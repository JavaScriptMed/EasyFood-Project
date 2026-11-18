<?php

namespace App\Services;

use App\Models\Corte;
use App\Models\EntregaEmpresa;
use App\Models\Incidencia;
use App\Models\Parada;
use App\Models\PedidoDia;
use App\Models\Repartidor;
use App\Models\Ruta;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Arma las rutas de una fecha con lo que todavía no tiene ruta: primero las entregas a empresas (por ventana) y después los
 * pedidos de clientes individuales, agrupando por zona y llenando un auto a la vez sin pasar de su capacidad.
 *
 * Lo usa el corte y también el administrador (`POST /admin/rutas/generar`) para completar las rutas cuando entra un repartidor
 * nuevo: nunca toca una ruta que ya existe (borrador o aprobada), solo crea rutas para lo que quedó sin ruta, y no duplica
 * las incidencias abiertas.
 */
class GeneradorDeRutas
{
    /** Una ruta que solo lleva entregas a clientes individuales arranca a esta hora… */
    private const INICIO_SIN_EMPRESAS = '12:00:00';

    /** …y cada entrega a un cliente individual se estima esta cantidad de minutos después de la anterior (tras las de empresas). */
    private const MINUTOS_ENTRE_ENTREGAS_INDIVIDUALES = 10;

    public function __construct(private readonly CalendarioDeServicio $calendario) {}

    /**
     * Agrupa por zona y llena un auto a la vez sin pasar de su capacidad (entregas y platillos). En cada zona van primero
     * las entregas a empresas (por ventana) y después las de clientes individuales (cada una cuenta como 1 entrega y 1 platillo).
     * Lo que no cabe, o una dirección sin zona, queda sin ruta y genera una incidencia abierta.
     *
     * @return array{0:int,1:int} rutas creadas y entregas sin ruta
     */
    public function generar(Corte $corte, string $fecha): array
    {
        $capacidad = $this->calendario->capacidadDelAuto();

        $empresas = EntregaEmpresa::query()->with('empresa')->whereDate('fecha', $fecha)->whereNull('ruta_id')->where('estado', 'programada')
            ->orderBy('ventana_inicio')->orderBy('id')->get()
            ->map(fn (EntregaEmpresa $e) => ['tipo' => 'empresa', 'zona' => $e->empresa->zona, 'peso' => (int) $e->platillos, 'modelo' => $e]);

        $individuales = PedidoDia::query()->with(['pedido.cliente', 'pedido.direccion'])
            ->whereDate('fecha', $fecha)->where('estado', 'programada')->doesntHave('parada')
            ->whereHas('pedido', fn ($q) => $q->whereIn('estado', ['recibido', 'activo']))
            ->orderBy('id')->get()
            ->map(fn (PedidoDia $d) => ['tipo' => 'cliente', 'zona' => $d->pedido->direccion->zona, 'peso' => 1, 'modelo' => $d]);

        $porZona = $empresas->concat($individuales)->groupBy(fn ($item) => $item['zona'] === null ? '' : $this->zona($item['zona']));

        $ocupados = Ruta::query()->whereDate('fecha', $fecha)->pluck('repartidor_id')->all();
        $repartidores = Repartidor::query()->activos()->where('estado', '<>', 'no_disponible')->whereNotIn('id', $ocupados)
            ->orderBy('id')->get()->groupBy(fn ($r) => $this->zona($r->zona_base));

        $rutas = 0;
        $sinRuta = [];
        foreach ($porZona as $zona => $items) {
            $resto = $items->all();
            if ($zona !== '') {
                foreach ($repartidores->get($zona, collect()) as $repartidor) {
                    if (! $resto) {
                        break;
                    }
                    [$asignados, $resto] = $this->llenarAuto($resto, $capacidad);
                    if (! $asignados) {
                        break; // Ninguno cabe solo en un auto: otro auto igual tampoco serviría.
                    }
                    $this->guardarRuta($corte, $fecha, $repartidor, $asignados, $capacidad);
                    $rutas++;
                }
            }
            array_push($sinRuta, ...$resto);
        }

        foreach ($sinRuta as $item) {
            $this->abrirIncidenciaDeFaltaDeRuta($fecha, $item);
        }
        $this->cerrarIncidenciasDeLoQueYaTieneRuta($fecha);

        return [$rutas, count($sinRuta)];
    }

    /** @return array{0: array<int, array>, 1: array<int, array>} los que caben en un auto y los que sobran */
    private function llenarAuto(array $items, array $capacidad): array
    {
        $asignados = [];
        $resto = [];
        $platillos = 0;
        foreach ($items as $item) {
            if (count($asignados) < $capacidad['entregas'] && $platillos + $item['peso'] <= $capacidad['platillos']) {
                $asignados[] = $item;
                $platillos += $item['peso'];
            } else {
                $resto[] = $item;
            }
        }

        return [$asignados, $resto];
    }

    /** @param array<int, array> $items entregas a empresas primero y luego clientes individuales */
    private function guardarRuta(Corte $corte, string $fecha, Repartidor $repartidor, array $items, array $capacidad): void
    {
        $items = collect($items);
        $empresas = $items->where('tipo', 'empresa');
        $clientes = $items->where('tipo', 'cliente')->values();

        $inicio = $empresas->map(fn ($i) => $i['modelo']->ventana_inicio)->min() ?? self::INICIO_SIN_EMPRESAS;
        $finEmpresas = $empresas->map(fn ($i) => $i['modelo']->ventana_fin)->max() ?? $inicio;
        $horaDeCliente = fn (int $n) => CarbonImmutable::createFromTimeString($finEmpresas)->addMinutes($n * self::MINUTOS_ENTRE_ENTREGAS_INDIVIDUALES)->format('H:i:s');
        $fin = $clientes->isEmpty() ? $finEmpresas : $horaDeCliente($clientes->count());

        $ruta = Ruta::create([
            'fecha' => $fecha, 'repartidor_id' => $repartidor->id, 'corte_id' => $corte->id, 'zona' => $items->first()['zona'],
            'ventana_inicio' => $inicio, 'ventana_fin' => $fin,
            'capacidad_entregas' => $capacidad['entregas'], 'capacidad_platillos' => $capacidad['platillos'],
            'entregas_asignadas' => $items->count(), 'platillos_asignados' => $items->sum('peso'),
            'hora_fin_estimada' => $fin, 'excede_bloque' => false, 'estado' => 'borrador',
        ]);

        $orden = 0;
        foreach ($empresas as $item) {
            /** @var EntregaEmpresa $entrega */
            $entrega = $item['modelo'];
            $entrega->update(['ruta_id' => $ruta->id]);
            Parada::create([
                'ruta_id' => $ruta->id, 'orden' => ++$orden, 'entrega_empresa_id' => $entrega->id, 'nombre_destinatario' => $entrega->empresa->nombre,
                'direccion' => $entrega->empresa->direccion_entrega, 'hora_estimada' => $entrega->ventana_inicio, 'estado' => 'pendiente',
            ]);
        }
        foreach ($clientes as $n => $item) {
            /** @var PedidoDia $dia */
            $dia = $item['modelo'];
            $direccion = $dia->pedido->direccion;
            $parada = Parada::create([
                'ruta_id' => $ruta->id, 'orden' => ++$orden, 'pedido_dia_id' => $dia->id, 'nombre_destinatario' => $dia->pedido->cliente->name,
                'direccion' => mb_substr(trim($direccion->street_address.($direccion->internal_number ? ' int. '.$direccion->internal_number : '')), 0, 255),
                'hora_estimada' => $horaDeCliente($n + 1), 'estado' => 'pendiente',
            ]);
            // El punto en el mapa de la parada es el de la dirección (PostGIS).
            DB::update('update paradas set ubicacion = (select ubicacion from addresses where id = ?) where id = ?', [$direccion->id, $parada->id]);
        }
    }

    private function abrirIncidenciaDeFaltaDeRuta(string $fecha, array $item): void
    {
        // Al volver a generar no se duplica el aviso de lo que ya quedó sin ruta antes.
        $yaAbierta = Incidencia::query()->whereDate('fecha', $fecha)->where('estado', 'abierta')
            ->when($item['tipo'] === 'empresa', fn ($q) => $q->where('entrega_empresa_id', $item['modelo']->id), fn ($q) => $q->where('pedido_dia_id', $item['modelo']->id))
            ->exists();
        if ($yaAbierta) {
            return;
        }

        if ($item['tipo'] === 'empresa') {
            /** @var EntregaEmpresa $entrega */
            $entrega = $item['modelo'];
            Incidencia::create([
                'fecha' => $fecha, 'tipo' => 'falta_repartidores', 'prioridad' => 'inmediata', 'estado' => 'abierta', 'entrega_empresa_id' => $entrega->id,
                'descripcion' => "La entrega de {$entrega->empresa->nombre} ({$entrega->platillos} platillos, zona {$entrega->empresa->zona}) quedó sin ruta: no hay repartidor con capacidad en la zona.",
            ]);

            return;
        }

        /** @var PedidoDia $dia */
        $dia = $item['modelo'];
        $sinZona = $item['zona'] === null;
        Incidencia::create([
            'fecha' => $fecha, 'tipo' => $sinZona ? 'otro' : 'falta_repartidores', 'prioridad' => 'inmediata', 'estado' => 'abierta', 'pedido_dia_id' => $dia->id,
            'descripcion' => $sinZona
                ? "La entrega del pedido {$dia->pedido->folio} no tiene zona: su dirección quedó fuera de cobertura después de pedir."
                : "La entrega del pedido {$dia->pedido->folio} (zona {$item['zona']}) quedó sin ruta: no hay repartidor con capacidad en la zona.",
        ]);
    }

    /** Las incidencias «falta de repartidores» de entregas que ahora sí tienen ruta se cierran solas, con una nota que lo explica. */
    private function cerrarIncidenciasDeLoQueYaTieneRuta(string $fecha): void
    {
        $incidencias = Incidencia::query()->whereDate('fecha', $fecha)->where('estado', 'abierta')->where('tipo', 'falta_repartidores')
            ->where(fn ($q) => $q->whereHas('entregaEmpresa', fn ($e) => $e->whereNotNull('ruta_id'))->orWhereHas('pedidoDia', fn ($d) => $d->has('parada')))
            ->get();

        foreach ($incidencias as $incidencia) {
            $incidencia->update(['estado' => 'resuelta', 'resuelta_en' => now(), 'nota' => 'Resuelta al generar las rutas: la entrega ya tiene repartidor.']);
        }
    }

    private function zona(string $zona): string
    {
        return mb_strtolower(trim($zona));
    }
}
