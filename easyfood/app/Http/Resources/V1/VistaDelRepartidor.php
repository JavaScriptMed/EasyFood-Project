<?php

namespace App\Http\Resources\V1;

use App\Models\EntregaEmpresa;
use App\Models\Parada;
use App\Models\PedidoDia;
use App\Models\Ruta;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cómo ve el repartidor sus rutas: cada parada trae todo lo que necesita en la calle (dirección, punto en el mapa, hora estimada,
 * a quién se entrega, qué platillos lleva y el teléfono para llamar). Se arma con pocas consultas aunque haya muchas paradas.
 */
final class VistaDelRepartidor
{
    private const TIEMPOS = ['desayuno' => 0, 'comida' => 1, 'cena' => 2];

    /**
     * @param  Collection<int, Ruta>  $rutas  con sus paradas ya cargadas
     * @return array<int, array<string, mixed>>
     */
    public static function rutas(Collection $rutas): array
    {
        $paradas = $rutas->flatMap(fn (Ruta $r) => $r->paradas);
        $puntos = self::puntos($paradas);
        $clientes = PedidoDia::query()->with(['pedido.cliente.profile', 'pedido.direccion', 'platillos.menu'])->whereIn('id', $paradas->pluck('pedido_dia_id')->filter())->get()->keyBy('id');
        $entregas = EntregaEmpresa::query()->with('empresa.usuario.profile')->whereIn('id', $paradas->pluck('entrega_empresa_id')->filter())->get()->keyBy('id');
        $platillosDeEmpresas = self::platillosDeEmpresas($entregas);

        return $rutas->map(function (Ruta $ruta) use ($puntos, $clientes, $entregas, $platillosDeEmpresas) {
            $lista = $ruta->paradas->sortBy('orden')->values();
            $items = $lista->map(fn (Parada $p) => $p->pedido_dia_id
                ? self::paradaDeCliente($p, $clientes->get($p->pedido_dia_id), $puntos->get($p->id))
                : self::paradaDeEmpresa($p, $entregas->get($p->entrega_empresa_id), $puntos->get($p->id), $platillosDeEmpresas));
            $siguiente = $lista->first(fn (Parada $p) => in_array($p->estado, ['pendiente', 'en_camino'], true));

            return [
                'id' => $ruta->id, 'fecha' => $ruta->fecha->toDateString(), 'estado' => $ruta->estado, 'zona' => $ruta->zona,
                'tipo' => $items->every(fn ($i) => $i['tipo'] === 'cliente') ? 'clientes' : ($items->every(fn ($i) => $i['tipo'] === 'empresa') ? 'empresas' : 'mixta'),
                'ventana_inicio' => Vistas::hora($ruta->ventana_inicio), 'ventana_fin' => Vistas::hora($ruta->ventana_fin),
                'entregas_asignadas' => $ruta->entregas_asignadas, 'platillos_asignados' => $ruta->platillos_asignados,
                'iniciada_en' => Vistas::iso($ruta->iniciada_en), 'completada_en' => Vistas::iso($ruta->completada_en),
                'paradas_totales' => $lista->count(), 'paradas_cerradas' => $lista->whereIn('estado', ['entregado', 'no_localizado'])->count(),
                'siguiente_parada_id' => $siguiente?->id,
                'paradas' => $items->all(),
            ];
        })->values()->all();
    }

    /**
     * Lo que responden las acciones sobre una parada: la parada ya actualizada y el estado de su ruta (para refrescar la pantalla).
     *
     * @return array{parada: array<string, mixed>, ruta: array<string, mixed>}
     */
    public static function resultado(Parada $parada): array
    {
        $ruta = Ruta::query()->with('paradas')->findOrFail($parada->ruta_id);
        $vista = self::rutas(collect([$ruta]))[0];
        $item = collect($vista['paradas'])->firstWhere('id', $parada->id);
        unset($vista['paradas']);

        return ['parada' => $item, 'ruta' => $vista];
    }

    // ------------------------------------------------------------------ una parada

    /** @return array<string, mixed> */
    private static function paradaDeCliente(Parada $p, ?PedidoDia $entrega, ?object $punto): array
    {
        $pedido = $entrega?->pedido;
        $platillos = ($entrega?->platillos ?? collect())
            ->sortBy(fn ($c) => [$c->fecha->toDateString(), (int) $c->es_extra, self::TIEMPOS[$c->tiempo_comida] ?? 9])
            ->map(fn ($c) => [
                'nombre' => $c->menu?->name, 'tiempo_comida' => $c->tiempo_comida, 'cantidad' => 1,
                'dia_de_comida' => $c->fecha->toDateString(), 'es_extra' => (bool) $c->es_extra,
            ])->values()->all();

        return self::base($p, 'cliente', $punto, $platillos) + [
            'referencias' => $pedido?->direccion?->delivery_instructions,
            'contacto_nombre' => $pedido?->cliente?->name,
            'telefono' => $pedido?->cliente?->profile?->phone,
            'folio' => $pedido?->folio,
        ];
    }

    /**
     * @param  Collection<string, array<int, array<string, mixed>>>  $platillosDeEmpresas
     * @return array<string, mixed>
     */
    private static function paradaDeEmpresa(Parada $p, ?EntregaEmpresa $entrega, ?object $punto, Collection $platillosDeEmpresas): array
    {
        $platillos = $platillosDeEmpresas->get($entrega ? "{$entrega->empresa_id}|{$entrega->fecha->toDateString()}" : '', []);

        return self::base($p, 'empresa', $punto, $platillos) + [
            'referencias' => null,
            'contacto_nombre' => $entrega?->empresa?->usuario?->name, // RR. HH.: por quién preguntar en la entrada
            'telefono' => $entrega?->empresa?->usuario?->profile?->phone,
            'folio' => null,
        ];
    }

    /** @param  array<int, array<string, mixed>>  $platillos */
    private static function base(Parada $p, string $tipo, ?object $punto, array $platillos): array
    {
        return [
            'id' => $p->id, 'orden' => $p->orden, 'estado' => $p->estado, 'tipo' => $tipo,
            'destinatario' => $p->nombre_destinatario, 'direccion' => $p->direccion,
            'ubicacion' => $punto ? ['latitud' => round((float) $punto->lat, 6), 'longitud' => round((float) $punto->lon, 6)] : null,
            'hora_estimada' => Vistas::hora($p->hora_estimada),
            'platillos' => $platillos, 'total_platillos' => array_sum(array_column($platillos, 'cantidad')),
            'iniciada_en' => Vistas::iso($p->iniciada_en), 'cerrada_en' => Vistas::iso($p->cerrada_en),
            'evidencia_foto_url' => $p->evidencia_foto_url, 'motivo_no_entrega' => $p->motivo_no_entrega,
        ];
    }

    // ------------------------------------------------------------------ consultas en lote

    /** El punto en el mapa de cada parada (PostGIS). Las empresas no tienen punto guardado: salen sin él. */
    private static function puntos(Collection $paradas): Collection
    {
        if ($paradas->isEmpty()) {
            return collect();
        }

        return DB::table('paradas')->whereIn('id', $paradas->pluck('id'))->whereNotNull('ubicacion')
            ->selectRaw('id, ST_X(ubicacion::geometry) as lon, ST_Y(ubicacion::geometry) as lat')->get()->keyBy('id');
    }

    /**
     * Lo que eligieron los empleados de cada empresa para su entrega, por platillo y tiempo de comida.
     *
     * @param  Collection<int, EntregaEmpresa>  $entregas
     * @return Collection<string, array<int, array<string, mixed>>> por «empresa|fecha»
     */
    private static function platillosDeEmpresas(Collection $entregas): Collection
    {
        if ($entregas->isEmpty()) {
            return collect();
        }

        return DB::table('elecciones_empleado as e')->join('empleados as emp', 'emp.id', '=', 'e.empleado_id')->join('menus as m', 'm.id', '=', 'e.menu_id')
            ->whereIn('emp.empresa_id', $entregas->pluck('empresa_id')->unique())->whereIn('e.fecha', $entregas->map(fn ($e) => $e->fecha->toDateString())->unique())
            ->groupBy('emp.empresa_id', 'e.fecha', 'm.id', 'm.name', 'e.tiempo_comida')
            ->selectRaw('emp.empresa_id, e.fecha, m.name, e.tiempo_comida, count(*) as cantidad')->get()
            ->groupBy(fn ($f) => "{$f->empresa_id}|".substr((string) $f->fecha, 0, 10))
            ->map(fn ($filas) => $filas->sortBy(fn ($f) => [self::TIEMPOS[$f->tiempo_comida] ?? 9, $f->name])->map(fn ($f) => [
                'nombre' => $f->name, 'tiempo_comida' => $f->tiempo_comida, 'cantidad' => (int) $f->cantidad,
                'dia_de_comida' => substr((string) $f->fecha, 0, 10), 'es_extra' => false,
            ])->values()->all());
    }
}
