<?php

namespace Tests\Feature\Esquema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Reglas de negocio de la operación diaria (platillos, elecciones, corte, reparto, dinero, cuentas y soporte)
 * que viven en PostgreSQL. Corren contra «easylunch_pruebas»: docker compose exec app php artisan test
 */
class RestriccionesDeOperacionTest extends TestCase
{
    use RefreshDatabase;
    use VerificaRestricciones;

    // ---------------------------------------------------------------- platillos (menus)

    public function test_un_platillo_nuevo_nace_como_borrador_y_con_listas_vacias(): void
    {
        $menu = DB::table('menus')->find($this->menu());

        $this->assertSame('borrador', $menu->estado_revision);
        $this->assertSame('comida', $menu->tiempo_comida);
        $this->assertSame('{}', $menu->alergenos);
    }

    public function test_el_estado_de_revision_y_el_tiempo_de_comida_son_valores_conocidos(): void
    {
        $this->rechaza('menus_estado_revision_valido', fn () => $this->menu(null, ['estado_revision' => 'aprobado']));
        $this->rechaza('menus_tiempo_comida_valido', fn () => $this->menu(null, ['tiempo_comida' => 'merienda']));
        $this->rechaza('menus_precio_positivo', fn () => $this->menu(null, ['price' => 0]));
    }

    public function test_un_platillo_rechazado_exige_el_motivo(): void
    {
        $this->rechaza('menus_rechazo_con_motivo', fn () => $this->menu(null, ['estado_revision' => 'rechazado']));
        $this->rechaza('menus_rechazo_con_motivo', fn () => $this->menu(null, ['estado_revision' => 'rechazado', 'motivo_rechazo' => '   ']));

        $id = $this->menu(null, ['estado_revision' => 'rechazado', 'motivo_rechazo' => 'Falta el gramaje de la guarnición']);
        $this->assertSame('rechazado', DB::table('menus')->find($id)->estado_revision);
    }

    // ---------------------------------------------------------------- disponibilidad

    public function test_la_disponibilidad_es_unica_por_platillo_y_dia_y_no_acepta_negativos(): void
    {
        $menu = $this->menu();
        DB::table('disponibilidad_dia')->insert(['menu_id' => $menu, 'fecha' => '2026-11-16', 'porciones_maximas' => 70]);

        $this->rechaza('disponibilidad_dia_menu_id_fecha_unique', fn () => DB::table('disponibilidad_dia')->insert(['menu_id' => $menu, 'fecha' => '2026-11-16', 'porciones_maximas' => 50]));
        $this->rechaza('porciones_maximas_no_negativas', fn () => DB::table('disponibilidad_dia')->insert(['menu_id' => $this->menu(), 'fecha' => '2026-11-16', 'porciones_maximas' => -1]));
    }

    public function test_las_porciones_tomadas_pueden_superar_el_maximo_porque_es_una_alerta(): void
    {
        $id = DB::table('disponibilidad_dia')->insertGetId(['menu_id' => $this->menu(), 'fecha' => '2026-11-16', 'porciones_maximas' => 58, 'porciones_tomadas' => 68]);

        $this->assertSame(68, DB::table('disponibilidad_dia')->find($id)->porciones_tomadas);
    }

    // ---------------------------------------------------------------- elecciones y corte

    public function test_un_empleado_elige_un_solo_platillo_por_dia_y_tiempo_de_comida(): void
    {
        $empleado = $this->empleado();
        $this->eleccion($empleado, $this->menu());

        $this->rechaza('elecciones_empleado_empleado_id_fecha_tiempo_comida_unique', fn () => $this->eleccion($empleado, $this->menu()));
        // Otro tiempo de comida el mismo día sí se puede.
        $this->assertGreaterThan(0, $this->eleccion($empleado, $this->menu(), ['tiempo_comida' => 'cena']));
    }

    public function test_precio_y_subsidio_congelados_son_validos(): void
    {
        $empleado = $this->empleado();
        $menu = $this->menu();

        $this->rechaza('precio_positivo', fn () => $this->eleccion($empleado, $menu, ['precio_congelado' => 0]));
        $this->rechaza('subsidio_entre_0_y_100', fn () => $this->eleccion($empleado, $menu, ['porcentaje_subsidio_congelado' => 101]));
        $this->rechaza('elecciones_empleado_origen_valido', fn () => $this->eleccion($empleado, $menu, ['origen' => 'azar']));
    }

    public function test_antes_del_corte_la_eleccion_se_puede_cambiar_y_quitar(): void
    {
        $empleado = $this->empleado();
        $nuevo = $this->menu();
        $id = $this->eleccion($empleado, $this->menu());

        DB::table('elecciones_empleado')->where('id', $id)->update(['menu_id' => $nuevo]);
        $this->assertSame($nuevo, DB::table('elecciones_empleado')->find($id)->menu_id);

        DB::table('elecciones_empleado')->where('id', $id)->delete();
        $this->assertDatabaseCount('elecciones_empleado', 0);
    }

    public function test_cerrada_por_el_corte_la_eleccion_ya_no_se_cambia_ni_se_borra(): void
    {
        $id = $this->eleccion($this->empleado(), $this->menu());
        DB::table('elecciones_empleado')->where('id', $id)->update(['cerrada_en' => now()]); // así la cierra el corte

        $this->rechaza('La elección ya fue cerrada', fn () => DB::table('elecciones_empleado')->where('id', $id)->update(['menu_id' => $this->menu()]));
        $this->rechaza('La elección ya fue cerrada', fn () => DB::table('elecciones_empleado')->where('id', $id)->delete());
    }

    public function test_despues_del_corte_no_se_registran_elecciones_nuevas_para_ese_dia(): void
    {
        $empleado = $this->empleado();
        $menu = $this->menu();
        DB::table('cortes')->insert(['fecha_entrega' => '2026-11-16', 'estado' => 'correcto']);

        $this->rechaza('ya se ejecutó', fn () => $this->eleccion($empleado, $menu));
        // Otro día sigue abierto.
        $this->assertGreaterThan(0, $this->eleccion($empleado, $menu, ['fecha' => '2026-11-17']));
    }

    public function test_un_corte_fallido_o_sin_cambios_no_cierra_el_dia(): void
    {
        DB::table('cortes')->insert(['fecha_entrega' => '2026-11-16', 'estado' => 'fallido', 'mensaje' => 'No hay menú publicado para esa fecha.']);
        DB::table('cortes')->insert(['fecha_entrega' => '2026-11-17', 'estado' => 'correcto', 'sin_cambios' => true]);

        $this->assertGreaterThan(0, $this->eleccion($this->empleado(), $this->menu()));
        $this->assertGreaterThan(0, $this->eleccion($this->empleado(), $this->menu(), ['fecha' => '2026-11-17']));
    }

    public function test_el_corte_es_idempotente_solo_un_cierre_real_por_dia(): void
    {
        DB::table('cortes')->insert(['fecha_entrega' => '2026-11-16', 'estado' => 'correcto']);

        $this->rechaza('cortes_un_cierre_por_dia', fn () => DB::table('cortes')->insert(['fecha_entrega' => '2026-11-16', 'estado' => 'con_advertencias']));

        // Ejecutarlo otra vez queda como "sin cambios" y los fallidos previos o posteriores se conservan en el historial.
        DB::table('cortes')->insert(['fecha_entrega' => '2026-11-16', 'estado' => 'correcto', 'sin_cambios' => true, 'mensaje' => 'Sin cambios; ya estaba cerrado.']);
        DB::table('cortes')->insert(['fecha_entrega' => '2026-11-16', 'estado' => 'fallido', 'mensaje' => 'Error de ejemplo']);
        $this->assertSame(3, DB::table('cortes')->where('fecha_entrega', '2026-11-16')->count());
    }

    public function test_un_corte_fallido_lleva_mensaje_y_el_historial_no_se_borra(): void
    {
        $this->rechaza('cortes_fallido_con_mensaje', fn () => DB::table('cortes')->insert(['fecha_entrega' => '2026-11-16', 'estado' => 'fallido']));

        $id = DB::table('cortes')->insertGetId(['fecha_entrega' => '2026-11-16', 'estado' => 'correcto']);
        $this->rechaza('No se puede borrar un registro de cortes', fn () => DB::table('cortes')->where('id', $id)->delete());
    }

    // ---------------------------------------------------------------- órdenes y entregas

    public function test_la_orden_de_produccion_cuadra_y_no_se_duplica(): void
    {
        $comedor = $this->comedor();
        $menu = $this->menu($comedor);
        $orden = ['comedor_id' => $comedor, 'menu_id' => $menu, 'fecha' => '2026-11-16', 'porciones_totales' => 68, 'porciones_empresas' => 54, 'porciones_individuales' => 14, 'hora_recoleccion' => '10:30'];

        DB::table('ordenes_produccion')->insert($orden);
        $this->rechaza('ordenes_produccion_comedor_id_menu_id_fecha_unique', fn () => DB::table('ordenes_produccion')->insert($orden));
        $this->rechaza('total_cuadra', fn () => DB::table('ordenes_produccion')->insert(array_merge($orden, ['menu_id' => $this->menu($comedor), 'porciones_totales' => 70])));
    }

    public function test_una_empresa_recibe_una_sola_entrega_por_dia(): void
    {
        $empresa = $this->empresa();
        $this->entrega(['empresa_id' => $empresa]);

        $this->rechaza('entregas_empresa_empresa_id_fecha_unique', fn () => $this->entrega(['empresa_id' => $empresa]));
        $this->assertGreaterThan(0, $this->entrega(['empresa_id' => $empresa, 'fecha' => '2026-11-17']));
        $this->rechaza('entregas_empresa_estado_valido', fn () => $this->entrega(['estado' => 'perdida']));
    }

    // ---------------------------------------------------------------- reparto

    public function test_un_repartidor_exige_placas_y_guarda_su_ubicacion_con_postgis(): void
    {
        $this->rechaza('repartidores_placas_con_texto', fn () => DB::table('repartidores')->insert(['usuario_id' => $this->usuario(), 'numero_empleado' => 'R1', 'placas' => '  ', 'zona_base' => 'Centro']));

        $id = $this->repartidor();
        DB::statement('UPDATE repartidores SET ultima_ubicacion = ST_SetSRID(ST_MakePoint(-103.3496, 20.6597), 4326)::geography WHERE id = ?', [$id]);
        $punto = DB::selectOne('SELECT ST_Y(ultima_ubicacion::geometry) AS lat, ST_X(ultima_ubicacion::geometry) AS lng FROM repartidores WHERE id = ?', [$id]);
        $this->assertEqualsWithDelta(20.6597, $punto->lat, 0.00001);
        $this->assertEqualsWithDelta(-103.3496, $punto->lng, 0.00001);

        $this->rechaza('No se puede borrar un registro de repartidores', fn () => DB::table('repartidores')->where('id', $id)->delete());
    }

    public function test_una_ruta_nunca_lleva_mas_de_lo_que_cabe_en_el_auto(): void
    {
        $this->assertGreaterThan(0, $this->ruta(['entregas_asignadas' => 30, 'platillos_asignados' => 120])); // justo al límite

        $this->rechaza('rutas_dentro_de_la_capacidad', fn () => $this->ruta(['entregas_asignadas' => 31]));
        $this->rechaza('rutas_dentro_de_la_capacidad', fn () => $this->ruta(['platillos_asignados' => 121]));
        $this->rechaza('rutas_capacidad_positiva', fn () => $this->ruta(['capacidad_entregas' => 0]));
        $this->rechaza('rutas_ventana_valida', fn () => $this->ruta(['ventana_inicio' => '14:00', 'ventana_fin' => '12:00']));
    }

    public function test_una_parada_es_de_una_empresa_o_de_un_cliente_nunca_de_ambos_ni_de_ninguno(): void
    {
        $ruta = $this->ruta();
        $base = ['ruta_id' => $ruta, 'nombre_destinatario' => 'Destino', 'direccion' => 'Calle 1', 'hora_estimada' => '12:40'];

        DB::table('paradas')->insert($base + ['orden' => 1, 'entrega_empresa_id' => $this->entrega()]);
        DB::table('paradas')->insert($base + ['orden' => 2, 'pedido_dia_id' => $this->pedidoDia()]);

        $this->rechaza('paradas_un_solo_destino', fn () => DB::table('paradas')->insert($base + ['orden' => 3]));
        $this->rechaza('paradas_un_solo_destino', fn () => DB::table('paradas')->insert($base + ['orden' => 4, 'entrega_empresa_id' => $this->entrega(), 'pedido_dia_id' => $this->pedidoDia()]));
    }

    public function test_el_orden_de_las_paradas_no_se_repite_y_una_entrega_no_va_en_dos_paradas(): void
    {
        $ruta = $this->ruta();
        $entrega = $this->entrega();
        $base = ['ruta_id' => $ruta, 'nombre_destinatario' => 'Destino', 'direccion' => 'Calle 1', 'hora_estimada' => '12:40'];
        DB::table('paradas')->insert($base + ['orden' => 1, 'entrega_empresa_id' => $entrega]);

        $this->rechaza('paradas_ruta_id_orden_unique', fn () => DB::table('paradas')->insert($base + ['orden' => 1, 'entrega_empresa_id' => $this->entrega()]));
        $this->rechaza('paradas_entrega_empresa_unica', fn () => DB::table('paradas')->insert($base + ['orden' => 2, 'entrega_empresa_id' => $entrega]));
        $this->rechaza('paradas_orden_positivo', fn () => DB::table('paradas')->insert($base + ['orden' => 0, 'entrega_empresa_id' => $this->entrega()]));
    }

    public function test_una_incidencia_resuelta_lleva_fecha_y_una_reasignada_lleva_repartidor_y_ruta(): void
    {
        $base = ['fecha' => '2026-11-16', 'tipo' => 'repartidor_no_llego', 'descripcion' => 'No se presentó'];

        $this->rechaza('incidencias_resolucion_coherente', fn () => DB::table('incidencias')->insert($base + ['estado' => 'resuelta']));
        $this->rechaza('incidencias_reasignacion_coherente', fn () => DB::table('incidencias')->insert($base + ['estado' => 'reasignada']));
        $this->rechaza('incidencias_tipo_valido', fn () => DB::table('incidencias')->insert(array_merge($base, ['tipo' => 'ovni'])));

        DB::table('incidencias')->insert($base + ['estado' => 'reasignada', 'ruta_id' => $this->ruta(), 'repartidor_reasignado_id' => $this->repartidor()]);
        DB::table('incidencias')->insert($base + ['estado' => 'resuelta', 'resuelta_en' => now(), 'foto_url' => 'https://ejemplo.mx/foto.jpg']);
        $this->assertDatabaseCount('incidencias', 2);
    }

    // ---------------------------------------------------------------- clientes individuales

    public function test_los_paquetes_son_de_1_2_o_6_dias_y_hay_uno_activo_por_cada_uno(): void
    {
        $this->rechaza('paquetes_dias_validos', fn () => DB::table('paquetes')->insert(['nombre' => 'Paquete de 3', 'dias' => 3]));
        $this->rechaza('paquetes_descuento_entre_0_y_100', fn () => DB::table('paquetes')->insert(['nombre' => 'Regalado', 'dias' => 1, 'descuento_porcentaje' => 101]));

        DB::table('paquetes')->insert(['nombre' => 'Plan semanal', 'dias' => 6, 'descuento_porcentaje' => 12]);
        $this->rechaza('paquetes_un_activo_por_dias', fn () => DB::table('paquetes')->insert(['nombre' => 'Otro semanal', 'dias' => 6]));
    }

    public function test_un_pedido_pagado_lleva_fecha_de_pago_y_uno_por_pagar_no(): void
    {
        $this->rechaza('pedidos_pago_coherente', fn () => $this->pedido(['estado' => 'activo']));
        $this->rechaza('pedidos_pago_coherente', fn () => $this->pedido(['estado' => 'pendiente_pago', 'pagado_en' => now()]));
        $this->rechaza('pedidos_vigencia_valida', fn () => $this->pedido(['fecha_fin' => '2026-11-15']));
        $this->rechaza('pedidos_digitos_validos', fn () => $this->pedido(['estado' => 'activo', 'pagado_en' => now(), 'ultimos_digitos' => '48x1']));

        $this->assertGreaterThan(0, $this->pedido(['estado' => 'activo', 'pagado_en' => now(), 'metodo_pago' => 'Mercado Pago', 'ultimos_digitos' => '4821']));
        $this->assertGreaterThan(0, $this->pedido(['estado' => 'cancelado'])); // cancelado sin pagar
    }

    public function test_un_dia_de_pedido_entregado_lleva_su_hora_de_entrega(): void
    {
        $this->rechaza('pedido_dias_entrega_coherente', fn () => $this->pedidoDia(null, ['estado' => 'entregada']));

        $pedido = $this->pedido();
        $this->pedidoDia($pedido);
        $this->rechaza('pedido_dias_pedido_id_fecha_unique', fn () => $this->pedidoDia($pedido));
    }

    // ---------------------------------------------------------------- dinero

    private function factura(array $extra = []): array
    {
        return array_merge([
            'folio' => uniqid('EL-'), 'empresa_id' => $this->empresa(), 'mes' => '2026-10-01', 'platillos' => 100,
            'parte_empresa' => 7800, 'parte_empleados' => 5200, 'platillos_faltantes' => 0, 'diferencia_minimo' => 0, 'total' => 13000,
        ], $extra);
    }

    public function test_el_total_de_la_factura_es_consumo_mas_diferencia_por_minimo(): void
    {
        $this->assertGreaterThan(0, DB::table('facturas_mensuales')->insertGetId($this->factura()));
        // Faltaron 20 platillos a $130: 2,600 extra.
        $this->assertGreaterThan(0, DB::table('facturas_mensuales')->insertGetId($this->factura(['platillos_faltantes' => 20, 'diferencia_minimo' => 2600, 'total' => 15600])));

        $this->rechaza('facturas_mensuales_total_cuadra', fn () => DB::table('facturas_mensuales')->insert($this->factura(['total' => 13001])));
        $this->rechaza('sin_faltantes_no_hay_diferencia', fn () => DB::table('facturas_mensuales')->insert($this->factura(['diferencia_minimo' => 100, 'total' => 13100])));
    }

    public function test_hay_una_factura_por_empresa_y_mes_y_el_mes_empieza_el_dia_1(): void
    {
        $datos = $this->factura();
        DB::table('facturas_mensuales')->insert($datos);

        $this->rechaza('facturas_mensuales_empresa_id_mes_unique', fn () => DB::table('facturas_mensuales')->insert(array_merge($datos, ['folio' => uniqid('EL-')])));
        $this->rechaza('facturas_mensuales_mes_primer_dia', fn () => DB::table('facturas_mensuales')->insert($this->factura(['mes' => '2026-10-15'])));
    }

    public function test_las_fechas_de_la_factura_siguen_a_su_estado(): void
    {
        $this->rechaza('fechas_segun_estado', fn () => DB::table('facturas_mensuales')->insert($this->factura(['estado' => 'emitida'])));
        $this->rechaza('fechas_segun_estado', fn () => DB::table('facturas_mensuales')->insert($this->factura(['estado' => 'borrador', 'emitida_en' => now()])));
        $this->rechaza('fechas_segun_estado', fn () => DB::table('facturas_mensuales')->insert($this->factura(['estado' => 'pagada', 'emitida_en' => now()])));
    }

    public function test_una_factura_emitida_queda_congelada_y_solo_avanza_a_pagada(): void
    {
        $id = DB::table('facturas_mensuales')->insertGetId($this->factura());

        // En borrador se puede recalcular.
        DB::table('facturas_mensuales')->where('id', $id)->update(['platillos' => 101, 'parte_empresa' => 7878, 'parte_empleados' => 5252, 'total' => 13130]);
        DB::table('facturas_mensuales')->where('id', $id)->update(['estado' => 'emitida', 'emitida_en' => now()]);

        $this->rechaza('ya fue emitida', fn () => DB::table('facturas_mensuales')->where('id', $id)->update(['platillos' => 50, 'parte_empresa' => 3900, 'parte_empleados' => 2600, 'total' => 6500]));
        $this->rechaza('no puede regresar', fn () => DB::table('facturas_mensuales')->where('id', $id)->update(['estado' => 'borrador', 'emitida_en' => null]));

        DB::table('facturas_mensuales')->where('id', $id)->update(['estado' => 'pagada', 'pagada_en' => now()]);
        $this->rechaza('no puede regresar', fn () => DB::table('facturas_mensuales')->where('id', $id)->update(['estado' => 'emitida', 'pagada_en' => null]));
        $this->rechaza('No se puede borrar un registro de facturas_mensuales', fn () => DB::table('facturas_mensuales')->where('id', $id)->delete());
    }

    public function test_si_el_comedor_recibe_menos_del_minimo_se_le_paga_la_diferencia(): void
    {
        $comedor = $this->comedor();
        $mes = ['comedor_id' => $comedor, 'precio_por_platillo' => 49, 'platillos_minimos' => 200];

        // 142 entregados: faltan 58 × $49 = $2,842; monto = 200 × $49 = $9,800.
        DB::table('liquidaciones_comedor')->insert($mes + ['mes' => '2026-10-01', 'platillos_entregados' => 142, 'platillos_faltantes' => 58, 'diferencia_minimo' => 2842, 'monto' => 9800]);
        // Sobre el mínimo: no hay diferencia.
        DB::table('liquidaciones_comedor')->insert($mes + ['mes' => '2026-09-01', 'platillos_entregados' => 224, 'platillos_faltantes' => 0, 'diferencia_minimo' => 0, 'monto' => 10976]);

        $this->rechaza('faltantes_cuadran', fn () => DB::table('liquidaciones_comedor')->insert($mes + ['mes' => '2026-08-01', 'platillos_entregados' => 142, 'platillos_faltantes' => 0, 'diferencia_minimo' => 0, 'monto' => 6958]));
        $this->rechaza('diferencia_cuadra', fn () => DB::table('liquidaciones_comedor')->insert($mes + ['mes' => '2026-08-01', 'platillos_entregados' => 142, 'platillos_faltantes' => 58, 'diferencia_minimo' => 100, 'monto' => 9800]));
        $this->rechaza('monto_cuadra', fn () => DB::table('liquidaciones_comedor')->insert($mes + ['mes' => '2026-08-01', 'platillos_entregados' => 142, 'platillos_faltantes' => 58, 'diferencia_minimo' => 2842, 'monto' => 6958]));
        $this->rechaza('liquidaciones_comedor_comedor_id_mes_unique', fn () => DB::table('liquidaciones_comedor')->insert($mes + ['mes' => '2026-10-01', 'platillos_entregados' => 200, 'platillos_faltantes' => 0, 'diferencia_minimo' => 0, 'monto' => 9800]));
        $this->rechaza('liquidaciones_comedor_pago_coherente', fn () => DB::table('liquidaciones_comedor')->insert($mes + ['mes' => '2026-07-01', 'platillos_entregados' => 200, 'platillos_faltantes' => 0, 'diferencia_minimo' => 0, 'monto' => 9800, 'estado' => 'pagado']));
    }

    // ---------------------------------------------------------------- calendario de servicio

    public function test_el_cierre_de_empresa_lleva_empresa_y_los_dias_generales_no(): void
    {
        $empresa = $this->empresa();

        $this->rechaza('dias_sin_servicio_empresa_segun_tipo', fn () => DB::table('dias_sin_servicio')->insert(['fecha' => '2026-11-20', 'tipo' => 'cierre_empresa', 'motivo' => 'Inventario']));
        $this->rechaza('dias_sin_servicio_empresa_segun_tipo', fn () => DB::table('dias_sin_servicio')->insert(['fecha' => '2026-11-20', 'tipo' => 'festivo', 'motivo' => 'x', 'empresa_id' => $empresa]));
        $this->rechaza('dias_sin_servicio_tipo_valido', fn () => DB::table('dias_sin_servicio')->insert(['fecha' => '2026-11-20', 'tipo' => 'vacaciones', 'motivo' => 'x']));
        $this->rechaza('dias_sin_servicio_motivo_con_texto', fn () => DB::table('dias_sin_servicio')->insert(['fecha' => '2026-11-20', 'tipo' => 'festivo', 'motivo' => ' ']));
    }

    public function test_un_dia_general_se_marca_una_vez_y_una_empresa_se_cierra_una_vez_por_dia(): void
    {
        $empresa = $this->empresa();
        DB::table('dias_sin_servicio')->insert(['fecha' => '2026-11-16', 'tipo' => 'festivo', 'motivo' => 'Revolución Mexicana']);
        DB::table('dias_sin_servicio')->insert(['fecha' => '2026-11-16', 'tipo' => 'cierre_empresa', 'motivo' => 'Inventario', 'empresa_id' => $empresa]);

        $this->rechaza('dias_sin_servicio_general_unico', fn () => DB::table('dias_sin_servicio')->insert(['fecha' => '2026-11-16', 'tipo' => 'sin_servicio', 'motivo' => 'Otro']));
        $this->rechaza('dias_sin_servicio_empresa_unico', fn () => DB::table('dias_sin_servicio')->insert(['fecha' => '2026-11-16', 'tipo' => 'cierre_empresa', 'motivo' => 'Otra vez', 'empresa_id' => $empresa]));
    }

    // ---------------------------------------------------------------- cuentas y soporte

    private function invitacion(array $extra = []): array
    {
        return array_merge([
            'usuario_id' => $this->usuario(), 'correo' => 'persona@ejemplo.mx', 'rol_id' => 1, 'organizacion_tipo' => 'plataforma',
            'token_hash' => hash('sha256', uniqid('token', true)), 'enviada_en' => now(), 'vence_en' => now()->addDays(7),
        ], $extra);
    }

    public function test_una_invitacion_pertenece_a_un_comedor_a_una_empresa_o_a_la_plataforma(): void
    {
        $this->assertGreaterThan(0, DB::table('invitaciones')->insertGetId($this->invitacion()));
        $this->assertGreaterThan(0, DB::table('invitaciones')->insertGetId($this->invitacion(['organizacion_tipo' => 'comedor', 'comedor_id' => $this->comedor()])));
        $this->assertGreaterThan(0, DB::table('invitaciones')->insertGetId($this->invitacion(['organizacion_tipo' => 'empresa', 'empresa_id' => $this->empresa()])));

        $this->rechaza('invitaciones_organizacion_coherente', fn () => DB::table('invitaciones')->insert($this->invitacion(['organizacion_tipo' => 'comedor'])));
        $this->rechaza('invitaciones_organizacion_coherente', fn () => DB::table('invitaciones')->insert($this->invitacion(['organizacion_tipo' => 'empresa', 'empresa_id' => $this->empresa(), 'comedor_id' => $this->comedor()])));
        $this->rechaza('invitaciones_organizacion_coherente', fn () => DB::table('invitaciones')->insert($this->invitacion(['empresa_id' => $this->empresa()])));
    }

    public function test_el_enlace_de_invitacion_se_guarda_como_hash_unico_y_vence_despues_de_enviarse(): void
    {
        $this->rechaza('invitaciones_token_hexadecimal', fn () => DB::table('invitaciones')->insert($this->invitacion(['token_hash' => 'inv-enlace-en-claro'])));
        $this->rechaza('invitaciones_vigencia_valida', fn () => DB::table('invitaciones')->insert($this->invitacion(['vence_en' => now()->subDay()])));

        $hash = hash('sha256', 'secreto');
        DB::table('invitaciones')->insert($this->invitacion(['token_hash' => $hash]));
        $this->rechaza('invitaciones_token_hash_unique', fn () => DB::table('invitaciones')->insert($this->invitacion(['token_hash' => $hash])));
    }

    public function test_los_mensajes_de_un_ticket_tienen_autor_valido_y_texto(): void
    {
        $cliente = $this->usuario();
        $ticket = DB::table('tickets')->insertGetId(['folio' => uniqid('TKT-'), 'cliente_id' => $cliente, 'asunto' => 'No llegó mi comida']);

        DB::table('mensajes_ticket')->insert(['ticket_id' => $ticket, 'autor_id' => $cliente, 'autor_tipo' => 'cliente', 'texto' => 'Hola, ¿me ayudan?']);
        $this->rechaza('mensajes_ticket_autor_tipo_valido', fn () => DB::table('mensajes_ticket')->insert(['ticket_id' => $ticket, 'autor_id' => $cliente, 'autor_tipo' => 'robot', 'texto' => 'x']));
        $this->rechaza('mensajes_ticket_texto_con_contenido', fn () => DB::table('mensajes_ticket')->insert(['ticket_id' => $ticket, 'autor_id' => $cliente, 'autor_tipo' => 'agente', 'texto' => '   ']));
        $this->rechaza('tickets_estado_valido', fn () => DB::table('tickets')->insert(['folio' => uniqid('TKT-'), 'cliente_id' => $cliente, 'asunto' => 'x', 'estado' => 'perdido']));
    }

    public function test_la_bitacora_solo_agrega_registros_y_conserva_los_del_sistema(): void
    {
        $usuario = $this->usuario();
        $id = DB::table('registros_auditoria')->insertGetId(['usuario_id' => $usuario, 'accion' => 'aprobar', 'entidad' => 'platillo', 'entidad_id' => '1', 'detalle' => 'Aprobó el platillo Pollo teriyaki']);
        DB::table('registros_auditoria')->insert(['usuario_id' => null, 'accion' => 'ejecutar_corte', 'entidad' => 'corte', 'entidad_id' => '1', 'detalle' => 'Ejecutó el corte']);

        $this->rechaza('solo admite registros nuevos', fn () => DB::table('registros_auditoria')->where('id', $id)->update(['detalle' => 'Reescrito']));
        $this->rechaza('solo admite registros nuevos', fn () => DB::table('registros_auditoria')->where('id', $id)->delete());
    }

    public function test_si_se_elimina_el_usuario_la_bitacora_se_conserva_sin_autor(): void
    {
        $usuario = $this->usuario();
        $id = DB::table('registros_auditoria')->insertGetId(['usuario_id' => $usuario, 'accion' => 'crear', 'entidad' => 'empresa', 'entidad_id' => '1', 'detalle' => 'Creó la empresa']);

        DB::table('users')->where('id', $usuario)->delete();

        $this->assertNull(DB::table('registros_auditoria')->find($id)->usuario_id);
    }

    public function test_el_formulario_de_contacto_exige_nombre_correo_valido_y_mensaje(): void
    {
        $base = ['nombre' => 'Ana Pérez', 'correo' => 'ana@empresa.mx', 'mensaje' => 'Quiero comida para mi equipo.'];
        DB::table('mensajes_contacto')->insert($base);

        $this->rechaza('mensajes_contacto_correo_con_formato', fn () => DB::table('mensajes_contacto')->insert(array_merge($base, ['correo' => 'sin-arroba'])));
        $this->rechaza('mensajes_contacto_mensaje_con_texto', fn () => DB::table('mensajes_contacto')->insert(array_merge($base, ['mensaje' => 'corto'])));
        $this->rechaza('mensajes_contacto_nombre_con_texto', fn () => DB::table('mensajes_contacto')->insert(array_merge($base, ['nombre' => ' '])));
    }

    // ---------------------------------------------------------------- roles

    public function test_el_seeder_deja_los_siete_roles_del_negocio(): void
    {
        $this->seed(\Database\Seeders\InitialDataSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['customer', 'admin', 'driver', 'provider', 'support', 'company', 'employee'],
            DB::table('roles')->pluck('name')->all(),
        );
        // provider es el comedor: los comedores son la tabla providers.
        $this->assertSame('Comedor (proveedor)', DB::table('roles')->where('name', 'provider')->value('display_name'));
    }
}
