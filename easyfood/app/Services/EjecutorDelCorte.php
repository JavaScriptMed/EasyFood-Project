<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Configuracion;
use App\Models\ContratoComedor;
use App\Models\ContratoEmpresa;
use App\Models\Corte;
use App\Models\DiaSinServicio;
use App\Models\DisponibilidadDia;
use App\Models\EleccionEmpleado;
use App\Models\Empleado;
use App\Models\EntregaEmpresa;
use App\Models\Incidencia;
use App\Models\Menu;
use App\Models\OrdenProduccion;
use App\Models\Parada;
use App\Models\PedidoDia;
use App\Models\Repartidor;
use App\Models\Ruta;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Corte diario de una fecha de entrega. En una sola transacción:
 *  1. aplica la regla configurada a quien no eligió (ultimo_platillo, platillo_del_dia o no_entregar);
 *  2. cierra las elecciones (cerrada_en): desde ese momento ya no se cambian;
 *  3. genera una orden de producción por comedor y platillo;
 *  4. consolida una sola entrega por empresa;
 *  5. crea rutas respetando la capacidad del auto (entregas y platillos) y agrupando por zona: primero las entregas a empresas
 *     y después las de clientes individuales (cuya zona sale de su dirección).
 * Antes de contar nada cancela los pedidos individuales por pagar que ya no se pueden pagar.
 *
 * Es idempotente: ejecutarlo otra vez para la misma fecha no cambia nada y deja un registro «sin_cambios».
 * Si algo falla, nada se guarda salvo un registro «fallido» con el motivo, y se puede volver a ejecutar.
 */
class EjecutorDelCorte
{
    /** La recolección en el comedor es esta cantidad de minutos antes de la primera ventana de entrega que atiende. */
    private const MINUTOS_ANTES_DE_LA_VENTANA = 90;

    private const HORA_DE_ENTREGA_SIN_DATOS = '12:00';

    /** Una ruta que solo lleva entregas a clientes individuales arranca a esta hora… */
    private const INICIO_SIN_EMPRESAS = '12:00:00';

    /** …y cada entrega a un cliente individual se estima esta cantidad de minutos después de la anterior (tras las de empresas). */
    private const MINUTOS_ENTRE_ENTREGAS_INDIVIDUALES = 10;

    public function __construct(
        private readonly CalendarioDeServicio $calendario,
        private readonly ContratacionDePaquetes $paquetes,
    ) {}

    /**
     * @param  int|null  $usuarioId  null = lo lanza el sistema a la hora de corte; con valor, lo lanzó esa persona a mano.
     */
    public function ejecutar(CarbonInterface|string $fechaEntrega, ?int $usuarioId = null, ?CarbonInterface $ahora = null): Corte
    {
        $dia = $this->calendario->dia($fechaEntrega);
        $ahora = $this->calendario->ahora($ahora);

        if (! $this->calendario->esDiaDeServicio($dia)) {
            throw new ReglaDeNegocioException('dia_sin_servicio', "El {$dia->toDateString()} no es día de servicio: no hay corte.");
        }
        if (! $dia->gt($ahora->startOfDay())) {
            throw new ReglaDeNegocioException('fecha_pasada', 'El corte solo puede ejecutarse para una fecha de entrega futura.');
        }
        if ($usuarioId === null && $ahora->lt($this->calendario->momentoDelCorte($dia))) {
            throw new ReglaDeNegocioException('corte_prematuro', 'Aún no es la hora del corte de esa fecha.');
        }

        try {
            return DB::transaction(fn () => $this->cerrar($dia, $usuarioId, $ahora));
        } catch (Throwable $error) {
            report($error);

            return Corte::create([
                'fecha_entrega' => $dia->toDateString(), 'ejecutado_en' => $ahora, 'ejecutado_por' => $usuarioId,
                'estado' => 'fallido', 'sin_cambios' => false, 'mensaje' => mb_substr('El corte falló y no cambió nada: '.$error->getMessage(), 0, 1000),
            ])->refresh();
        }
    }

    private function cerrar(CarbonImmutable $dia, ?int $usuarioId, CarbonImmutable $ahora): Corte
    {
        $fecha = $dia->toDateString();

        // Dos ejecuciones simultáneas del mismo día se turnan; la segunda ya verá el cierre de la primera.
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ["corte:{$fecha}"]);

        if ($this->calendario->corteYaEjecutado($dia)) {
            return Corte::create([
                'fecha_entrega' => $fecha, 'ejecutado_en' => $ahora, 'ejecutado_por' => $usuarioId, 'estado' => 'correcto',
                'sin_cambios' => true, 'mensaje' => "El corte del {$fecha} ya se había ejecutado: no hubo cambios.",
            ])->refresh();
        }

        $this->paquetes->cancelarVencidos($ahora, $fecha);

        $advertencias = [];
        $contratos = $this->contratosVigentes($dia);

        [$porDefecto, $sinEntrega] = $this->aplicarReglaSinEleccion($dia, $contratos);
        if ($sinEntrega > 0) {
            $advertencias[] = "{$sinEntrega} elección(es) por defecto quedaron sin entrega: el platillo no estaba disponible o no hay dato para aplicar la regla.";
        }

        $cerradas = DB::table('elecciones_empleado')->whereDate('fecha', $fecha)->whereNull('cerrada_en')
            ->update(['cerrada_en' => $ahora, 'actualizado_en' => $ahora]);

        // El corte se registra después de insertar las elecciones por defecto (el trigger bloquea elecciones tras un corte real).
        $corte = Corte::create([
            'fecha_entrega' => $fecha, 'ejecutado_en' => $ahora, 'ejecutado_por' => $usuarioId, 'estado' => 'correcto',
            'sin_cambios' => false, 'elecciones_cerradas' => $cerradas, 'empleados_por_defecto' => $porDefecto,
        ]);

        $porEmpresa = $this->eleccionesPorEmpresaYPlatillo($fecha);
        $entregas = $this->consolidarEntregas($corte, $fecha, $porEmpresa, $contratos, $advertencias);
        $ordenes = $this->generarOrdenes($corte, $fecha, $porEmpresa, $contratos);
        [$rutas, $sinRuta] = $this->crearRutas($corte, $fecha);
        if ($sinRuta > 0) {
            $advertencias[] = "{$sinRuta} entrega(s) quedaron sin ruta por falta de repartidores con capacidad (o por una dirección sin zona): hay una incidencia abierta por cada una.";
        }

        $corte->update([
            'ordenes_generadas' => $ordenes, 'entregas_consolidadas' => $entregas, 'rutas_creadas' => $rutas,
            'estado' => $advertencias ? 'con_advertencias' : 'correcto', 'mensaje' => $advertencias ? implode(' ', $advertencias) : null,
        ]);

        return $corte->refresh();
    }

    // ------------------------------------------------------------------ 1. quien no eligió

    /** @return array{0:int,1:int} elecciones por defecto creadas, y casos que quedaron sin entrega */
    private function aplicarReglaSinEleccion(CarbonImmutable $dia, Collection $contratos): array
    {
        $regla = Configuracion::find('regla_sin_eleccion')?->valor ?? 'ultimo_platillo';
        if ($regla === 'no_entregar' || $contratos->isEmpty()) {
            return [0, 0];
        }

        $fecha = $dia->toDateString();
        $cierres = DiaSinServicio::query()->whereDate('fecha', $fecha)->whereNotNull('empresa_id')->pluck('empresa_id')->flip();
        $empleados = Empleado::query()->activos()->whereIn('empresa_id', $contratos->keys())
            ->whereHas('empresa', fn ($q) => $q->activos())->get();

        $existentes = DB::table('elecciones_empleado')->whereDate('fecha', $fecha)->get(['empleado_id', 'tiempo_comida'])
            ->mapWithKeys(fn ($f) => ["{$f->empleado_id}|{$f->tiempo_comida}" => true]);

        // Último platillo de cada empleado por tiempo de comida (el más reciente anterior a la fecha de entrega).
        $historial = collect(DB::select(
            'select distinct on (empleado_id, tiempo_comida) empleado_id, tiempo_comida, menu_id
             from elecciones_empleado where fecha < ? order by empleado_id, tiempo_comida, fecha desc',
            [$fecha],
        ))->groupBy('empleado_id')->map(fn ($filas) => $filas->pluck('menu_id', 'tiempo_comida'));

        $disponibilidad = DisponibilidadDia::query()->whereDate('fecha', $fecha)->orderBy('id')->lockForUpdate()->get()->keyBy('menu_id');
        $menus = Menu::query()->whereIn('id', $disponibilidad->keys())->get()->keyBy('id');
        $tomadas = [];

        $hayPorcion = function (?int $menuId, string $tiempo) use ($disponibilidad, $menus, &$tomadas): bool {
            $menu = $menuId ? $menus->get($menuId) : null;
            $disp = $menuId ? $disponibilidad->get($menuId) : null;

            return $menu && $disp && $menu->estado_revision === 'publicado' && $menu->is_available && $menu->tiempo_comida === $tiempo
                && $disp->porciones_tomadas + ($tomadas[$menuId] ?? 0) < $disp->porciones_maximas;
        };

        // Con varios comedores que marcaron su platillo del día, va primero el que más atrás está en su compra mínima del mes.
        // El avance se calcula una sola vez, antes de repartir, para que todos los tiempos de comida usen la misma foto.
        $marcados = $disponibilidad->filter(fn ($d) => $d->es_platillo_del_dia)->keys()->map(fn ($id) => $menus->get($id)?->provider_id)->filter()->unique()->values()->all();
        $avance = $marcados ? $this->avanceDeComedores($marcados, $dia) : [];
        $ranking = [];

        $platilloDelDia = function (string $tiempo) use ($disponibilidad, $menus, $avance, &$ranking, $hayPorcion): ?int {
            $ranking[$tiempo] ??= $this->menusDelDiaPorRezago($disponibilidad, $menus, $avance, $tiempo);
            foreach ($ranking[$tiempo] as $menuId) {
                if ($hayPorcion($menuId, $tiempo)) {
                    return $menuId; // el que sigue en el orden si el anterior ya no tiene porciones libres
                }
            }

            return null;
        };

        $creadas = 0;
        $sinEntrega = 0;
        foreach ($empleados as $empleado) {
            if ($cierres->has($empleado->empresa_id)) {
                continue;
            }
            $contrato = $contratos[$empleado->empresa_id];
            $previos = $historial->get($empleado->id, collect());
            $tiempos = $previos->isNotEmpty() ? $previos->keys()->all() : ['comida'];

            foreach ($tiempos as $tiempo) {
                if ($existentes->has("{$empleado->id}|{$tiempo}")) {
                    continue;
                }
                $menuId = $regla === 'ultimo_platillo' ? $previos->get($tiempo) : $platilloDelDia($tiempo);
                if (! $hayPorcion($menuId, $tiempo)) {
                    $sinEntrega++;

                    continue;
                }
                EleccionEmpleado::create([
                    'empleado_id' => $empleado->id, 'fecha' => $fecha, 'tiempo_comida' => $tiempo, 'menu_id' => $menuId,
                    'precio_congelado' => $contrato->precio_por_platillo, 'porcentaje_subsidio_congelado' => $contrato->porcentaje_subsidio,
                    'origen' => 'por_defecto',
                ]);
                $tomadas[$menuId] = ($tomadas[$menuId] ?? 0) + 1;
                $creadas++;
            }
        }

        foreach ($tomadas as $menuId => $cantidad) {
            $disponibilidad[$menuId]->increment('porciones_tomadas', $cantidad);
        }

        return [$creadas, $sinEntrega];
    }

    /**
     * Avance de cada comedor en su compra mínima del mes de la entrega: platillos del mes / mínimo de su contrato vigente.
     * Platillos del mes = porciones de sus órdenes de producción de los días anteriores del mes (ya cortados)
     *                   + lo ya elegido para la fecha que se está cortando (empleados y clientes individuales pagados).
     * Un comedor sin contrato vigente no participa.
     *
     * @param  array<int, int>  $comedores
     * @return array<int, array{platillos:int, minimo:int}> por comedor
     */
    private function avanceDeComedores(array $comedores, CarbonImmutable $dia): array
    {
        $fecha = $dia->toDateString();
        $inicioMes = $dia->startOfMonth()->toDateString();

        $minimos = ContratoComedor::query()->activos()->whereIn('comedor_id', $comedores)
            ->whereDate('vigencia_inicio', '<=', $fecha)->whereDate('vigencia_fin', '>=', $fecha)
            ->pluck('compra_minima_mensual', 'comedor_id');

        $previos = DB::table('ordenes_produccion')->whereIn('comedor_id', $comedores)
            ->whereDate('fecha', '>=', $inicioMes)->whereDate('fecha', '<', $fecha)
            ->groupBy('comedor_id')->selectRaw('comedor_id, sum(porciones_totales) as total')->pluck('total', 'comedor_id');

        $elegidos = DB::table('elecciones_empleado as e')->join('menus as m', 'm.id', '=', 'e.menu_id')
            ->whereIn('m.provider_id', $comedores)->whereDate('e.fecha', $fecha)
            ->groupBy('m.provider_id')->selectRaw('m.provider_id, count(*) as total')->pluck('total', 'provider_id');

        $individuales = DB::table('pedido_dias as d')->join('pedidos as p', 'p.id', '=', 'd.pedido_id')->join('menus as m', 'm.id', '=', 'd.menu_id')
            ->whereIn('m.provider_id', $comedores)->whereDate('d.fecha', $fecha)->where('d.estado', 'programada')->whereIn('p.estado', ['recibido', 'activo'])
            ->groupBy('m.provider_id')->selectRaw('m.provider_id, count(*) as total')->pluck('total', 'provider_id');

        $avance = [];
        foreach ($minimos as $comedorId => $minimo) {
            $avance[$comedorId] = [
                'platillos' => (int) ($previos[$comedorId] ?? 0) + (int) ($elegidos[$comedorId] ?? 0) + (int) ($individuales[$comedorId] ?? 0),
                'minimo' => (int) $minimo,
            ];
        }

        return $avance;
    }

    /**
     * Platillos del día marcados para un tiempo de comida, del comedor más atrás al más adelantado:
     * menor avance (platillos / mínimo); si empatan, el de menos platillos; si aún empatan, el de menor id.
     * Se compara con productos cruzados para no depender de decimales.
     *
     * @param  array<int, array{platillos:int, minimo:int}>  $avance
     * @return array<int, int> ids de menú en orden de asignación
     */
    private function menusDelDiaPorRezago(Collection $disponibilidad, Collection $menus, array $avance, string $tiempo): array
    {
        $candidatos = [];
        foreach ($disponibilidad as $menuId => $disp) {
            $menu = $menus->get($menuId);
            if ($disp->es_platillo_del_dia && $menu && $menu->estado_revision === 'publicado' && $menu->is_available
                && $menu->tiempo_comida === $tiempo && isset($avance[$menu->provider_id])) {
                $candidatos[] = ['menu' => $menuId, 'comedor' => $menu->provider_id] + $avance[$menu->provider_id];
            }
        }

        usort($candidatos, fn ($a, $b) => ($a['platillos'] * $b['minimo']) <=> ($b['platillos'] * $a['minimo'])
            ?: $a['platillos'] <=> $b['platillos']
            ?: $a['comedor'] <=> $b['comedor']);

        return array_column($candidatos, 'menu');
    }

    // ------------------------------------------------------------------ 3 y 4. órdenes y entregas

    /** @return Collection<int, \stdClass> filas (menu_id, provider_id, empresa_id, cantidad) */
    private function eleccionesPorEmpresaYPlatillo(string $fecha): Collection
    {
        return DB::table('elecciones_empleado as e')
            ->join('empleados as emp', 'emp.id', '=', 'e.empleado_id')
            ->join('menus as m', 'm.id', '=', 'e.menu_id')
            ->whereDate('e.fecha', $fecha)
            ->groupBy('e.menu_id', 'm.provider_id', 'emp.empresa_id')
            ->select('e.menu_id', 'm.provider_id', 'emp.empresa_id', DB::raw('count(*) as cantidad'))
            ->get();
    }

    /** Una entrega por empresa y día, con la ventana de su contrato. Devuelve cuántas consolidó. */
    private function consolidarEntregas(Corte $corte, string $fecha, Collection $porEmpresa, Collection $contratos, array &$advertencias): int
    {
        $total = 0;
        foreach ($porEmpresa->groupBy('empresa_id') as $empresaId => $filas) {
            $contrato = $contratos->get($empresaId);
            if (! $contrato) {
                $advertencias[] = "La empresa {$empresaId} tiene elecciones pero ningún contrato vigente: no se consolidó su entrega.";

                continue;
            }
            EntregaEmpresa::updateOrCreate(
                ['empresa_id' => $empresaId, 'fecha' => $fecha],
                [
                    'corte_id' => $corte->id, 'platillos' => $filas->sum('cantidad'), 'estado' => 'programada',
                    'ventana_inicio' => $contrato->ventana_entrega_inicio, 'ventana_fin' => $contrato->ventana_entrega_fin,
                ],
            );
            $total++;
        }

        return $total;
    }

    /** Una orden por comedor y platillo. Devuelve cuántas generó. */
    private function generarOrdenes(Corte $corte, string $fecha, Collection $porEmpresa, Collection $contratos): int
    {
        $individuales = DB::table('pedido_dias as d')
            ->join('pedidos as p', 'p.id', '=', 'd.pedido_id')->join('menus as m', 'm.id', '=', 'd.menu_id')
            ->whereDate('d.fecha', $fecha)->where('d.estado', 'programada')->whereIn('p.estado', ['recibido', 'activo'])
            ->groupBy('d.menu_id', 'm.provider_id')->select('d.menu_id', 'm.provider_id', DB::raw('count(*) as cantidad'))->get();

        $primeraDelDia = $porEmpresa->pluck('empresa_id')->unique()->map(fn ($id) => $contratos->get($id)?->ventana_entrega_inicio)->filter()->min();

        $ordenes = [];
        foreach ($porEmpresa->groupBy('menu_id') as $menuId => $filas) {
            $ventanas = $filas->pluck('empresa_id')->unique()->map(fn ($id) => $contratos->get($id)?->ventana_entrega_inicio)->filter();
            $ordenes[$menuId] = [
                'comedor_id' => $filas->first()->provider_id, 'empresas' => (int) $filas->sum('cantidad'), 'individuales' => 0,
                'ventana' => $ventanas->min() ?? $primeraDelDia,
            ];
        }
        foreach ($individuales as $fila) {
            $ordenes[$fila->menu_id] ??= ['comedor_id' => $fila->provider_id, 'empresas' => 0, 'individuales' => 0, 'ventana' => $primeraDelDia];
            $ordenes[$fila->menu_id]['individuales'] = (int) $fila->cantidad;
        }

        foreach ($ordenes as $menuId => $orden) {
            $recoleccion = CarbonImmutable::createFromTimeString($orden['ventana'] ?? self::HORA_DE_ENTREGA_SIN_DATOS)
                ->subMinutes(self::MINUTOS_ANTES_DE_LA_VENTANA)->format('H:i:s');

            OrdenProduccion::updateOrCreate(
                ['comedor_id' => $orden['comedor_id'], 'menu_id' => $menuId, 'fecha' => $fecha],
                [
                    'corte_id' => $corte->id, 'porciones_empresas' => $orden['empresas'], 'porciones_individuales' => $orden['individuales'],
                    'porciones_totales' => $orden['empresas'] + $orden['individuales'], 'hora_recoleccion' => $recoleccion,
                ],
            );
        }

        return count($ordenes);
    }

    // ------------------------------------------------------------------ 5. rutas

    /**
     * Agrupa por zona y llena un auto a la vez sin pasar de su capacidad (entregas y platillos). En cada zona van primero
     * las entregas a empresas (por ventana) y después las de clientes individuales (cada una cuenta como 1 entrega y 1 platillo).
     * Lo que no cabe, o una dirección sin zona, queda sin ruta y genera una incidencia abierta.
     *
     * @return array{0:int,1:int} rutas creadas y entregas sin ruta
     */
    private function crearRutas(Corte $corte, string $fecha): array
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

    // ------------------------------------------------------------------ apoyo

    /** @return Collection<int, ContratoEmpresa> contratos activos que cubren la fecha, por empresa */
    private function contratosVigentes(CarbonImmutable $dia): Collection
    {
        return ContratoEmpresa::query()->activos()
            ->whereDate('vigencia_inicio', '<=', $dia->toDateString())->whereDate('vigencia_fin', '>=', $dia->toDateString())
            ->get()->keyBy('empresa_id');
    }

    private function zona(string $zona): string
    {
        return mb_strtolower(trim($zona));
    }
}
