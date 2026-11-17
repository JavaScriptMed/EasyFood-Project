<?php

namespace App\Services;

use App\Models\Configuracion;
use App\Models\Empresa;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores del dashboard del administrador y del portal de empresas. Todo se calcula al pedirlo (tiempo real), sin tablas de resumen.
 *
 * Periodos: «semana» = de lunes a domingo de la semana en curso; «mes» = el mes calendario en curso (la facturación mira el mes anterior,
 * que es el que ya se cobra). Las series son las últimas 6 semanas.
 *
 * Reglas de cada cifra:
 * - Platillos entregados: los de las entregas a empresas con estado «entregada» (lo mismo que cuenta la factura).
 * - Calificación de la entrega: promedio simple de puntualidad, trato del chofer y empaque.
 * - Ahorro estimado: lo gastado en platillos entregados × el sobreprecio estimado de las apps de entrega (editable en Configuración).
 * - Balance semanal (devengado por entrega): ingresos = platillos entregados × su precio (empresas: el precio congelado; clientes: con el
 *   descuento de su paquete); costos = lo que se paga a las cocinas por esos platillos (su precio de contrato) + los costos operativos
 *   registrados esa semana. Utilidad = ingresos − costos.
 */
class IndicadoresDelDashboard
{
    public const TIPOS_DE_QUEJA = ['comida_fria' => 'Comida fría', 'platillo_danado' => 'Empaque dañado', 'entrega_tardia' => 'Entrega tardía'];

    private const SEMANAS_DE_SERIE = 6;

    public function __construct(private readonly CalendarioDeServicio $calendario) {}

    // ------------------------------------------------------------------ resumen operativo del día

    private const ESTADOS_DE_ENTREGA = [
        'programada' => ['Programadas', '#94a3b8'], 'en_consolidacion' => ['En consolidación', '#e9b51f'], 'en_ruta' => ['En ruta', '#3d7fe8'], 'entregada' => ['Entregadas', '#43a86b'],
        'no_localizado' => ['No localizadas', '#d9822b'], 'reprogramada' => ['Reprogramadas', '#a98bc4'], 'cancelada' => ['Canceladas', '#9ca3af'],
    ];

    /**
     * Las cifras de arriba del dashboard (entregas de hoy, pedidos activos, ingresos de la semana, últimos 7 días, zonas).
     *
     * @return array<string, mixed>
     */
    public function resumen(?CarbonInterface $ahora = null): array
    {
        [$hoy, $semana] = $this->periodos($ahora);
        $dia = $hoy->toDateString();

        $porEstado = DB::table('entregas_empresa')->whereDate('fecha', $dia)->selectRaw('estado, count(*)::int as n')->groupBy('estado')->pluck('n', 'estado')->all();
        foreach (DB::table('pedido_dias as d')->join('pedidos as p', 'p.id', '=', 'd.pedido_id')->whereIn('p.estado', ['recibido', 'activo', 'completado'])->whereDate('d.fecha', $dia)
            ->selectRaw('d.estado, count(*)::int as n')->groupBy('d.estado')->pluck('n', 'estado') as $estado => $n) {
            $porEstado[$estado] = ($porEstado[$estado] ?? 0) + $n;
        }
        $estados = [];
        foreach (self::ESTADOS_DE_ENTREGA as $estado => [$etiqueta, $color]) {
            if (($porEstado[$estado] ?? 0) > 0) {
                $estados[] = ['estado' => $etiqueta, 'cantidad' => (int) $porEstado[$estado], 'color' => $color];
            }
        }

        $ingresos = $this->balance($semana['inicio'], $semana['fin'])['ingresos'];
        $anterior = $this->balance($semana['inicio']->subWeek(), $semana['fin']->subWeek())['ingresos'];
        $nombres = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
        $pedidosPorDia = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = $hoy->subDays($i);
            $platillos = DB::table('elecciones_empleado')->whereDate('fecha', $d->toDateString())->count()
                + DB::table('pedido_dia_platillos as c')->join('pedido_dias as pd', 'pd.id', '=', 'c.pedido_dia_id')->join('pedidos as p', 'p.id', '=', 'pd.pedido_id')
                    ->whereIn('p.estado', ['recibido', 'activo', 'completado'])->whereDate('pd.fecha', $d->toDateString())->count();
            $pedidosPorDia[] = ['dia' => $nombres[$d->dayOfWeek], 'pedidos' => $platillos];
        }

        return [
            'entregas_hoy' => array_sum($porEstado), 'entregas_completadas' => (int) ($porEstado['entregada'] ?? 0),
            'pedidos_activos' => DB::table('pedidos')->whereIn('estado', ['recibido', 'activo'])->count(),
            'en_consolidacion' => (int) ($porEstado['en_consolidacion'] ?? 0), 'en_ruta' => (int) ($porEstado['en_ruta'] ?? 0),
            'ingresos_semana' => $ingresos, 'variacion_ingresos_porcentaje' => $anterior > 0 ? round(($ingresos - $anterior) / $anterior * 100, 1) : 0.0,
            'pedidos_por_dia' => $pedidosPorDia, 'entregas_por_estado' => $estados, 'zonas_activas' => $this->zonasEnElMapa(),
        ];
    }

    /** Cada zona con su centro, en porcentaje sobre el recuadro que cubren todas (para dibujarlas en el mapa del dashboard). */
    private function zonasEnElMapa(): array
    {
        $zonas = DB::select('select nombre, ST_X(ST_Centroid(area::geometry)) as x, ST_Y(ST_Centroid(area::geometry)) as y from zonas order by nombre');
        if (! $zonas) {
            return [];
        }
        $xs = array_column($zonas, 'x');
        $ys = array_column($zonas, 'y');
        $ancho = max(0.0001, max($xs) - min($xs));
        $alto = max(0.0001, max($ys) - min($ys));

        return array_map(fn ($z) => [
            'zona' => $z->nombre, 'x' => round(15 + ($z->x - min($xs)) / $ancho * 60).'%', 'y' => round(15 + (max($ys) - $z->y) / $alto * 55).'%',
        ], $zonas);
    }

    // ------------------------------------------------------------------ administrador

    public function paraAdministrador(?CarbonInterface $ahora = null): array
    {
        [$hoy, $semana, $mes] = $this->periodos($ahora);
        $semanas = $this->semanas($semana['inicio']);

        $platillosSerie = array_map(fn ($s) => ['semana_inicio' => $s['inicio']->toDateString(), 'platillos' => $this->platillosEntregados($s['inicio'], $s['fin'])], $semanas);
        $entregaSerie = array_map(fn ($s) => ['semana_inicio' => $s['inicio']->toDateString()] + $this->promediosDeEntrega($s['inicio'], $s['fin']), $semanas);
        $menuSerie = array_map(fn ($s) => ['semana_inicio' => $s['inicio']->toDateString()] + $this->promedioDelMenu($s['inicio'], $s['fin']), $semanas);
        $quejasSerie = array_map(fn ($s) => ['semana_inicio' => $s['inicio']->toDateString(), 'total' => $this->quejas($s['inicio'], $s['fin'])['total']], $semanas);
        $balanceSerie = array_map(fn ($s) => ['semana_inicio' => $s['inicio']->toDateString()] + $this->balance($s['inicio'], $s['fin']), $semanas);

        return [
            'periodo' => ['semana_inicio' => $semana['inicio']->toDateString(), 'semana_fin' => $semana['fin']->toDateString(), 'mes' => $mes['inicio']->format('Y-m'), 'actualizado_en' => $hoy->toIso8601String()],
            'platillos_entregados' => ['semana' => $this->platillosEntregados($semana['inicio'], $semana['fin']), 'por_empresa' => $this->platillosPorEmpresa($semana['inicio'], $semana['fin']), 'serie' => $platillosSerie],
            'calificacion_entrega' => $this->promediosDeEntrega($semana['inicio'], $semana['fin']) + ['serie' => $entregaSerie],
            'calificacion_menu' => $this->promedioDelMenu($semana['inicio'], $semana['fin']) + ['por_comedor' => $this->menuPorComedor($semana['inicio'], $semana['fin']), 'serie' => $menuSerie],
            'paquete_popular' => $this->paquetePopular($mes['inicio'], $mes['fin']),
            'ahorro_estimado' => $this->ahorro($mes['inicio'], $mes['fin']),
            'incidencias' => ['semana' => $this->quejas($semana['inicio'], $semana['fin']), 'serie' => $quejasSerie],
            'contratos_nuevos' => $this->contratosNuevos($mes['inicio'], $mes['fin']),
            'balance' => ['semana' => end($balanceSerie), 'serie' => $balanceSerie],
            'costo_maquila' => $this->costoDeMaquila($semana['inicio'], $semana['fin'], $hoy),
            'facturacion_b2b' => $this->facturacion($this->mesQueSeCobra($hoy)),
        ];
    }

    // ------------------------------------------------------------------ portal de empresas

    public function paraEmpresa(Empresa $empresa, ?CarbonInterface $ahora = null): array
    {
        [$hoy, $semana, $mes] = $this->periodos($ahora);
        $semanas = $this->semanas($semana['inicio']);

        return [
            'periodo' => ['semana_inicio' => $semana['inicio']->toDateString(), 'semana_fin' => $semana['fin']->toDateString(), 'mes' => $mes['inicio']->format('Y-m'), 'actualizado_en' => $hoy->toIso8601String()],
            'platillos_entregados' => [
                'semana' => $this->platillosEntregados($semana['inicio'], $semana['fin'], $empresa->id),
                'por_dia' => $this->platillosPorDia($semana['inicio'], $semana['fin'], $empresa->id),
                'serie' => array_map(fn ($s) => ['semana_inicio' => $s['inicio']->toDateString(), 'platillos' => $this->platillosEntregados($s['inicio'], $s['fin'], $empresa->id)], $semanas),
            ],
            'calificacion_entrega' => $this->promediosDeEntrega($semana['inicio'], $semana['fin'], $empresa->id),
            'calificacion_menu' => $this->promedioDelMenu($semana['inicio'], $semana['fin'], $empresa->id)
                + ['serie' => array_map(fn ($s) => ['semana_inicio' => $s['inicio']->toDateString()] + $this->promedioDelMenu($s['inicio'], $s['fin'], $empresa->id), $semanas)],
            'ahorro_estimado' => $this->ahorro($mes['inicio'], $mes['fin'], $empresa->id),
            'incidencias' => ['semana' => $this->quejas($semana['inicio'], $semana['fin'], $empresa->id),
                'serie' => array_map(fn ($s) => ['semana_inicio' => $s['inicio']->toDateString(), 'total' => $this->quejas($s['inicio'], $s['fin'], $empresa->id)['total']], $semanas)],
            'facturacion' => $this->facturasDeLaEmpresa($empresa->id, $hoy),
        ];
    }

    // ------------------------------------------------------------------ calificación del menú (la ve cualquiera con sesión)

    public function calificacionDelMenu(?CarbonInterface $ahora = null): array
    {
        [, $semana] = $this->periodos($ahora);

        return ['semana_inicio' => $semana['inicio']->toDateString(), 'semana_fin' => $semana['fin']->toDateString()] + $this->promedioDelMenu($semana['inicio'], $semana['fin'])
            + ['serie' => array_map(fn ($s) => ['semana_inicio' => $s['inicio']->toDateString()] + $this->promedioDelMenu($s['inicio'], $s['fin']), $this->semanas($semana['inicio']))];
    }

    // ------------------------------------------------------------------ 1. platillos entregados

    public function platillosEntregados(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $empresaId = null): int
    {
        return (int) DB::table('entregas_empresa')->where('estado', 'entregada')->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->when($empresaId, fn ($q) => $q->where('empresa_id', $empresaId))->sum('platillos');
    }

    /** @return array<int, array{empresa_id: int, empresa: string, platillos: int}> */
    private function platillosPorEmpresa(CarbonImmutable $inicio, CarbonImmutable $fin): array
    {
        return DB::table('entregas_empresa as en')->join('empresas as e', 'e.id', '=', 'en.empresa_id')->where('en.estado', 'entregada')
            ->whereBetween('en.fecha', [$inicio->toDateString(), $fin->toDateString()])->groupBy('e.id', 'e.nombre')->orderByDesc(DB::raw('sum(en.platillos)'))->orderBy('e.nombre')
            ->selectRaw('e.id as empresa_id, e.nombre as empresa, sum(en.platillos)::int as platillos')->get()
            ->map(fn ($f) => ['empresa_id' => (int) $f->empresa_id, 'empresa' => $f->empresa, 'platillos' => (int) $f->platillos])->all();
    }

    /** @return array<int, array{fecha: string, platillos: int}> */
    private function platillosPorDia(CarbonImmutable $inicio, CarbonImmutable $fin, int $empresaId): array
    {
        return DB::table('entregas_empresa')->where('empresa_id', $empresaId)->where('estado', 'entregada')->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->orderBy('fecha')->get(['fecha', 'platillos'])->map(fn ($f) => ['fecha' => substr((string) $f->fecha, 0, 10), 'platillos' => (int) $f->platillos])->all();
    }

    // ------------------------------------------------------------------ 2. calificación de la entrega (última milla)

    /** @return array{promedio: ?float, puntualidad: ?float, trato_chofer: ?float, empaque: ?float, calificaciones: int} */
    private function promediosDeEntrega(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $empresaId = null): array
    {
        $f = DB::table('calificaciones_entrega as c')->leftJoin('entregas_empresa as ee', 'ee.id', '=', 'c.entrega_empresa_id')->leftJoin('pedido_dias as pd', 'pd.id', '=', 'c.pedido_dia_id')
            ->whereBetween(DB::raw('coalesce(ee.fecha, pd.fecha)'), [$inicio->toDateString(), $fin->toDateString()])
            ->when($empresaId, fn ($q) => $q->where('ee.empresa_id', $empresaId))
            ->selectRaw('count(*)::int as n, avg(c.puntualidad) as p, avg(c.trato_chofer) as t, avg(c.empaque) as e')->first();
        $n = (int) $f->n;

        return [
            'promedio' => $n ? round(((float) $f->p + (float) $f->t + (float) $f->e) / 3, 2) : null,
            'puntualidad' => $n ? round((float) $f->p, 2) : null, 'trato_chofer' => $n ? round((float) $f->t, 2) : null, 'empaque' => $n ? round((float) $f->e, 2) : null,
            'calificaciones' => $n,
        ];
    }

    // ------------------------------------------------------------------ 3. calificación semanal del menú

    /** @return array{promedio: ?float, calificaciones: int} */
    private function promedioDelMenu(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $empresaId = null): array
    {
        $f = DB::table('calificaciones_platillo as c')
            ->when($empresaId, fn ($q) => $q->join('empleados as emp', 'emp.usuario_id', '=', 'c.calificador_id')->where('emp.empresa_id', $empresaId))
            ->whereBetween('c.fecha', [$inicio->toDateString(), $fin->toDateString()])->selectRaw('count(*)::int as n, avg(c.estrellas) as p')->first();

        return ['promedio' => $f->n ? round((float) $f->p, 2) : null, 'calificaciones' => (int) $f->n];
    }

    /** @return array<int, array{comedor: string, promedio: float, calificaciones: int}> */
    private function menuPorComedor(CarbonImmutable $inicio, CarbonImmutable $fin): array
    {
        return DB::table('calificaciones_platillo as c')->join('menus as m', 'm.id', '=', 'c.menu_id')->join('providers as p', 'p.id', '=', 'm.provider_id')
            ->whereBetween('c.fecha', [$inicio->toDateString(), $fin->toDateString()])->groupBy('p.id', 'p.commercial_name')->orderByDesc(DB::raw('avg(c.estrellas)'))
            ->selectRaw('p.commercial_name as comedor, avg(c.estrellas) as promedio, count(*)::int as n')->get()
            ->map(fn ($f) => ['comedor' => $f->comedor, 'promedio' => round((float) $f->promedio, 2), 'calificaciones' => (int) $f->n])->all();
    }

    // ------------------------------------------------------------------ 4. paquete más popular

    /** Pedidos pagados del mes por paquete (1, 2 o 6 días). El «popular» es el de más pedidos; si empatan, el de menos días. */
    private function paquetePopular(CarbonImmutable $inicio, CarbonImmutable $fin): array
    {
        $paquetes = DB::table('paquetes as q')->leftJoin('pedidos as p', fn ($j) => $j->on('p.paquete_id', '=', 'q.id')->whereIn('p.estado', ['recibido', 'activo', 'completado'])
            ->whereBetween('p.pagado_en', [$inicio->startOfDay()->toDateTimeString(), $fin->endOfDay()->toDateTimeString()]))
            ->where('q.activo', true)->groupBy('q.id', 'q.nombre', 'q.dias')->orderByDesc(DB::raw('count(p.id)'))->orderBy('q.dias')
            ->selectRaw('q.id, q.nombre, q.dias, count(p.id)::int as pedidos')->get()
            ->map(fn ($f) => ['id' => (int) $f->id, 'nombre' => $f->nombre, 'dias' => (int) $f->dias, 'pedidos' => (int) $f->pedidos])->all();

        return ['mes' => $inicio->format('Y-m'), 'paquete' => ($paquetes[0]['pedidos'] ?? 0) > 0 ? $paquetes[0] : null, 'paquetes' => $paquetes];
    }

    // ------------------------------------------------------------------ 5. ahorro estimado por empleado

    private function ahorro(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $empresaId = null): array
    {
        $porcentaje = (float) (Configuracion::find('ahorro_apps_porcentaje')?->valor ?? 30);
        $filas = DB::table('elecciones_empleado as e')->join('empleados as emp', 'emp.id', '=', 'e.empleado_id')->join('users as u', 'u.id', '=', 'emp.usuario_id')->join('empresas as em', 'em.id', '=', 'emp.empresa_id')
            ->join('entregas_empresa as en', fn ($j) => $j->on('en.empresa_id', '=', 'emp.empresa_id')->on('en.fecha', '=', 'e.fecha')->where('en.estado', 'entregada'))
            ->whereBetween('e.fecha', [$inicio->toDateString(), $fin->toDateString()])->when($empresaId, fn ($q) => $q->where('emp.empresa_id', $empresaId))
            ->groupBy('emp.id', 'u.name', 'em.id', 'em.nombre')->orderBy('u.name')
            ->selectRaw('emp.id as empleado_id, u.name as empleado, em.id as empresa_id, em.nombre as empresa, count(*)::int as platillos, sum(e.precio_congelado) as gasto')->get()
            ->map(fn ($f) => ['empleado_id' => (int) $f->empleado_id, 'empleado' => $f->empleado, 'empresa_id' => (int) $f->empresa_id, 'empresa' => $f->empresa, 'platillos' => (int) $f->platillos,
                'ahorro' => $this->ahorroDe((string) $f->gasto, $porcentaje)]);

        $total = $filas->sum('ahorro');
        $resultado = [
            'mes' => $inicio->format('Y-m'), 'porcentaje_sobreprecio' => $porcentaje, 'empleados' => $filas->count(),
            'por_empleado_promedio' => $filas->count() ? round($total / $filas->count(), 2) : 0.0, 'ahorro_total' => round($total, 2),
        ];
        if ($empresaId) {
            return $resultado + ['por_empleado' => $filas->sortByDesc('ahorro')->take(30)->map(fn ($f) => ['empleado_id' => $f['empleado_id'], 'empleado' => $f['empleado'], 'platillos' => $f['platillos'], 'ahorro' => $f['ahorro']])->values()->all()];
        }

        return $resultado + ['por_empresa' => $filas->groupBy('empresa_id')->map(fn ($g) => [
            'empresa_id' => $g[0]['empresa_id'], 'empresa' => $g[0]['empresa'], 'empleados' => $g->count(), 'ahorro_promedio' => round($g->sum('ahorro') / $g->count(), 2), 'ahorro_total' => round($g->sum('ahorro'), 2),
        ])->sortByDesc('ahorro_total')->values()->all()];
    }

    private function ahorroDe(string $gasto, float $porcentaje): float
    {
        return round(Dinero::centavos($gasto) * $porcentaje / 100 / 100, 2);
    }

    // ------------------------------------------------------------------ 6. incidencias o quejas

    /** @return array{total: int, abiertas: int, por_tipo: array<int, array{tipo: string, etiqueta: string, cantidad: int}>} */
    private function quejas(CarbonImmutable $inicio, CarbonImmutable $fin, ?int $empresaId = null): array
    {
        $porTipo = DB::table('incidencias as i')->whereIn('i.tipo', array_keys(self::TIPOS_DE_QUEJA))->whereBetween('i.fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->when($empresaId, fn ($q) => $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('entregas_empresa as ee')->whereColumn('ee.id', 'i.entrega_empresa_id')->where('ee.empresa_id', $empresaId)))
            ->groupBy('i.tipo')->selectRaw("i.tipo, count(*)::int as cantidad, count(*) filter (where i.estado = 'abierta')::int as abiertas")->get()->keyBy('tipo');

        $lista = [];
        foreach (self::TIPOS_DE_QUEJA as $tipo => $etiqueta) {
            $lista[] = ['tipo' => $tipo, 'etiqueta' => $etiqueta, 'cantidad' => (int) ($porTipo[$tipo]->cantidad ?? 0)];
        }

        return ['total' => array_sum(array_column($lista, 'cantidad')), 'abiertas' => (int) $porTipo->sum('abiertas'), 'por_tipo' => $lista];
    }

    // ------------------------------------------------------------------ 7. nuevos contratos B2B

    /** Empresas cuyo primer contrato empezó en el mes (no cuentan las renovaciones). */
    private function contratosNuevos(CarbonImmutable $inicio, CarbonImmutable $fin): array
    {
        $deMes = fn (CarbonImmutable $desde, CarbonImmutable $hasta) => DB::table('contratos_empresa as c')->join('empresas as e', 'e.id', '=', 'c.empresa_id')
            ->groupBy('e.id', 'e.nombre')->havingRaw('min(c.vigencia_inicio) between ? and ?', [$desde->toDateString(), $hasta->toDateString()])
            ->selectRaw('e.id as empresa_id, e.nombre as empresa, min(c.vigencia_inicio) as primer_inicio')->orderBy('primer_inicio')->get();

        $contratos = $deMes($inicio, $fin)->map(function ($f) {
            $c = DB::table('contratos_empresa')->where('empresa_id', $f->empresa_id)->whereDate('vigencia_inicio', $f->primer_inicio)->orderBy('id')->first();

            return [
                'empresa_id' => (int) $f->empresa_id, 'empresa' => $f->empresa, 'vigencia_inicio' => substr((string) $c->vigencia_inicio, 0, 10), 'vigencia_fin' => substr((string) $c->vigencia_fin, 0, 10),
                'meses' => (int) round(CarbonImmutable::parse($c->vigencia_inicio)->floatDiffInMonths(CarbonImmutable::parse($c->vigencia_fin)->addDay())),
                'compra_minima_mensual' => (int) $c->compra_minima_mensual,
            ];
        })->all();

        return ['mes' => $inicio->format('Y-m'), 'cantidad' => count($contratos), 'mes_anterior' => $deMes($inicio->subMonthNoOverflow()->startOfMonth(), $inicio->subDay())->count(), 'contratos' => $contratos];
    }

    // ------------------------------------------------------------------ 8. balance semanal de utilidad

    /** @return array{ingresos_empresas: float, ingresos_clientes: float, ingresos: float, costo_maquila: float, costos_operativos: float, costos: float, utilidad: float} */
    public function balance(CarbonImmutable $inicio, CarbonImmutable $fin): array
    {
        [$desde, $hasta] = [$inicio->toDateString(), $fin->toDateString()];
        $precioCocina = '(select c.precio_por_platillo from contratos_comedor c where c.comedor_id = m.provider_id order by (? between c.vigencia_inicio and c.vigencia_fin) desc, c.vigencia_inicio desc limit 1)';

        // Empresas: lo que se cobra por cada platillo entregado (el precio congelado al elegir) y lo que cuesta en cocina.
        $emp = DB::selectOne(
            "select coalesce(sum(e.precio_congelado), 0) as ingresos, coalesce(sum({$this->conFecha($precioCocina, 'e.fecha')}), 0) as maquila
             from elecciones_empleado e join empleados emp on emp.id = e.empleado_id join menus m on m.id = e.menu_id
             join entregas_empresa en on en.empresa_id = emp.empresa_id and en.fecha = e.fecha and en.estado = 'entregada'
             where e.fecha between ? and ?",
            [$desde, $hasta],
        );
        // Clientes individuales: el precio de cada platillo de los días entregados, con el descuento de su paquete.
        $cli = DB::selectOne(
            "select coalesce(sum(c.precio_congelado * (100 - q.descuento_porcentaje) / 100), 0) as ingresos, coalesce(sum({$this->conFecha($precioCocina, 'd.fecha')}), 0) as maquila
             from pedido_dia_platillos c join pedido_dias d on d.id = c.pedido_dia_id and d.estado = 'entregada' join pedidos p on p.id = d.pedido_id
             join paquetes q on q.id = p.paquete_id join menus m on m.id = c.menu_id
             where d.fecha between ? and ?",
            [$desde, $hasta],
        );
        $operativos = (string) DB::table('costos_operativos')->where('activo', true)->whereBetween('fecha', [$desde, $hasta])->sum('monto');

        $ingresosE = Dinero::centavos($emp->ingresos);
        $ingresosC = Dinero::centavos($cli->ingresos);
        $maquila = Dinero::centavos($emp->maquila) + Dinero::centavos($cli->maquila);
        $otros = Dinero::centavos($operativos);

        return [
            'ingresos_empresas' => $ingresosE / 100.0, 'ingresos_clientes' => $ingresosC / 100.0, 'ingresos' => ($ingresosE + $ingresosC) / 100.0,
            'costo_maquila' => $maquila / 100.0, 'costos_operativos' => $otros / 100.0, 'costos' => ($maquila + $otros) / 100.0,
            'utilidad' => ($ingresosE + $ingresosC - $maquila - $otros) / 100.0,
        ];
    }

    /** Pone la fecha de la fila donde la subconsulta del precio de la cocina tiene su «?». */
    private function conFecha(string $sql, string $columna): string
    {
        return str_replace('?', $columna, $sql);
    }

    // ------------------------------------------------------------------ 9. costo directo de maquila

    private function costoDeMaquila(CarbonImmutable $inicio, CarbonImmutable $fin, CarbonImmutable $hoy): array
    {
        $limite = (float) (Configuracion::find('costo_maquila_limite')?->valor ?? 49);
        $cocinas = DB::table('contratos_comedor as c')->join('providers as p', 'p.id', '=', 'c.comedor_id')->where('c.activo', true)->where('p.is_active', true)
            ->whereDate('c.vigencia_inicio', '<=', $hoy->toDateString())->whereDate('c.vigencia_fin', '>=', $hoy->toDateString())->orderBy('p.commercial_name')
            ->get(['p.id as comedor_id', 'p.commercial_name as comedor', 'c.precio_por_platillo'])
            ->map(fn ($f) => ['comedor_id' => (int) $f->comedor_id, 'comedor' => $f->comedor, 'precio_por_platillo' => round((float) $f->precio_por_platillo, 2), 'en_limite' => (float) $f->precio_por_platillo <= $limite])->all();

        $b = $this->balance($inicio, $fin);
        $platillos = $this->platillosEntregados($inicio, $fin) + $this->platillosDeClientes($inicio, $fin);
        $promedio = $platillos ? round($b['costo_maquila'] / $platillos, 2) : null;

        return [
            'limite' => $limite, 'platillos_semana' => $platillos, 'costo_maquila_semana' => $b['costo_maquila'], 'costo_promedio_semana' => $promedio,
            'en_limite' => ($promedio === null || $promedio <= $limite) && ! in_array(false, array_column($cocinas, 'en_limite'), true), 'cocinas' => $cocinas,
        ];
    }

    private function platillosDeClientes(CarbonImmutable $inicio, CarbonImmutable $fin): int
    {
        return (int) DB::table('pedido_dia_platillos as c')->join('pedido_dias as d', fn ($j) => $j->on('d.id', '=', 'c.pedido_dia_id')->where('d.estado', 'entregada'))
            ->whereBetween('d.fecha', [$inicio->toDateString(), $fin->toDateString()])->count();
    }

    // ------------------------------------------------------------------ 10. estatus de facturación B2B (semáforo)

    private const SEMAFORO = ['pagada' => 'verde', 'emitida' => 'rojo', 'borrador' => 'gris'];

    /** El mes más reciente con facturas; si todavía no hay ninguna, el mes anterior (el que ya se cobra). */
    private function mesQueSeCobra(CarbonImmutable $hoy): CarbonImmutable
    {
        $ultimo = DB::table('facturas_mensuales')->whereDate('mes', '<=', $hoy->startOfMonth()->toDateString())->max('mes');

        return $ultimo ? CarbonImmutable::parse($ultimo)->startOfMonth() : $hoy->startOfMonth()->subMonthNoOverflow();
    }

    /** Facturas de un mes de todas las empresas con contrato ese mes. Verde = pagada, rojo = emitida y pendiente de pago, gris = aún sin emitir. */
    private function facturacion(CarbonImmutable $mes): array
    {
        $filas = DB::table('empresas as e')->leftJoin('facturas_mensuales as f', fn ($j) => $j->on('f.empresa_id', '=', 'e.id')->whereDate('f.mes', $mes->toDateString()))
            ->whereExists(fn ($s) => $s->select(DB::raw(1))->from('contratos_empresa as c')->whereColumn('c.empresa_id', 'e.id')
                ->whereDate('c.vigencia_inicio', '<=', $mes->endOfMonth()->toDateString())->whereDate('c.vigencia_fin', '>=', $mes->toDateString()))
            ->orderBy('e.nombre')->get(['e.id as empresa_id', 'e.nombre as empresa', 'f.folio', 'f.total', 'f.estado', 'f.emitida_en', 'f.pagada_en']);

        $facturas = $filas->map(fn ($f) => [
            'empresa_id' => (int) $f->empresa_id, 'empresa' => $f->empresa, 'folio' => $f->folio, 'total' => $f->total === null ? null : round((float) $f->total, 2),
            'estado' => $f->estado ?? 'sin_generar', 'semaforo' => self::SEMAFORO[$f->estado ?? ''] ?? 'gris',
            'emitida_en' => $f->emitida_en ? CarbonImmutable::parse($f->emitida_en)->toDateString() : null, 'pagada_en' => $f->pagada_en ? CarbonImmutable::parse($f->pagada_en)->toDateString() : null,
        ]);

        return [
            'mes' => $mes->format('Y-m'), 'pagadas' => $facturas->where('semaforo', 'verde')->count(), 'pendientes' => $facturas->where('semaforo', 'rojo')->count(),
            'sin_emitir' => $facturas->where('semaforo', 'gris')->count(), 'monto_cobrado' => round($facturas->where('semaforo', 'verde')->sum('total'), 2),
            'monto_pendiente' => round($facturas->where('semaforo', 'rojo')->sum('total'), 2), 'facturas' => $facturas->values()->all(),
        ];
    }

    /** Las últimas facturas de una empresa con su semáforo (portal de empresas). */
    private function facturasDeLaEmpresa(int $empresaId, CarbonImmutable $hoy): array
    {
        $facturas = DB::table('facturas_mensuales')->where('empresa_id', $empresaId)->orderByDesc('mes')->limit(6)->get(['folio', 'mes', 'total', 'estado', 'emitida_en', 'pagada_en'])
            ->map(fn ($f) => [
                'folio' => $f->folio, 'mes' => substr((string) $f->mes, 0, 7), 'total' => round((float) $f->total, 2), 'estado' => $f->estado, 'semaforo' => self::SEMAFORO[$f->estado] ?? 'gris',
                'emitida_en' => $f->emitida_en ? CarbonImmutable::parse($f->emitida_en)->toDateString() : null, 'pagada_en' => $f->pagada_en ? CarbonImmutable::parse($f->pagada_en)->toDateString() : null,
            ]);

        return ['pagadas' => $facturas->where('semaforo', 'verde')->count(), 'pendientes' => $facturas->where('semaforo', 'rojo')->count(), 'monto_pendiente' => round($facturas->where('semaforo', 'rojo')->sum('total'), 2), 'facturas' => $facturas->values()->all()];
    }

    // ------------------------------------------------------------------ periodos

    /** @return array{0: CarbonImmutable, 1: array{inicio: CarbonImmutable, fin: CarbonImmutable}, 2: array{inicio: CarbonImmutable, fin: CarbonImmutable}} */
    private function periodos(?CarbonInterface $ahora): array
    {
        $hoy = $this->calendario->ahora($ahora);

        return [
            $hoy,
            ['inicio' => $hoy->startOfWeek()->startOfDay(), 'fin' => $hoy->endOfWeek()->startOfDay()],
            ['inicio' => $hoy->startOfMonth()->startOfDay(), 'fin' => $hoy->endOfMonth()->startOfDay()],
        ];
    }

    /** @return array<int, array{inicio: CarbonImmutable, fin: CarbonImmutable}> las últimas semanas, de la más antigua a la en curso */
    private function semanas(CarbonImmutable $semanaActual): array
    {
        $semanas = [];
        for ($i = self::SEMANAS_DE_SERIE - 1; $i >= 0; $i--) {
            $inicio = $semanaActual->subWeeks($i);
            $semanas[] = ['inicio' => $inicio, 'fin' => $inicio->addDays(6)];
        }

        return $semanas;
    }
}
