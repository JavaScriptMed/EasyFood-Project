<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\EntregaEmpresa;
use App\Models\Incidencia;
use App\Models\Parada;
use App\Models\Pedido;
use App\Models\PedidoDia;
use App\Models\Repartidor;
use App\Models\Ruta;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Lo que hace el repartidor desde su sesión: ponerse en línea o fuera de línea y recorrer su ruta parada por parada.
 *
 * - Solo ve y toca sus propias rutas, y solo las aprobadas por el administrador (en un borrador no hay nada que hacer todavía).
 * - Una ruta solo se empieza el día que le toca (`fecha`); una ya empezada se puede seguir aunque pase la medianoche.
 * - Una parada se atiende en orden: no se inicia hasta que las anteriores quedaron entregadas o no entregadas. Iniciar la primera
 *   pasa la ruta a «en_curso» (y al repartidor a «en_ruta»); cerrar la última la pasa a «completada» (y al repartidor a «libre»).
 * - Una parada se cierra con la foto de evidencia («entregado») o con un motivo («no_localizado», que además abre una incidencia
 *   para que el administrador la atienda). Los estados de la entrega a la empresa o del pedido del cliente siguen a los de la parada.
 * - Sin chat ni GPS: eso no existe todavía.
 */
class EntregasDelRepartidor
{
    public const DISCO = 'public';

    public const CARPETA = 'evidencias';

    /** La foto de evidencia: imagen real (no SVG) de hasta 5 MB. */
    public const REGLAS_DE_FOTO = ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'];

    /** Estados de una ruta que el repartidor puede ver. */
    public const RUTAS_VISIBLES = ['aprobada', 'en_curso', 'completada'];

    public function __construct(private readonly CalendarioDeServicio $calendario) {}

    // ------------------------------------------------------------------ disponibilidad

    /** @return array{en_linea: bool, estado: string, ruta_en_curso_id: int|null} */
    public function disponibilidad(User $usuario): array
    {
        return $this->estadoDeDisponibilidad($this->repartidorDe($usuario));
    }

    /**
     * Ponerse en línea o fuera de línea. Fuera de línea no recibe rutas nuevas; no se puede con una ruta en curso.
     *
     * @return array{en_linea: bool, estado: string, ruta_en_curso_id: int|null}
     */
    public function cambiarDisponibilidad(User $usuario, bool $enLinea): array
    {
        return DB::transaction(function () use ($usuario, $enLinea) {
            $repartidor = Repartidor::query()->activos()->where('usuario_id', $usuario->id)->lockForUpdate()->first() ?? $this->sinFicha();

            if (! $enLinea) {
                if ($this->rutaEnCurso($repartidor)) {
                    throw new ReglaDeNegocioException('ruta_en_curso', 'Tienes una ruta en curso: termínala antes de ponerte fuera de línea.');
                }
                if ($repartidor->estado !== 'no_disponible') {
                    $repartidor->update(['estado' => 'no_disponible']);
                    Bitacora::registrar($usuario, 'repartidor.disponibilidad', 'repartidores', $repartidor->id, "{$usuario->name} se puso fuera de línea.");
                }
            } elseif ($repartidor->estado === 'no_disponible') {
                $repartidor->update(['estado' => 'libre']);
                Bitacora::registrar($usuario, 'repartidor.disponibilidad', 'repartidores', $repartidor->id, "{$usuario->name} se puso en línea.");
            }

            return $this->estadoDeDisponibilidad($repartidor->refresh());
        });
    }

    // ------------------------------------------------------------------ rutas del día

    /** Las rutas aprobadas (o ya en curso o completadas) del repartidor ese día, con sus paradas. */
    public function rutasDelDia(User $usuario, string $fecha): \Illuminate\Support\Collection
    {
        $repartidor = $this->repartidorDe($usuario);

        return Ruta::query()->with('paradas')->where('repartidor_id', $repartidor->id)->whereDate('fecha', $fecha)
            ->whereIn('estado', self::RUTAS_VISIBLES)->orderBy('ventana_inicio')->orderBy('id')->get();
    }

    // ------------------------------------------------------------------ iniciar la siguiente parada

    public function iniciar(User $usuario, int $paradaId, ?CarbonImmutable $ahora = null): Parada
    {
        $ahora = $this->calendario->ahora($ahora);

        return DB::transaction(function () use ($usuario, $paradaId, $ahora) {
            [$repartidor, $ruta, $parada] = $this->bloquear($usuario, $paradaId);

            if ($repartidor->estado === 'no_disponible') {
                throw new ReglaDeNegocioException('fuera_de_linea', 'Estás fuera de línea: ponte en línea para iniciar tu ruta.');
            }
            if ($ruta->estado === 'completada') {
                throw new ReglaDeNegocioException('ruta_completada', 'Esa ruta ya se completó.');
            }
            // Solo se empieza una ruta el día que le toca. Una que ya empezó se puede seguir aunque pase la medianoche.
            if ($ruta->estado === 'aprobada' && $ruta->fecha->toDateString() !== $ahora->toDateString()) {
                throw new ReglaDeNegocioException('ruta_de_otro_dia', "Esa ruta es del {$ruta->fecha->toDateString()}: solo puedes iniciar las rutas de hoy.");
            }
            if ($parada->estado === 'en_camino') {
                throw new ReglaDeNegocioException('parada_en_camino', 'Esa parada ya está en camino: confirma la entrega o márcala como no entregada.');
            }
            if ($parada->estado !== 'pendiente') {
                throw new ReglaDeNegocioException('parada_ya_atendida', 'Esa parada ya se atendió.');
            }

            $anterior = Parada::query()->where('ruta_id', $ruta->id)->where('orden', '<', $parada->orden)->whereIn('estado', ['pendiente', 'en_camino'])->orderBy('orden')->first();
            if ($anterior) {
                throw new ReglaDeNegocioException('orden_de_paradas', "Primero atiende la parada {$anterior->orden} ({$anterior->nombre_destinatario}): las paradas van en orden.");
            }

            if ($ruta->estado === 'aprobada') {
                if ($this->rutaEnCurso($repartidor)) {
                    throw new ReglaDeNegocioException('otra_ruta_en_curso', 'Ya tienes otra ruta en curso: termínala antes de empezar esta.');
                }
                $ruta->update(['estado' => 'en_curso', 'iniciada_en' => $ahora]);
                $repartidor->update(['estado' => 'en_ruta']);
                Bitacora::registrar($usuario, 'ruta.iniciada', 'rutas', $ruta->id, "{$usuario->name} inició la ruta {$ruta->id} del {$ruta->fecha->toDateString()}.");
            }

            $parada->update(['estado' => 'en_camino', 'iniciada_en' => $ahora]);
            $this->marcarLaEntrega($parada, 'en_ruta');

            return $parada->refresh();
        });
    }

    // ------------------------------------------------------------------ cerrar una parada

    /** Confirma la entrega con la foto de evidencia. */
    public function entregar(User $usuario, int $paradaId, UploadedFile $foto, ?CarbonImmutable $ahora = null): Parada
    {
        $ahora = $this->calendario->ahora($ahora);
        $guardada = null;

        try {
            return DB::transaction(function () use ($usuario, $paradaId, $foto, $ahora, &$guardada) {
                [$repartidor, $ruta, $parada] = $this->bloquear($usuario, $paradaId);
                $this->exigirEnCamino($parada);

                $guardada = $this->guardarFoto($parada, $foto);
                $url = Storage::disk(self::DISCO)->url($guardada);
                $parada->update(['estado' => 'entregado', 'cerrada_en' => $ahora, 'evidencia_foto_url' => $url]);

                if ($parada->entrega_empresa_id) {
                    EntregaEmpresa::query()->whereKey($parada->entrega_empresa_id)->first()?->update(['estado' => 'entregada']);
                } else {
                    $dia = PedidoDia::query()->findOrFail($parada->pedido_dia_id);
                    $dia->update(['estado' => 'entregada', 'entregada_en' => $ahora, 'evidencia_foto_url' => $url]);
                    $this->completarElPedidoSiYaNoFaltaNada($dia->pedido_id);
                }

                Bitacora::registrar($usuario, 'parada.entregada', 'paradas', $parada->id, "{$usuario->name} entregó la parada {$parada->orden} de la ruta {$ruta->id} ({$parada->nombre_destinatario}).");
                $this->cerrarLaRutaSiYaNoFaltaNada($ruta, $repartidor, $ahora);

                return $parada->refresh();
            });
        } catch (Throwable $error) {
            if ($guardada) {
                Storage::disk(self::DISCO)->delete($guardada); // no queda una foto suelta de una entrega que no se guardó
            }

            throw $error;
        }
    }

    /** Marca la parada como no entregada con su motivo y abre una incidencia para el administrador. */
    public function noEntregada(User $usuario, int $paradaId, string $motivo, ?CarbonImmutable $ahora = null): Parada
    {
        $ahora = $this->calendario->ahora($ahora);
        $motivo = trim($motivo);

        return DB::transaction(function () use ($usuario, $paradaId, $motivo, $ahora) {
            [$repartidor, $ruta, $parada] = $this->bloquear($usuario, $paradaId);
            $this->exigirEnCamino($parada);

            $parada->update(['estado' => 'no_localizado', 'cerrada_en' => $ahora, 'motivo_no_entrega' => $motivo]);
            $this->marcarLaEntrega($parada, 'no_localizado');

            $dia = $parada->pedido_dia_id ? PedidoDia::query()->with('pedido')->find($parada->pedido_dia_id) : null;
            Incidencia::create([
                'fecha' => $ruta->fecha->toDateString(), 'tipo' => 'entrega_no_localizada', 'prioridad' => 'inmediata', 'estado' => 'abierta', 'hora' => $ahora->format('H:i:s'),
                'ruta_id' => $ruta->id, 'entrega_empresa_id' => $parada->entrega_empresa_id, 'pedido_dia_id' => $parada->pedido_dia_id,
                'descripcion' => "{$usuario->name} no pudo entregar en la parada {$parada->orden} ({$parada->nombre_destinatario}".($dia ? ', pedido '.$dia->pedido->folio : '').'): '.$motivo,
            ]);

            Bitacora::registrar($usuario, 'parada.no_entregada', 'paradas', $parada->id, "{$usuario->name} marcó como no entregada la parada {$parada->orden} de la ruta {$ruta->id} ({$parada->nombre_destinatario}): {$motivo}");
            $this->cerrarLaRutaSiYaNoFaltaNada($ruta, $repartidor, $ahora);

            return $parada->refresh();
        });
    }

    // ------------------------------------------------------------------ apoyo

    public function repartidorDe(User $usuario): Repartidor
    {
        return Repartidor::query()->activos()->where('usuario_id', $usuario->id)->first() ?? $this->sinFicha();
    }

    private function sinFicha(): never
    {
        throw new HttpException(403, 'Tu cuenta no está dada de alta como repartidor activo.');
    }

    private function rutaEnCurso(Repartidor $repartidor): ?Ruta
    {
        return Ruta::query()->where('repartidor_id', $repartidor->id)->where('estado', 'en_curso')->first();
    }

    /** @return array{en_linea: bool, estado: string, ruta_en_curso_id: int|null} */
    private function estadoDeDisponibilidad(Repartidor $repartidor): array
    {
        return ['en_linea' => $repartidor->estado !== 'no_disponible', 'estado' => $repartidor->estado, 'ruta_en_curso_id' => $this->rutaEnCurso($repartidor)?->id];
    }

    /**
     * La parada de una ruta propia (aprobada, en curso o completada) con su ruta y su repartidor bloqueados: dos toques seguidos desde
     * el celular no se pisan. Una parada de otra persona (o de un borrador) responde 404, igual que una que no existe.
     *
     * @return array{0: Repartidor, 1: Ruta, 2: Parada}
     */
    private function bloquear(User $usuario, int $paradaId): array
    {
        $repartidor = Repartidor::query()->activos()->where('usuario_id', $usuario->id)->lockForUpdate()->first() ?? $this->sinFicha();
        $parada = Parada::query()->whereHas('ruta', fn ($q) => $q->where('repartidor_id', $repartidor->id)->whereIn('estado', self::RUTAS_VISIBLES))->findOrFail($paradaId);
        $ruta = Ruta::query()->lockForUpdate()->findOrFail($parada->ruta_id);
        $parada = Parada::query()->lockForUpdate()->findOrFail($parada->id);

        return [$repartidor, $ruta, $parada];
    }

    private function exigirEnCamino(Parada $parada): void
    {
        if ($parada->estado === 'pendiente') {
            throw new ReglaDeNegocioException('parada_no_iniciada', 'Primero inicia esta parada.');
        }
        if ($parada->estado !== 'en_camino') {
            throw new ReglaDeNegocioException('parada_ya_atendida', 'Esa parada ya se atendió.');
        }
    }

    /** El estado de la entrega a la empresa o del pedido del cliente sigue al de su parada. */
    private function marcarLaEntrega(Parada $parada, string $estado): void
    {
        if ($parada->entrega_empresa_id) {
            EntregaEmpresa::query()->whereKey($parada->entrega_empresa_id)->first()?->update(['estado' => $estado]);
        } else {
            PedidoDia::query()->whereKey($parada->pedido_dia_id)->first()?->update(['estado' => $estado]);
        }
    }

    /** Un pedido de cliente queda «completado» cuando ya no le falta ninguna entrega por hacer. */
    private function completarElPedidoSiYaNoFaltaNada(int $pedidoId): void
    {
        $pendientes = PedidoDia::query()->where('pedido_id', $pedidoId)->whereIn('estado', ['programada', 'en_ruta'])->exists();
        if (! $pendientes) {
            Pedido::query()->whereKey($pedidoId)->whereIn('estado', ['recibido', 'activo'])->first()?->update(['estado' => 'completado']);
        }
    }

    /** Cuando ya no queda ninguna parada por atender, la ruta se completa y el repartidor queda libre. */
    private function cerrarLaRutaSiYaNoFaltaNada(Ruta $ruta, Repartidor $repartidor, CarbonImmutable $ahora): void
    {
        if (Parada::query()->where('ruta_id', $ruta->id)->whereIn('estado', ['pendiente', 'en_camino'])->exists()) {
            return;
        }
        $ruta->update(['estado' => 'completada', 'completada_en' => $ahora]);
        $repartidor->update(['estado' => 'libre']);
        Bitacora::registrar($repartidor->usuario_id, 'ruta.completada', 'rutas', $ruta->id, "Se completó la ruta {$ruta->id} del {$ruta->fecha->toDateString()}.");
    }

    /** Guarda la foto con un nombre aleatorio (nunca el que manda el celular). Devuelve su ruta dentro del disco. */
    private function guardarFoto(Parada $parada, UploadedFile $foto): string
    {
        $extension = match ($foto->getMimeType()) {
            'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg',
        };

        return $foto->storeAs(self::CARPETA, "{$parada->id}-".Str::random(24).".{$extension}", self::DISCO);
    }
}
