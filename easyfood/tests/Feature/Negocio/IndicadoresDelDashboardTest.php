<?php

namespace Tests\Feature\Negocio;

use App\Models\Configuracion;
use App\Models\Empresa;
use App\Models\Provider;
use App\Services\IndicadoresDelDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Los 10 indicadores del dashboard. «Hoy» es el jueves 12 de nov de 2026: la semana va del lunes 9 al domingo 15 y el mes es noviembre.
 * La semana anterior (2 al 8) y el mes anterior (octubre) sirven para comprobar que cada cifra cuenta solo su periodo.
 */
class IndicadoresDelDashboardTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private Provider $cocina;

    private Empresa $empresa;

    /** @var array<int, int> empleados de la empresa (ids) */
    private array $empleados = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-12 12:00');
        $this->cocina = $this->comedorConContrato(200, 49);
        $this->empresa = $this->empresaConContrato('Zapopan', ['precio_por_platillo' => 130]);
        $this->zonaDePruebas();
    }

    private function indicadores(): array
    {
        return app(IndicadoresDelDashboard::class)->paraAdministrador();
    }

    /** Una entrega a la empresa de $n platillos (uno por empleado, del mismo comedor), con la elección de cada uno. */
    private function entrega(string $fecha, int $n, string $estado = 'entregada', ?Empresa $empresa = null, float $precio = 130): int
    {
        $empresa ??= $this->empresa;
        $menu = $this->platillo($this->cocina)->id;
        $empleados = DB::table('empleados')->where('empresa_id', $empresa->id)->pluck('id')->all();
        while (count($empleados) < $n) {
            $empleados[] = $this->empleado($empresa->id);
        }
        foreach (array_slice($empleados, 0, $n) as $e) {
            $this->eleccion($e, $menu, ['fecha' => $fecha, 'precio_congelado' => $precio]);
        }

        return DB::table('entregas_empresa')->insertGetId(['empresa_id' => $empresa->id, 'fecha' => $fecha, 'platillos' => $n, 'ventana_inicio' => '12:30', 'ventana_fin' => '12:50', 'estado' => $estado]);
    }

    // ------------------------------------------------------------------ 1. platillos entregados por semana

    public function test_cuenta_los_platillos_entregados_de_la_semana_por_empresa_y_nada_mas(): void
    {
        $otra = $this->empresaConContrato('Centro');
        $this->entrega('2026-11-09', 3);
        $this->entrega('2026-11-10', 2);
        $this->entrega('2026-11-11', 4, 'programada');      // todavía no se entrega
        $this->entrega('2026-11-09', 6, 'entregada', $otra);
        $this->entrega('2026-11-05', 5);                    // la semana pasada

        $i = $this->indicadores()['platillos_entregados'];

        $this->assertSame(11, $i['semana'], '3 + 2 + 6; la programada y la semana pasada no cuentan');
        $this->assertSame([6, 5], array_column($i['por_empresa'], 'platillos'), 'por empresa, de más a menos');
        $this->assertCount(6, $i['serie']);
        $this->assertSame([5, 11], array_slice(array_column($i['serie'], 'platillos'), -2), 'semana pasada y semana en curso');
    }

    public function test_la_empresa_solo_ve_sus_platillos_con_el_detalle_por_dia(): void
    {
        $otra = $this->empresaConContrato('Centro');
        $this->entrega('2026-11-09', 3);
        $this->entrega('2026-11-10', 2);
        $this->entrega('2026-11-09', 6, 'entregada', $otra);

        $i = app(IndicadoresDelDashboard::class)->paraEmpresa($this->empresa)['platillos_entregados'];

        $this->assertSame(5, $i['semana']);
        $this->assertSame([['fecha' => '2026-11-09', 'platillos' => 3], ['fecha' => '2026-11-10', 'platillos' => 2]], $i['por_dia']);
    }

    // ------------------------------------------------------------------ 2. calificación de la entrega

    public function test_la_calificacion_de_la_entrega_es_el_promedio_simple_de_puntualidad_trato_y_empaque(): void
    {
        $entrega = $this->entrega('2026-11-09', 1);
        $otra = $this->entrega('2026-11-10', 1);
        $semanaPasada = $this->entrega('2026-11-05', 1);
        DB::table('calificaciones_entrega')->insert([
            ['calificador_id' => $this->usuario(), 'entrega_empresa_id' => $entrega, 'puntualidad' => 5, 'trato_chofer' => 4, 'empaque' => 3],
            ['calificador_id' => $this->usuario(), 'entrega_empresa_id' => $otra, 'puntualidad' => 3, 'trato_chofer' => 4, 'empaque' => 5],
            ['calificador_id' => $this->usuario(), 'entrega_empresa_id' => $semanaPasada, 'puntualidad' => 1, 'trato_chofer' => 1, 'empaque' => 1], // otra semana
        ]);

        $i = $this->indicadores()['calificacion_entrega'];

        $this->assertSame([2, 4.0, 4.0, 4.0, 4.0], [$i['calificaciones'], $i['promedio'], $i['puntualidad'], $i['trato_chofer'], $i['empaque']]);
        $this->assertSame(1.0, $i['serie'][4]['promedio'], 'la semana pasada tiene la suya');
    }

    public function test_sin_calificaciones_el_promedio_es_nulo_y_no_cero(): void
    {
        $i = $this->indicadores();

        $this->assertNull($i['calificacion_entrega']['promedio']);
        $this->assertNull($i['calificacion_menu']['promedio']);
        $this->assertSame(0, $i['calificacion_menu']['calificaciones']);
    }

    // ------------------------------------------------------------------ 3. calificación semanal del menú

    public function test_la_calificacion_del_menu_es_el_promedio_simple_de_estrellas_de_la_semana(): void
    {
        $menu = $this->platillo($this->cocina)->id;
        DB::table('calificaciones_platillo')->insert([
            ['calificador_id' => $this->usuario(), 'menu_id' => $menu, 'fecha' => '2026-11-09', 'estrellas' => 5],
            ['calificador_id' => $this->usuario(), 'menu_id' => $menu, 'fecha' => '2026-11-10', 'estrellas' => 4],
            ['calificador_id' => $this->usuario(), 'menu_id' => $menu, 'fecha' => '2026-11-11', 'estrellas' => 2],
            ['calificador_id' => $this->usuario(), 'menu_id' => $menu, 'fecha' => '2026-11-04', 'estrellas' => 1], // semana pasada
        ]);

        $i = $this->indicadores()['calificacion_menu'];

        $this->assertSame([3.67, 3], [$i['promedio'], $i['calificaciones']]);
        $this->assertSame(1.0, $i['serie'][4]['promedio']);
        $this->assertSame(3.67, $i['por_comedor'][0]['promedio']);
    }

    public function test_la_calificacion_del_menu_se_actualiza_en_cuanto_alguien_califica(): void
    {
        $menu = $this->platillo($this->cocina)->id;
        DB::table('calificaciones_platillo')->insert(['calificador_id' => $this->usuario(), 'menu_id' => $menu, 'fecha' => '2026-11-09', 'estrellas' => 4]);
        $this->assertSame(4.0, app(IndicadoresDelDashboard::class)->calificacionDelMenu()['promedio']);

        DB::table('calificaciones_platillo')->insert(['calificador_id' => $this->usuario(), 'menu_id' => $menu, 'fecha' => '2026-11-10', 'estrellas' => 2]);

        $this->assertSame(3.0, app(IndicadoresDelDashboard::class)->calificacionDelMenu()['promedio'], 'sin cachés: se calcula al pedirlo');
    }

    // ------------------------------------------------------------------ 4. paquete más popular

    public function test_el_paquete_mas_popular_es_el_de_mas_pedidos_pagados_del_mes(): void
    {
        $uno = DB::table('paquetes')->insertGetId(['nombre' => 'Un día', 'dias' => 1]);
        $dos = DB::table('paquetes')->insertGetId(['nombre' => 'Dos días', 'dias' => 2]);
        $seis = DB::table('paquetes')->insertGetId(['nombre' => 'Semana', 'dias' => 6]);
        $pagado = fn (int $paquete, string $cuando) => $this->pedido(['paquete_id' => $paquete, 'estado' => 'recibido', 'pagado_en' => $cuando, 'vence_pago_en' => null]);
        $pagado($seis, '2026-11-03 10:00:00-06');
        $pagado($seis, '2026-11-05 10:00:00-06');
        $pagado($dos, '2026-11-04 10:00:00-06');
        $pagado($uno, '2026-10-20 10:00:00-06');               // el mes pasado
        $this->pedido(['paquete_id' => $uno]);                  // sin pagar: no cuenta

        $i = $this->indicadores()['paquete_popular'];

        $this->assertSame(['Semana', 6, 2], [$i['paquete']['nombre'], $i['paquete']['dias'], $i['paquete']['pedidos']]);
        $this->assertSame([2, 1, 0], array_column($i['paquetes'], 'pedidos'));
        $this->assertSame('2026-11', $i['mes']);
    }

    public function test_si_empatan_gana_el_paquete_de_menos_dias_y_sin_pedidos_no_hay_popular(): void
    {
        $this->assertNull($this->indicadores()['paquete_popular']['paquete']);

        $uno = DB::table('paquetes')->insertGetId(['nombre' => 'Un día', 'dias' => 1]);
        $seis = DB::table('paquetes')->insertGetId(['nombre' => 'Semana', 'dias' => 6]);
        foreach ([$uno, $seis] as $p) {
            $this->pedido(['paquete_id' => $p, 'estado' => 'recibido', 'pagado_en' => '2026-11-03 10:00:00-06', 'vence_pago_en' => null]);
        }

        $this->assertSame(1, $this->indicadores()['paquete_popular']['paquete']['dias']);
    }

    // ------------------------------------------------------------------ 5. ahorro estimado por empleado

    public function test_el_ahorro_es_lo_gastado_en_platillos_entregados_por_el_porcentaje_de_sobreprecio_de_las_apps(): void
    {
        $this->entrega('2026-11-09', 2);                    // 2 empleados × $130
        $this->entrega('2026-11-10', 1);                    // 1 empleado × $130
        $this->entrega('2026-11-11', 2, 'programada');      // no entregada: no cuenta
        $this->entrega('2026-10-27', 2);                    // el mes pasado

        $i = $this->indicadores()['ahorro_estimado'];

        $this->assertSame(30.0, $i['porcentaje_sobreprecio'], 'arranca en 30 %');
        $this->assertSame(2, $i['empleados']);
        $this->assertSame(117.0, $i['ahorro_total'], '3 platillos × $130 × 30 %');
        $this->assertSame(58.5, $i['por_empleado_promedio']);
        $this->assertSame([['empresa' => $this->empresa->nombre, 'empleados' => 2, 'ahorro_promedio' => 58.5, 'ahorro_total' => 117.0]],
            array_map(fn ($e) => array_diff_key($e, ['empresa_id' => 1]), $i['por_empresa']));
    }

    public function test_el_porcentaje_de_ahorro_se_cambia_en_la_configuracion(): void
    {
        $this->entrega('2026-11-09', 1);
        Configuracion::findOrFail('ahorro_apps_porcentaje')->update(['valor' => '50']);

        $i = $this->indicadores()['ahorro_estimado'];

        $this->assertSame([50.0, 65.0], [$i['porcentaje_sobreprecio'], $i['ahorro_total']]);
    }

    public function test_la_empresa_ve_el_ahorro_de_cada_uno_de_sus_empleados(): void
    {
        $otra = $this->empresaConContrato('Centro');
        $this->entrega('2026-11-09', 2);
        $this->entrega('2026-11-10', 1);
        $this->entrega('2026-11-09', 5, 'entregada', $otra);

        $i = app(IndicadoresDelDashboard::class)->paraEmpresa($this->empresa)['ahorro_estimado'];

        $this->assertSame([2, 117.0], [$i['empleados'], $i['ahorro_total']]);
        $this->assertSame([2, 1], array_column($i['por_empleado'], 'platillos'), 'de más a menos ahorro');
        $this->assertArrayNotHasKey('por_empresa', $i);
    }

    // ------------------------------------------------------------------ 6. incidencias o quejas semanales

    public function test_cuenta_las_quejas_de_comida_fria_empaque_danado_y_entrega_tardia_de_la_semana(): void
    {
        $inc = fn (string $tipo, string $fecha, string $estado = 'abierta', ?int $entrega = null) => DB::table('incidencias')->insert(
            ['fecha' => $fecha, 'tipo' => $tipo, 'descripcion' => 'x', 'estado' => $estado, 'entrega_empresa_id' => $entrega] + ($estado === 'resuelta' ? ['resuelta_en' => '2026-11-10 15:00:00-06'] : []),
        );
        $inc('comida_fria', '2026-11-09');
        $inc('comida_fria', '2026-11-10', 'resuelta');
        $inc('platillo_danado', '2026-11-11');
        $inc('entrega_tardia', '2026-11-11');
        $inc('empresa_cerrada', '2026-11-11');   // no es una queja de servicio
        $inc('comida_fria', '2026-11-03');       // semana pasada

        $i = $this->indicadores()['incidencias'];

        $this->assertSame([4, 3], [$i['semana']['total'], $i['semana']['abiertas']]);
        $this->assertSame(['Comida fría' => 2, 'Empaque dañado' => 1, 'Entrega tardía' => 1], array_column($i['semana']['por_tipo'], 'cantidad', 'etiqueta'));
        $this->assertSame([1, 4], array_slice(array_column($i['serie'], 'total'), -2));
    }

    public function test_la_empresa_solo_ve_las_quejas_de_sus_entregas(): void
    {
        $otra = $this->empresaConContrato('Centro');
        $mia = $this->entrega('2026-11-09', 1);
        $ajena = $this->entrega('2026-11-09', 1, 'entregada', $otra);
        foreach ([$mia, $mia, $ajena] as $e) {
            DB::table('incidencias')->insert(['fecha' => '2026-11-09', 'tipo' => 'comida_fria', 'descripcion' => 'x', 'entrega_empresa_id' => $e]);
        }

        $this->assertSame(2, app(IndicadoresDelDashboard::class)->paraEmpresa($this->empresa)['incidencias']['semana']['total']);
        $this->assertSame(3, $this->indicadores()['incidencias']['semana']['total']);
    }

    // ------------------------------------------------------------------ 7. nuevos contratos B2B

    public function test_cuenta_las_empresas_cuyo_primer_contrato_empezo_este_mes_y_no_las_renovaciones(): void
    {
        $nueva = $this->empresaConContrato('Centro', ['vigencia_inicio' => '2026-11-02', 'vigencia_fin' => '2027-05-01', 'compra_minima_mensual' => 150]);
        $renovada = $this->empresaConContrato('Centro', ['vigencia_inicio' => '2026-05-01', 'vigencia_fin' => '2026-10-31']);
        DB::table('contratos_empresa')->where('empresa_id', $renovada->id)->update(['activo' => false, 'desactivado_en' => '2026-11-01 00:00:00-06']); // el contrato anterior se desactiva al renovar
        DB::table('contratos_empresa')->insert(['empresa_id' => $renovada->id, 'porcentaje_subsidio' => 50, 'compra_minima_mensual' => 100, 'precio_por_platillo' => 130,
            'vigencia_inicio' => '2026-11-01', 'vigencia_fin' => '2027-04-30', 'hora_entrega' => '12:40', 'ventana_entrega_inicio' => '12:30', 'ventana_entrega_fin' => '12:50']);
        $mesPasado = $this->empresaConContrato('Centro', ['vigencia_inicio' => '2026-10-10', 'vigencia_fin' => '2027-04-09']);

        $i = $this->indicadores()['contratos_nuevos'];

        $this->assertSame([1, 1, '2026-11'], [$i['cantidad'], $i['mes_anterior'], $i['mes']]);
        $this->assertSame([$nueva->nombre, '2026-11-02', 6, 150], [$i['contratos'][0]['empresa'], $i['contratos'][0]['vigencia_inicio'], $i['contratos'][0]['meses'], $i['contratos'][0]['compra_minima_mensual']]);
        $this->assertNotNull($mesPasado);
    }

    // ------------------------------------------------------------------ 8. balance semanal de utilidad

    public function test_el_balance_compara_ingresos_contra_el_pago_a_cocinas_y_los_costos_operativos(): void
    {
        $this->entrega('2026-11-09', 3);   // 3 × $130 = 390 de ingreso; 3 × $49 = 147 de cocina
        $this->entrega('2026-11-10', 2);   // 2 × $130 = 260; 2 × $49 = 98
        $this->entrega('2026-11-11', 4, 'programada');
        $cliente = $this->pedido(['estado' => 'activo', 'pagado_en' => '2026-11-02 10:00:00-06', 'vence_pago_en' => null]);
        DB::table('paquetes')->where('dias', 2)->update(['descuento_porcentaje' => 10]);
        $dia = $this->pedidoDia($cliente, ['fecha' => '2026-11-09', 'estado' => 'entregada', 'entregada_en' => '2026-11-09 13:00:00-06']);
        foreach (['comida', 'cena'] as $tiempo) {
            $this->pedidoDiaPlatillo($dia, ['tiempo_comida' => $tiempo, 'menu_id' => $this->platillo($this->cocina, $tiempo)->id, 'precio_congelado' => 100]);
        }   // 2 × $100 con 10 % de descuento = 180; 2 × $49 = 98 de cocina
        $reg = $this->usuario();
        foreach ([
            ['fecha' => '2026-11-10', 'categoria' => 'reparto', 'descripcion' => 'Combustible', 'monto' => 100],
            ['fecha' => '2026-11-11', 'categoria' => 'empaque', 'descripcion' => 'Charolas', 'monto' => 50.5],
            ['fecha' => '2026-11-11', 'categoria' => 'otro', 'descripcion' => 'Anulado', 'monto' => 999, 'activo' => false, 'desactivado_en' => '2026-11-11 10:00:00-06'],
            ['fecha' => '2026-11-03', 'categoria' => 'reparto', 'descripcion' => 'Semana pasada', 'monto' => 70],
        ] as $costo) {
            DB::table('costos_operativos')->insert($costo + ['registrado_por' => $reg]);
        }

        $b = $this->indicadores()['balance']['semana'];

        $this->assertSame(650.0, $b['ingresos_empresas']);
        $this->assertSame(180.0, $b['ingresos_clientes']);
        $this->assertSame(830.0, $b['ingresos']);
        $this->assertSame(343.0, $b['costo_maquila'], '(3 + 2 + 2) platillos × $49');
        $this->assertSame(150.5, $b['costos_operativos'], 'el anulado y el de otra semana no cuentan');
        $this->assertSame(493.5, $b['costos']);
        $this->assertSame(336.5, $b['utilidad'], 'ingresos − costos');
    }

    public function test_el_balance_trae_la_serie_de_6_semanas_y_la_utilidad_puede_ser_negativa(): void
    {
        DB::table('costos_operativos')->insert(['fecha' => '2026-11-03', 'categoria' => 'nomina', 'descripcion' => 'Semana pasada', 'monto' => 500, 'registrado_por' => $this->usuario()]);

        $serie = $this->indicadores()['balance']['serie'];

        $this->assertCount(6, $serie);
        $this->assertSame('2026-10-05', $serie[0]['semana_inicio']);
        $this->assertSame(['2026-11-02', -500.0], [$serie[4]['semana_inicio'], $serie[4]['utilidad']]);
    }

    // ------------------------------------------------------------------ 9. costo directo de maquila

    public function test_el_costo_de_maquila_avisa_si_alguna_cocina_pasa_del_limite_de_49(): void
    {
        $this->entrega('2026-11-09', 2);
        $this->assertTrue($this->indicadores()['costo_maquila']['en_limite']);

        $cara = $this->comedorConContrato(200, 52);

        $m = $this->indicadores()['costo_maquila'];
        $this->assertSame(49.0, $m['limite']);
        $this->assertFalse($m['en_limite']);
        $this->assertSame([true, false], array_column($m['cocinas'], 'en_limite'), 'la de $49 está bien, la de $52 no');
        $this->assertSame(49.0, $m['costo_promedio_semana']);
        $this->assertNotNull($cara);
    }

    public function test_el_limite_del_costo_de_maquila_se_cambia_en_la_configuracion(): void
    {
        $this->comedorConContrato(200, 52);
        $this->assertFalse($this->indicadores()['costo_maquila']['en_limite']);

        Configuracion::findOrFail('costo_maquila_limite')->update(['valor' => '55']);

        $this->assertTrue($this->indicadores()['costo_maquila']['en_limite']);
    }

    // ------------------------------------------------------------------ 10. estatus de facturación B2B

    public function test_el_semaforo_de_facturacion_es_verde_si_se_pago_rojo_si_esta_pendiente_y_gris_si_no_se_ha_emitido(): void
    {
        $pagada = $this->empresaConContrato('Zapopan');
        $pendiente = $this->empresaConContrato('Centro');
        $borrador = $this->empresaConContrato('Centro');
        $sinFactura = $this->empresaConContrato('Centro');
        $factura = fn (Empresa $e, string $estado, float $total, array $extra = []) => DB::table('facturas_mensuales')->insert([
            'folio' => uniqid('EL-'), 'empresa_id' => $e->id, 'mes' => '2026-10-01', 'platillos' => 10, 'parte_empresa' => $total, 'parte_empleados' => 0, 'total' => $total, 'estado' => $estado,
        ] + $extra);
        $factura($pagada, 'pagada', 1000, ['emitida_en' => '2026-11-01 10:00:00-06', 'pagada_en' => '2026-11-05 10:00:00-06']);
        $factura($pendiente, 'emitida', 2500, ['emitida_en' => '2026-11-01 10:00:00-06']);
        $factura($borrador, 'borrador', 400);
        $this->empresa->update([]); // la empresa del escenario también tiene contrato pero no factura

        $f = $this->indicadores()['facturacion_b2b'];

        $this->assertSame('2026-10', $f['mes'], 'el mes anterior, que es el que ya se cobra');
        $porEmpresa = array_column($f['facturas'], 'semaforo', 'empresa');
        $this->assertSame('verde', $porEmpresa[$pagada->nombre]);
        $this->assertSame('rojo', $porEmpresa[$pendiente->nombre]);
        $this->assertSame('gris', $porEmpresa[$borrador->nombre]);
        $this->assertSame('gris', $porEmpresa[$sinFactura->nombre]);
        $this->assertSame([1, 1, 3], [$f['pagadas'], $f['pendientes'], $f['sin_emitir']]);
        $this->assertSame([1000.0, 2500.0], [$f['monto_cobrado'], $f['monto_pendiente']]);
        $this->assertSame('sin_generar', array_column($f['facturas'], 'estado', 'empresa')[$sinFactura->nombre]);
    }

    public function test_la_empresa_ve_el_semaforo_de_sus_propias_facturas(): void
    {
        $otra = $this->empresaConContrato('Centro');
        foreach ([[$this->empresa, 'pagada'], [$otra, 'emitida']] as [$e, $estado]) {
            DB::table('facturas_mensuales')->insert(['folio' => uniqid('EL-'), 'empresa_id' => $e->id, 'mes' => '2026-10-01', 'platillos' => 1, 'parte_empresa' => 100, 'parte_empleados' => 0, 'total' => 100, 'estado' => $estado,
                'emitida_en' => '2026-11-01 10:00:00-06', 'pagada_en' => $estado === 'pagada' ? '2026-11-03 10:00:00-06' : null]);
        }

        $f = app(IndicadoresDelDashboard::class)->paraEmpresa($this->empresa)['facturacion'];

        $this->assertSame(['verde'], array_column($f['facturas'], 'semaforo'));
        $this->assertSame([1, 0], [$f['pagadas'], $f['pendientes']]);
    }

    // ------------------------------------------------------------------ periodos y resumen

    public function test_la_semana_va_de_lunes_a_domingo_y_el_domingo_ya_es_de_la_semana_siguiente_al_lunes(): void
    {
        $this->entrega('2026-11-15', 2); // domingo 15 de la semana en curso (9–15)

        $this->assertSame(['2026-11-09', '2026-11-15', 2], [$this->indicadores()['periodo']['semana_inicio'], $this->indicadores()['periodo']['semana_fin'], $this->indicadores()['platillos_entregados']['semana']]);

        $this->ahoraEs('2026-11-16 09:00'); // lunes: empieza otra semana
        $this->assertSame([0, '2026-11-16'], [$this->indicadores()['platillos_entregados']['semana'], $this->indicadores()['periodo']['semana_inicio']]);
    }

    public function test_el_resumen_del_dia_cuenta_las_entregas_de_hoy_por_estado_y_los_ingresos_de_la_semana(): void
    {
        $this->entrega('2026-11-12', 3, 'entregada');
        $this->entrega('2026-11-12', 2, 'en_ruta', $this->empresaConContrato('Centro'));
        $this->entrega('2026-11-09', 1);

        $r = app(IndicadoresDelDashboard::class)->resumen();

        $this->assertSame([2, 1, 1], [$r['entregas_hoy'], $r['entregas_completadas'], $r['en_ruta']]);
        $this->assertSame(['En ruta', 'Entregadas'], array_column($r['entregas_por_estado'], 'estado'));
        $this->assertSame(520.0, $r['ingresos_semana'], '(3 de hoy + 1 del lunes) entregados × $130; los 2 en ruta todavía no');
        $this->assertCount(7, $r['pedidos_por_dia']);
    }
}
