<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\EntregaEmpresa;
use App\Models\Parada;
use App\Models\Ruta;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pasa una entrega (parada) de una ruta a otra del mismo día para balancear la carga entre repartidores.
 *
 * Solo se mueve una entrega pendiente entre rutas que todavía no salen (borrador o aprobada), del mismo día, de la misma zona y del mismo
 * tipo (las de empresas y las de clientes individuales van en rutas separadas). La ruta destino no puede pasar de la capacidad del auto
 * (entregas y platillos), la de origen no puede quedar vacía y la ruta destino no puede empalmarse con otra del mismo repartidor.
 * Después se reordenan las paradas de las dos rutas y se recalculan sus horarios y totales con las mismas reglas del generador de rutas.
 */
class MovimientoDeParadas
{
    public function __construct(private readonly GeneradorDeRutas $generador) {}

    /** @return array{0: Ruta, 1: Ruta, 2: string} la ruta de origen, la de destino (ya actualizadas) y el nombre del destinatario */
    public function mover(User $actor, int $paradaId, int $rutaDestinoId): array
    {
        return DB::transaction(function () use ($actor, $paradaId, $rutaDestinoId) {
            $parada = Parada::query()->findOrFail($paradaId);
            Ruta::query()->findOrFail($rutaDestinoId);
            // Se bloquean las dos rutas siempre en el mismo orden (por id) para que dos movimientos a la vez no se traben entre sí.
            $rutas = Ruta::query()->whereIn('id', [$parada->ruta_id, $rutaDestinoId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $origen = $rutas[$parada->ruta_id];
            $destino = $rutas[$rutaDestinoId];
            $parada->refresh();
            if ($parada->ruta_id !== $origen->id) {
                throw new ReglaDeNegocioException('parada_ya_movida', 'Esa entrega ya cambió de ruta: actualiza la pantalla.');
            }

            $this->validar($parada, $origen, $destino);

            $esEmpresa = $parada->entrega_empresa_id !== null;
            $parada->update(['ruta_id' => $destino->id, 'orden' => 10000 + $parada->orden]); // fuera del camino mientras se reordena
            if ($esEmpresa) {
                EntregaEmpresa::query()->whereKey($parada->entrega_empresa_id)->update(['ruta_id' => $destino->id]);
            }
            $this->recalcular($destino);
            $this->recalcular($origen);

            Bitacora::registrar(
                $actor, 'rutas.parada_movida', 'paradas', $parada->id,
                "Movió la entrega de {$parada->nombre_destinatario} de la ruta #{$origen->id} a la ruta #{$destino->id} del {$destino->fecha->toDateString()}."
            );

            return [$origen->fresh(['repartidor.usuario', 'paradas']), $destino->fresh(['repartidor.usuario', 'paradas']), $parada->nombre_destinatario];
        });
    }

    private function validar(Parada $parada, Ruta $origen, Ruta $destino): void
    {
        if ($origen->id === $destino->id) {
            throw new ReglaDeNegocioException('misma_ruta', 'Esa entrega ya está en esa ruta: elige otra.');
        }
        if ($parada->estado !== 'pendiente') {
            throw new ReglaDeNegocioException('parada_cerrada', 'Solo se pueden mover entregas pendientes: esta ya está en camino o se cerró.');
        }
        foreach ([[$origen, 'de origen'], [$destino, 'de destino']] as [$ruta, $cual]) {
            if (! in_array($ruta->estado, ['borrador', 'aprobada'], true)) {
                throw new ReglaDeNegocioException('ruta_ya_iniciada', "La ruta {$cual} (#{$ruta->id}) ya salió a repartir: no se le pueden quitar ni agregar entregas.");
            }
        }
        if ($origen->fecha->toDateString() !== $destino->fecha->toDateString()) {
            throw new ReglaDeNegocioException('otra_fecha', 'Las dos rutas deben ser del mismo día.');
        }
        if (mb_strtolower(trim($origen->zona)) !== mb_strtolower(trim($destino->zona))) {
            throw new ReglaDeNegocioException('otra_zona', "La entrega es de la zona {$origen->zona} y la ruta destino es de la zona {$destino->zona}: elige una ruta de la misma zona.");
        }

        $esEmpresa = $parada->entrega_empresa_id !== null;
        $hayDelOtroTipo = Parada::query()->where('ruta_id', $destino->id)->whereNotNull($esEmpresa ? 'pedido_dia_id' : 'entrega_empresa_id')->exists();
        if ($hayDelOtroTipo) {
            throw new ReglaDeNegocioException('otro_tipo_de_ruta', 'Las entregas a empresas y las de clientes individuales van en rutas separadas: elige una ruta del mismo tipo.');
        }
        if (Parada::query()->where('ruta_id', $origen->id)->count() <= 1) {
            throw new ReglaDeNegocioException('ruta_quedaria_vacia', 'Esa es la única entrega de su ruta: si la mueves, la ruta se queda sin nada. Reasigna la ruta completa desde sus incidencias.');
        }

        $peso = $this->peso($parada);
        $nombre = $destino->repartidor?->usuario?->name ?? "la ruta #{$destino->id}";
        if ($destino->entregas_asignadas + 1 > $destino->capacidad_entregas) {
            throw new ReglaDeNegocioException('capacidad_insuficiente', "La ruta de {$nombre} ya lleva {$destino->entregas_asignadas} de {$destino->capacidad_entregas} entregas permitidas para su vehículo.");
        }
        if ($destino->platillos_asignados + $peso > $destino->capacidad_platillos) {
            throw new ReglaDeNegocioException('capacidad_insuficiente', "La ruta de {$nombre} ya lleva {$destino->platillos_asignados} de {$destino->capacidad_platillos} platillos permitidos y esta entrega suma {$peso}.");
        }
    }

    /** Cuántos platillos lleva una parada (el total de la empresa ese día, o los del pedido del cliente). */
    private function peso(Parada $parada): int
    {
        if ($parada->entrega_empresa_id !== null) {
            return (int) EntregaEmpresa::query()->whereKey($parada->entrega_empresa_id)->value('platillos');
        }

        return (int) DB::table('pedido_dia_platillos')->where('pedido_dia_id', $parada->pedido_dia_id)->count();
    }

    /** Reordena las paradas de la ruta (empresas por su ventana; clientes en el orden de su pedido), y recalcula horarios y totales. */
    private function recalcular(Ruta $ruta): void
    {
        $paradas = Parada::query()->where('ruta_id', $ruta->id)
            ->with(['entregaEmpresa', 'pedidoDia' => fn ($q) => $q->withCount('platillos')])->get();
        $items = $paradas
            ->map(fn (Parada $p) => $p->entrega_empresa_id !== null
                ? ['tipo' => 'empresa', 'peso' => (int) $p->entregaEmpresa->platillos, 'modelo' => $p->entregaEmpresa, 'parada' => $p, 'clave' => [$p->entregaEmpresa->ventana_inicio, $p->entregaEmpresa->id]]
                : ['tipo' => 'cliente', 'peso' => (int) $p->pedidoDia->platillos_count, 'modelo' => $p->pedidoDia, 'parada' => $p, 'clave' => ['', $p->pedidoDia->id]])
            ->sortBy('clave')->values();

        $noAntesDe = $ruta->repartidor ? $this->generador->finDeSuRutaDeEmpresas($ruta->repartidor, $ruta->fecha->toDateString()) : null;
        [$inicio, $fin, $horaDeCliente] = $this->generador->ventana($items, $noAntesDe);
        $this->verificarEmpalme($ruta, $inicio, $fin);

        // Las paradas pasan primero a un orden provisional para no chocar con el índice único (ruta, orden). Se actualiza por consulta (no por modelo):
        // Eloquent se saltaría la columna si el valor nuevo coincide con el que tiene en memoria.
        Parada::query()->where('ruta_id', $ruta->id)->update(['orden' => DB::raw('orden + 20000')]);
        $clientes = 0;
        foreach ($items as $i => $item) {
            Parada::query()->whereKey($item['parada']->id)->update([
                'orden' => $i + 1,
                'hora_estimada' => $item['tipo'] === 'empresa' ? $item['modelo']->ventana_inicio : $horaDeCliente(++$clientes),
            ]);
        }
        $ruta->update(['ventana_inicio' => $inicio, 'ventana_fin' => $fin, 'hora_fin_estimada' => $fin, 'entregas_asignadas' => $items->count(), 'platillos_asignados' => $items->sum('peso')]);
    }

    private function verificarEmpalme(Ruta $ruta, string $inicio, string $fin): void
    {
        $empalma = Ruta::query()->whereDate('fecha', $ruta->fecha->toDateString())->where('repartidor_id', $ruta->repartidor_id)->where('id', '<>', $ruta->id)
            ->where('ventana_inicio', '<', $fin)->where('ventana_fin', '>', $inicio)->exists();
        if ($empalma) {
            throw new ReglaDeNegocioException('ruta_se_empalma', 'Con ese cambio la ruta se empalmaría con otra del mismo repartidor ese día: elige otra ruta.');
        }
    }
}
