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
 * Arma las rutas de una fecha con lo que todavía no tiene ruta, agrupando por zona y llenando un auto a la vez sin pasar de su
 * capacidad. Las entregas a empresas (por ventana, el corte general) y las de clientes individuales (el sábado, su propio corte)
 * salen en rutas SEPARADAS: cada tipo se arma en su propia pasada y un repartidor puede hacer una ruta de cada tipo el mismo día.
 *
 * Lo usa el corte y también el administrador (`POST /admin/rutas/generar`) para completar las rutas cuando entra un repartidor
 * nuevo: nunca toca una ruta que ya existe (borrador o aprobada), solo crea rutas para lo que quedó sin ruta, y no duplica
 * las incidencias abiertas.
 */
class GeneradorDeRutas
{
    /** …y cada entrega a un cliente individual se estima esta cantidad de minutos después de la anterior (tras las de empresas). */
    private const MINUTOS_ENTRE_ENTREGAS_INDIVIDUALES = 10;

    /** Una ruta de clientes individuales sale el sábado a esta hora; si su repartidor ya hizo una ruta de empresas ese día, sale cuando la termina. */
    public const INICIO_SIN_EMPRESAS = '12:00:00';

    public function __construct(private readonly CalendarioDeServicio $calendario) {}

    /**
     * Agrupa por zona y llena un auto a la vez sin pasar de su capacidad (entregas y platillos). Con `$empresas` arma las rutas de
     * las entregas a empresas (por ventana); con `$individuales`, las de los clientes individuales (cada pedido es 1 entrega y
     * 1 parada con todos sus platillos). Lo que no cabe, o una dirección sin zona, queda sin ruta y genera una incidencia abierta.
     *
     * @return array{0:int,1:int} rutas creadas y entregas sin ruta
     */
    public function generar(Corte $corte, string $fecha, bool $empresas = true, bool $individuales = false): array
    {
        $capacidad = $this->calendario->capacidadDelAuto();

        $deEmpresas = ! $empresas ? collect() : EntregaEmpresa::query()->with('empresa')->whereDate('fecha', $fecha)->whereNull('ruta_id')->where('estado', 'programada')
            ->orderBy('ventana_inicio')->orderBy('id')->get()
            ->map(fn (EntregaEmpresa $e) => ['tipo' => 'empresa', 'zona' => $e->empresa->zona, 'peso' => (int) $e->platillos, 'modelo' => $e]);

        $deClientes = ! $individuales ? collect() : PedidoDia::query()->with(['pedido.cliente', 'pedido.direccion'])->withCount('platillos')
            ->whereDate('fecha', $fecha)->where('estado', 'programada')->doesntHave('parada')
            ->whereHas('pedido', fn ($q) => $q->whereIn('estado', ['recibido', 'activo']))
            ->orderBy('id')->get()
            ->map(fn (PedidoDia $d) => ['tipo' => 'cliente', 'zona' => $d->pedido->direccion->zona, 'peso' => (int) $d->platillos_count, 'modelo' => $d]);

        $porZona = $deEmpresas->concat($deClientes)->groupBy(fn ($item) => $item['zona'] === null ? '' : $this->zona($item['zona']));

        // Un repartidor ya ocupado con una ruta del MISMO tipo ese día no se repite; con una del otro tipo sí (las empresas se
        // atienden por la mañana y los clientes individuales después, el mismo sábado).
        $ocupados = Ruta::query()->whereDate('fecha', $fecha)
            ->when($empresas && ! $individuales, fn ($q) => $q->whereHas('paradas', fn ($p) => $p->whereNotNull('entrega_empresa_id')))
            ->when($individuales && ! $empresas, fn ($q) => $q->whereHas('paradas', fn ($p) => $p->whereNotNull('pedido_dia_id')))
            ->pluck('repartidor_id')->all();
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
                    [$asignados, $sobran] = $this->llenarAuto($resto, $capacidad);
                    if (! $asignados) {
                        break; // Ninguno cabe solo en un auto: otro auto igual tampoco serviría.
                    }
                    $noAntesDe = $this->finDeSuRutaDeEmpresas($repartidor, $fecha);
                    [$inicio, $fin] = $this->ventana(collect($asignados), $noAntesDe);
                    if ($this->seEmpalma($repartidor, $fecha, $inicio, $fin)) {
                        continue; // ya tiene otra ruta a esa hora ese día (por ejemplo, si el corte de clientes se hizo antes que el de empresas)
                    }
                    $resto = $sobran;
                    $this->guardarRuta($corte, $fecha, $repartidor, $asignados, $capacidad, $noAntesDe);
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

    /** ¿Ese repartidor ya tiene otra ruta ese día que se cruza con esta ventana? (terminar justo cuando empieza la otra no cuenta). */
    private function seEmpalma(Repartidor $repartidor, string $fecha, string $inicio, string $fin): bool
    {
        return Ruta::query()->whereDate('fecha', $fecha)->where('repartidor_id', $repartidor->id)
            ->where('ventana_inicio', '<', $fin)->where('ventana_fin', '>', $inicio)->exists();
    }

    /**
     * Cuándo sale y cuándo termina una ruta con esas entregas, y la hora estimada del cliente número $n (1, 2…).
     *
     * @return array{0: string, 1: string, 2: \Closure}
     */
    private function ventana(\Illuminate\Support\Collection $items, ?string $noAntesDe): array
    {
        $empresas = $items->where('tipo', 'empresa');
        $clientes = $items->where('tipo', 'cliente');

        // Una ruta de clientes individuales sale el sábado a las 12:00, o cuando su repartidor termina la ruta de empresas de ese día.
        $inicio = $empresas->map(fn ($i) => $i['modelo']->ventana_inicio)->min() ?? max(self::INICIO_SIN_EMPRESAS, (string) $noAntesDe);
        $finEmpresas = $empresas->map(fn ($i) => $i['modelo']->ventana_fin)->max() ?? $inicio;
        $horaDeCliente = function (int $n) use ($finEmpresas) {
            $base = CarbonImmutable::createFromTimeString($finEmpresas);
            $hora = $base->addMinutes($n * self::MINUTOS_ENTRE_ENTREGAS_INDIVIDUALES);

            return $hora->isSameDay($base) ? $hora->format('H:i:s') : '23:59:00'; // nunca pasa de la medianoche
        };

        return [$inicio, $clientes->isEmpty() ? $finEmpresas : $horaDeCliente($clientes->count()), $horaDeCliente];
    }

    /** A qué hora termina la ruta de empresas que ese repartidor ya tiene ese día (para que la de clientes no se le empalme). */
    private function finDeSuRutaDeEmpresas(Repartidor $repartidor, string $fecha): ?string
    {
        return Ruta::query()->whereDate('fecha', $fecha)->where('repartidor_id', $repartidor->id)
            ->whereHas('paradas', fn ($p) => $p->whereNotNull('entrega_empresa_id'))->max('ventana_fin');
    }

    /**
     * @param  array<int, array>  $items  entregas a empresas, o entregas a clientes individuales (se arman en pasadas distintas)
     * @param  string|null  $noAntesDe  hora en que el repartidor termina su ruta de empresas de ese día; la de clientes sale después
     */
    private function guardarRuta(Corte $corte, string $fecha, Repartidor $repartidor, array $items, array $capacidad, ?string $noAntesDe = null): void
    {
        $items = collect($items);
        $empresas = $items->where('tipo', 'empresa');
        $clientes = $items->where('tipo', 'cliente')->values();

        [$inicio, $fin, $horaDeCliente] = $this->ventana($items, $noAntesDe);

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
