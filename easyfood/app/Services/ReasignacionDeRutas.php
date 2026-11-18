<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Incidencia;
use App\Models\Repartidor;
use App\Models\Ruta;
use Illuminate\Support\Facades\DB;

/**
 * Pasar la ruta de una incidencia a otro repartidor. Un repartidor es candidato si está activo y disponible, no es el que ya
 * lleva la ruta y todavía le cabe algo ese día en su auto; «alcanza» cuando le cabe toda la ruta (entregas y platillos).
 * La capacidad libre es la del auto (configuración) menos lo que ya lleva ese día en otras rutas.
 */
class ReasignacionDeRutas
{
    public function __construct(private readonly CalendarioDeServicio $calendario) {}

    /** @return array<int, array{repartidor: Repartidor, capacidad_libre: int, platillos_libres: int, alcanza: bool}> los que alcanzan primero, luego por capacidad */
    public function candidatos(Incidencia $incidencia): array
    {
        $ruta = $this->rutaDe($incidencia);
        $auto = $this->calendario->capacidadDelAuto();

        $ocupado = Ruta::query()->whereDate('fecha', $ruta->fecha->toDateString())->where('id', '<>', $ruta->id)
            ->groupBy('repartidor_id')->selectRaw('repartidor_id, sum(entregas_asignadas) as entregas, sum(platillos_asignados) as platillos')->get()->keyBy('repartidor_id');

        $candidatos = [];
        foreach (Repartidor::query()->activos()->with('usuario')->where('estado', '<>', 'no_disponible')->where('id', '<>', $ruta->repartidor_id)->orderBy('id')->get() as $repartidor) {
            $libres = max(0, $auto['entregas'] - (int) ($ocupado[$repartidor->id]->entregas ?? 0));
            $platillosLibres = max(0, $auto['platillos'] - (int) ($ocupado[$repartidor->id]->platillos ?? 0));
            if ($libres === 0) {
                continue;
            }
            $candidatos[] = [
                'repartidor' => $repartidor, 'capacidad_libre' => $libres, 'platillos_libres' => $platillosLibres,
                'alcanza' => $libres >= $ruta->entregas_asignadas && $platillosLibres >= $ruta->platillos_asignados,
            ];
        }

        usort($candidatos, fn ($a, $b) => [$b['alcanza'], $b['capacidad_libre'], $a['repartidor']->id] <=> [$a['alcanza'], $a['capacidad_libre'], $b['repartidor']->id]);

        return $candidatos;
    }

    public function reasignar(Incidencia $incidencia, int $repartidorId, ?string $nota, ?\App\Models\User $usuario): Incidencia
    {
        return DB::transaction(function () use ($incidencia, $repartidorId, $nota, $usuario) {
            // Se bloquea la ruta para que dos personas no se la quiten a la vez.
            $incidencia = Incidencia::query()->lockForUpdate()->findOrFail($incidencia->id);
            Ruta::query()->lockForUpdate()->find($incidencia->ruta_id);

            $elegido = collect($this->candidatos($incidencia))->first(fn ($c) => $c['repartidor']->id === $repartidorId);
            if (! $elegido) {
                throw new ReglaDeNegocioException('repartidor_no_disponible', 'Ese repartidor no está disponible o ya no tiene capacidad libre ese día.');
            }
            $ruta = $this->rutaDe($incidencia);
            if (! $elegido['alcanza']) {
                $nombre = $elegido['repartidor']->usuario?->name ?? 'Ese repartidor';
                throw new ReglaDeNegocioException('capacidad_insuficiente', "A {$nombre} solo le caben {$elegido['capacidad_libre']} entregas y la ruta tiene {$ruta->entregas_asignadas}.");
            }

            $anterior = $ruta->repartidor?->usuario?->name ?? "repartidor {$ruta->repartidor_id}";
            $ruta->update(['repartidor_id' => $repartidorId]);
            $incidencia->update(['estado' => 'reasignada', 'repartidor_reasignado_id' => $repartidorId, 'nota' => $nota ?: $incidencia->nota]);

            $nuevo = $elegido['repartidor']->usuario?->name ?? "repartidor {$repartidorId}";
            Bitacora::registrar($usuario, 'ruta.reasignada', 'rutas', $ruta->id, "Reasignó la ruta {$ruta->id} de {$anterior} a {$nuevo} por la incidencia «{$incidencia->tipo}».");

            return $incidencia->refresh();
        });
    }

    /** La ruta de la incidencia; solo se reasigna mientras la incidencia siga abierta. */
    private function rutaDe(Incidencia $incidencia): Ruta
    {
        if ($incidencia->estado !== 'abierta') {
            throw new ReglaDeNegocioException('incidencia_cerrada', 'Esa incidencia ya está atendida: no se puede reasignar.');
        }
        if (! $incidencia->ruta_id) {
            throw new ReglaDeNegocioException('incidencia_sin_ruta', 'Esta incidencia no está ligada a una ruta, no hay nada que reasignar.');
        }

        return Ruta::query()->with('repartidor.usuario')->findOrFail($incidencia->ruta_id);
    }
}
