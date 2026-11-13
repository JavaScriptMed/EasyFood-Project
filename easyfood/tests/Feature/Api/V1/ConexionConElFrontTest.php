<?php

namespace Tests\Feature\Api\V1;

use App\Models\ContratoComedor;
use App\Models\ContratoEmpresa;
use App\Models\EleccionEmpleado;
use App\Models\FacturaMensual;
use App\Models\RegistroAuditoria;
use App\Services\EjecutorDelCorte;
use App\Services\EleccionDePlatillos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** Lo que el front necesita para mostrar sus portales: perfil de la empresa, consumo, pedidos de mañana, vista previa del corte, reportes y renovación de contratos. */
class ConexionConElFrontTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-11 10:00'); // miércoles; la próxima entrega es el jueves 12
    }

    private function eleccion($empleado, $menu, string $fecha, string $origen = 'manual'): void
    {
        DB::table('elecciones_empleado')->insert([
            'empleado_id' => $empleado->id, 'fecha' => $fecha, 'tiempo_comida' => $menu->tiempo_comida, 'menu_id' => $menu->id,
            'precio_congelado' => 130, 'porcentaje_subsidio_congelado' => 60, 'origen' => $origen,
        ]);
    }

    // ------------------------------------------------------------------ lecturas para cualquier rol

    public function test_la_configuracion_de_operacion_se_puede_leer_con_cualquier_rol_pero_no_cambiar(): void
    {
        foreach (['customer', 'company', 'provider', 'employee', 'support', 'admin'] as $rol) {
            $this->comoRol($rol);
            $this->getJson('/api/v1/configuracion/operacion')->assertOk()->assertJsonPath('hora_corte', '18:00')->assertJsonPath('dias_entrega', 'lunes_sabado');
        }
        $this->comoRol('company');
        $this->putJson('/api/v1/admin/configuracion', [])->assertStatus(403);
    }

    public function test_sin_sesion_la_configuracion_y_los_paquetes_piden_iniciar_sesion(): void
    {
        $this->getJson('/api/v1/configuracion/operacion')->assertStatus(401)->assertJsonPath('codigo', 'no_autenticado');
        $this->getJson('/api/v1/paquetes')->assertStatus(401);
    }

    public function test_los_paquetes_los_ve_cualquier_rol(): void
    {
        \App\Models\Paquete::create(['nombre' => 'Semana', 'dias' => 6, 'descuento_porcentaje' => 12]);

        $this->comoRol('admin');
        $this->getJson('/api/v1/paquetes')->assertOk()->assertJsonPath('0.nombre', 'Semana')->assertJsonPath('0.descuento_porcentaje', 12);
    }

    // ------------------------------------------------------------------ portal de empresas

    public function test_la_empresa_ve_su_propio_perfil_con_contrato_y_empleados_activos(): void
    {
        $empresa = $this->comoEmpresa('Zapopan', ['porcentaje_subsidio' => 70, 'vigencia_fin' => '2026-12-15']);
        $this->empleadoDe($empresa);
        $this->empleadoDe($this->empresaConContrato()); // de otra empresa

        $this->getJson('/api/v1/empresa/perfil')->assertOk()->assertJsonPath('id', $empresa->id)->assertJsonPath('nombre', $empresa->nombre)
            ->assertJsonPath('contrato.porcentaje_subsidio', 70)->assertJsonPath('total_empleados', 1)->assertJsonPath('estado_contrato', 'activo')
            ->assertJsonPath('contacto.correo', $empresa->usuario->email);
    }

    public function test_el_perfil_de_empresa_es_solo_para_empresas_y_quien_no_tiene_una_recibe_403(): void
    {
        $this->comoRol('company');
        $this->getJson('/api/v1/empresa/perfil')->assertStatus(403)->assertJsonPath('mensaje', 'Tu cuenta no está asociada a ninguna empresa.');
        $this->comoRol('customer');
        $this->getJson('/api/v1/empresa/perfil')->assertStatus(403);
    }

    public function test_el_consumo_diario_cuenta_las_elecciones_por_dia_del_mes_y_solo_de_la_empresa(): void
    {
        $empresa = $this->comoEmpresa();
        $a = $this->empleadoDe($empresa);
        $b = $this->empleadoDe($empresa);
        $otro = $this->empleadoDe($this->empresaConContrato());
        $menu = $this->platillo();
        foreach ([[$a, '2026-11-03'], [$b, '2026-11-03'], [$a, '2026-11-05'], [$a, '2026-10-30'], [$otro, '2026-11-03']] as [$e, $f]) {
            $this->eleccion($e, $menu, $f);
        }

        $this->getJson('/api/v1/empresa/consumo?mes=2026-11')->assertOk()->assertExactJson([['fecha' => '2026-11-03', 'platillos' => 2], ['fecha' => '2026-11-05', 'platillos' => 1]]);
        $this->getJson('/api/v1/empresa/consumo')->assertOk()->assertJsonCount(2);
        $this->getJson('/api/v1/empresa/consumo?mes=2026-10')->assertOk()->assertJsonCount(1);
        $this->getJson('/api/v1/empresa/consumo?mes=nov')->assertStatus(422);
    }

    public function test_el_administrador_ve_el_consumo_de_cualquier_empresa(): void
    {
        $empresa = $this->empresaConContrato();
        $this->eleccion($this->empleadoDe($empresa), $this->platillo(), '2026-11-03');
        $this->comoRol('admin');

        $this->getJson("/api/v1/admin/empresas/{$empresa->id}/consumo?mes=2026-11")->assertOk()->assertExactJson([['fecha' => '2026-11-03', 'platillos' => 1]]);
        $this->getJson('/api/v1/admin/empresas/999999/consumo')->assertStatus(404);
    }

    public function test_pedidos_de_manana_agrupa_por_platillo_y_lista_a_quienes_faltan(): void
    {
        $empresa = $this->comoEmpresa();
        [$ana, $beto, $carla, $diego] = [$this->empleadoDe($empresa), $this->empleadoDe($empresa), $this->empleadoDe($empresa), $this->empleadoDe($empresa)];
        $diego->desactivar();
        $mole = $this->platillo(null, 'comida', ['name' => 'Mole']);
        $caldo = $this->platillo(null, 'comida', ['name' => 'Caldo']);
        $this->eleccion($ana, $mole, '2026-11-12');
        $this->eleccion($beto, $mole, '2026-11-12');
        $this->eleccion($carla, $caldo, '2026-11-12');
        $this->eleccion($ana, $caldo, '2026-11-14'); // otro día: no cuenta

        $r = $this->getJson('/api/v1/empresa/pedidos-de-manana')->assertOk()->json();

        $this->assertSame('2026-11-12', $r['fecha']);
        $this->assertSame(3, $r['total']);
        $this->assertSame(['Mole', 'Caldo'], array_column($r['grupos'], 'platillo_nombre'), 'de mayor a menor');
        $this->assertSame(2, count($r['grupos'][0]['personas']));
        $this->assertSame([], $r['pendientes'], 'Diego está desactivado y los demás ya eligieron');
    }

    public function test_pedidos_de_manana_marca_pendientes_a_los_activos_sin_eleccion(): void
    {
        $empresa = $this->comoEmpresa();
        $ana = $this->empleadoDe($empresa);
        $beto = $this->empleadoDe($empresa);
        $this->eleccion($ana, $this->platillo(), '2026-11-12');

        $r = $this->getJson('/api/v1/empresa/pedidos-de-manana')->assertOk()->json();

        $this->assertSame([$beto->id], array_column($r['pendientes'], 'empleado_id'));
    }

    // ------------------------------------------------------------------ factura / consumo del mes en curso

    private function entregado($empresa, string $fecha, $menu): void
    {
        $this->eleccion($this->empleadoDe($empresa), $menu, $fecha);
        DB::table('entregas_empresa')->insertOrIgnore(['empresa_id' => $empresa->id, 'fecha' => $fecha, 'platillos' => 1, 'ventana_inicio' => '12:30', 'ventana_fin' => '12:50', 'estado' => 'entregada']);
    }

    public function test_la_vista_previa_calcula_el_mes_en_curso_hasta_hoy_sin_guardar_nada(): void
    {
        $empresa = $this->comoEmpresa('Zapopan', ['compra_minima_mensual' => 10, 'precio_por_platillo' => 130, 'porcentaje_subsidio' => 60]);
        $menu = $this->platillo();
        foreach (['2026-11-03', '2026-11-04', '2026-11-05'] as $f) {
            $this->entregado($empresa, $f, $menu);
        }

        $r = $this->getJson('/api/v1/empresa/facturas/vista-previa?mes=2026-11')->assertOk()->json();

        $this->assertEquals([3, 234, 156, 7, 910, 1300], [$r['platillos'], $r['parte_empresa'], $r['parte_empleados'], $r['platillos_faltantes'], $r['diferencia_minimo'], $r['total']]);
        $this->assertTrue($r['es_vista_previa']);
        $this->assertSame('borrador', $r['estado']);
        $this->assertCount(3, $r['lineas']);
        $this->assertEquals(390, $r['importe_consumo']);
        $this->assertSame(0, FacturaMensual::count(), 'no se guarda nada');
    }

    public function test_si_la_factura_del_mes_ya_esta_emitida_la_vista_previa_devuelve_esa_congelada(): void
    {
        $empresa = $this->comoEmpresa('Zapopan', ['compra_minima_mensual' => 1]);
        $this->entregado($empresa, '2026-10-05', $this->platillo());
        $this->ahoraEs('2026-11-05 09:00');
        $factura = app(\App\Services\GeneradorDeFacturas::class)->generar($empresa, '2026-10-01');
        app(\App\Services\GeneradorDeFacturas::class)->emitir($factura);
        $this->entregado($empresa, '2026-10-06', $this->platillo()); // llega después: la emitida no cambia

        $r = $this->getJson('/api/v1/empresa/facturas/vista-previa?mes=2026-10')->assertOk()->json();

        $this->assertSame([1, 'emitida', $factura->folio], [$r['platillos'], $r['estado'], $r['folio']]);
        $this->assertArrayNotHasKey('es_vista_previa', $r);
    }

    public function test_la_vista_previa_no_acepta_meses_futuros_ni_mira_otras_empresas(): void
    {
        $this->comoEmpresa('Zapopan', ['compra_minima_mensual' => 5]);
        $otra = $this->empresaConContrato();
        $this->entregado($otra, '2026-11-03', $this->platillo());

        $this->getJson('/api/v1/empresa/facturas/vista-previa?mes=2026-12')->assertStatus(422)->assertJsonPath('codigo', 'mes_futuro');
        $this->getJson('/api/v1/empresa/facturas/vista-previa?mes=2026-11')->assertOk()->assertJsonPath('platillos', 0);
        $this->getJson('/api/v1/empresa/facturas/vista-previa')->assertStatus(422);
    }

    public function test_un_mes_sin_contrato_responde_422_y_no_error_500(): void
    {
        $this->comoEmpresa('Zapopan', ['vigencia_inicio' => '2026-11-01']);

        $this->getJson('/api/v1/empresa/facturas/vista-previa?mes=2026-10')->assertStatus(422)->assertJsonPath('codigo', 'sin_contrato');
    }

    // ------------------------------------------------------------------ corte

    public function test_la_vista_previa_del_corte_cuenta_empleados_activos_y_quienes_no_han_elegido(): void
    {
        $this->comoRol('admin');
        $empresa = $this->empresaConContrato();
        $ana = $this->empleadoDe($empresa);
        $this->empleadoDe($empresa);
        $this->empleadoDe($empresa)->desactivar();
        $cerrada = $this->empresaConContrato();
        $this->empleadoDe($cerrada);
        DB::table('dias_sin_servicio')->insert(['fecha' => '2026-11-12', 'tipo' => 'cierre_empresa', 'motivo' => 'Cierre', 'empresa_id' => $cerrada->id]);
        $this->eleccion($ana, $this->platillo(), '2026-11-12');

        $this->getJson('/api/v1/admin/cortes/vista-previa')->assertOk()->assertExactJson([
            'fecha_entrega' => '2026-11-12', 'hora_corte' => '18:00', 'regla_sin_eleccion' => 'ultimo_platillo', 'ya_cerrado' => false, 'empleados_activos' => 2, 'sin_elegir' => 1,
        ]);
    }

    public function test_ejecutar_el_corte_devuelve_el_detalle_que_muestra_el_panel(): void
    {
        $this->comoRol('admin');
        $this->repartidorEn('Zapopan');
        $empresa = $this->empresaConContrato('Zapopan');
        $ana = $this->empleadoDe($empresa);
        $beto = $this->empleadoDe($empresa);
        $comedor = $this->comedorConContrato();
        $comedor->update(['commercial_name' => 'Cocina Norte']);
        $menu = $this->platillo($comedor, 'comida', ['name' => 'Mole']);
        $this->disponible($menu, '2026-11-12');
        DB::table('elecciones_empleado')->insert(['empleado_id' => $beto->id, 'fecha' => '2026-11-10', 'tiempo_comida' => 'comida', 'menu_id' => $menu->id,
            'precio_congelado' => 130, 'porcentaje_subsidio_congelado' => 60, 'origen' => 'manual', 'cerrada_en' => '2026-11-09 18:00:00']);
        app(EleccionDePlatillos::class)->elegir($ana, '2026-11-12', 'comida', $menu);

        $r = $this->postJson('/api/v1/admin/cortes/ejecutar')->assertOk()->json();

        $this->assertSame(['2026-11-12', false, 2], [$r['fecha_entrega'], $r['sin_cambios'], $r['elecciones_cerradas']]);
        $this->assertSame([['empleado_id' => $beto->id, 'nombre' => $beto->usuario->name, 'empresa_nombre' => $empresa->nombre, 'platillo_nombre' => 'Mole']], $r['empleados_por_defecto']);
        $this->assertSame([['comedor_id' => $comedor->id, 'comedor_nombre' => 'Cocina Norte', 'ordenes' => 1, 'porciones' => 2]], $r['ordenes_por_comedor']);
        $this->assertSame([$empresa->id, 2, '12:40'], [$r['entregas_por_empresa'][0]['empresa_id'], $r['entregas_por_empresa'][0]['platillos'], $r['entregas_por_empresa'][0]['hora_entrega']]);
        $this->assertNotNull($r['entregas_por_empresa'][0]['ruta_id']);
        $this->assertSame(['Zapopan', 1, 2], [$r['rutas'][0]['zona'], $r['rutas'][0]['entregas'], $r['rutas'][0]['platillos']]);
        $this->assertSame(0, $r['empleados_sin_entrega']);
        $this->assertSame(1, $r['corte']['empleados_por_defecto']);
    }

    public function test_repetir_el_corte_devuelve_el_mismo_detalle_marcado_como_sin_cambios(): void
    {
        $this->comoRol('admin');
        $menu = $this->platillo();
        $this->disponible($menu, '2026-11-12');
        $this->eleccion($this->empleadoDe($this->empresaConContrato()), $menu, '2026-11-12');
        $primero = $this->postJson('/api/v1/admin/cortes/ejecutar', ['fecha_entrega' => '2026-11-12'])->assertOk()->json();

        $repetido = $this->postJson('/api/v1/admin/cortes/ejecutar', ['fecha_entrega' => '2026-11-12'])->assertOk()->json();

        $this->assertTrue($repetido['sin_cambios']);
        $this->assertSame($primero['ordenes_por_comedor'], $repetido['ordenes_por_comedor']);
        $this->assertSame($primero['entregas_por_empresa'], $repetido['entregas_por_empresa']);
    }

    // ------------------------------------------------------------------ reportes y menú

    public function test_el_reporte_de_proveedor_costea_cada_orden_con_el_precio_del_contrato(): void
    {
        $this->comoRol('admin');
        $comedor = $this->comedorNuevo();
        ContratoComedor::create(['comedor_id' => $comedor->id, 'precio_por_platillo' => 49, 'compra_minima_mensual' => 200, 'vigencia_inicio' => '2026-07-01', 'vigencia_fin' => '2027-01-31']);
        $menu = $this->platillo($comedor, 'comida', ['name' => 'Mole']);
        DB::table('ordenes_produccion')->insert(['comedor_id' => $comedor->id, 'menu_id' => $menu->id, 'fecha' => '2026-11-12', 'porciones_totales' => 10, 'porciones_empresas' => 10, 'porciones_individuales' => 0, 'hora_recoleccion' => '11:00']);

        $filas = $this->getJson('/api/v1/admin/reportes/proveedor?fecha=2026-11-12')->assertOk()->json();

        $this->assertSame(['Mole', 'comida', 10], [$filas[0]['platillo_nombre'], $filas[0]['tiempo_comida'], $filas[0]['porciones']]);
        $this->assertEquals(490, $filas[0]['costo_total']);
        $this->getJson('/api/v1/admin/reportes/proveedor?fecha=2026-11-13')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/v1/admin/reportes/proveedor')->assertStatus(422);
    }

    public function test_el_menu_semanal_trae_seis_dias_con_un_platillo_por_tiempo_de_comida(): void
    {
        $comida = $this->platillo(null, 'comida', ['name' => 'Mole', 'price' => 49]);
        $cena = $this->platillo(null, 'cena', ['name' => 'Sopa']);
        $borrador = $this->platillo(null, 'desayuno', ['estado_revision' => 'borrador']);
        $this->disponible($comida, '2026-11-16', 40);
        $this->disponible($cena, '2026-11-16', 30);
        $this->disponible($borrador, '2026-11-16', 30);

        foreach (['customer', 'admin'] as $rol) {
            $this->comoRol($rol);
            $uri = $rol === 'admin' ? '/api/v1/admin/menu-semanal' : '/api/v1/cliente/menu-semanal';
            $dias = $this->getJson("{$uri}?fecha_inicio=2026-11-16")->assertOk()->assertJsonCount(6)->json();

            $this->assertSame('2026-11-16', $dias[0]['fecha']);
            $this->assertSame(['comida', 'cena'], array_keys($dias[0]['celdas']), 'el borrador no aparece');
            $this->assertEquals(['Mole', 49, 40], [$dias[0]['celdas']['comida']['platillo_nombre'], $dias[0]['celdas']['comida']['precio_venta'], $dias[0]['celdas']['comida']['porciones_maximas']]);
            $this->assertSame([], $dias[1]['celdas']);
        }
        $this->getJson('/api/v1/admin/menu-semanal')->assertStatus(422);
    }

    public function test_la_respuesta_vacia_de_un_dia_del_menu_es_un_objeto_json_y_no_una_lista(): void
    {
        $this->comoRol('admin');

        $this->assertStringContainsString('"celdas":{}', $this->getJson('/api/v1/admin/menu-semanal?fecha_inicio=2026-11-16')->getContent());
    }

    public function test_la_disponibilidad_del_comedor_trae_el_nombre_del_platillo(): void
    {
        $comedor = $this->comoComedor();
        $this->disponible($this->platillo($comedor, 'comida', ['name' => 'Mole']), '2026-11-12', 40);

        $this->getJson('/api/v1/comedor/disponibilidad?desde=2026-11-12&hasta=2026-11-12')->assertOk()->assertJsonPath('0.platillo_nombre', 'Mole');
    }

    // ------------------------------------------------------------------ renovar contratos

    public function test_renovar_un_contrato_vigente_extiende_6_meses_desde_su_fin(): void
    {
        $admin = $this->comoRol('admin');
        $empresa = $this->empresaConContrato('Zapopan', ['vigencia_fin' => '2026-12-31']);

        $this->postJson("/api/v1/admin/empresas/{$empresa->id}/renovar-contrato")->assertOk()
            ->assertJsonPath('contrato.vigencia_fin', '2027-06-30')->assertJsonPath('contrato.porcentaje_subsidio', 60)->assertJsonPath('estado_contrato', 'activo');
        $registro = RegistroAuditoria::sole();
        $this->assertSame(['contrato.renovado', $admin->id], [$registro->accion, $registro->usuario_id]);
    }

    public function test_renovar_un_contrato_vencido_cuenta_los_6_meses_desde_hoy(): void
    {
        $this->comoRol('admin');
        $empresa = $this->empresaConContrato('Zapopan', ['vigencia_inicio' => '2026-01-01', 'vigencia_fin' => '2026-10-31']);

        $this->postJson("/api/v1/admin/empresas/{$empresa->id}/renovar-contrato")->assertOk()->assertJsonPath('contrato.vigencia_fin', '2027-05-11');
    }

    public function test_renovar_el_contrato_de_un_comedor(): void
    {
        $this->comoRol('admin');
        $comedor = $this->comedorConContrato();

        $this->postJson("/api/v1/admin/comedores/{$comedor->id}/renovar-contrato")->assertOk()->assertJsonPath('contrato.vigencia_fin', '2027-07-31');
        $this->assertSame('2027-07-31', ContratoComedor::sole()->vigencia_fin->toDateString());
    }

    public function test_sin_contrato_activo_no_hay_nada_que_renovar_ni_siquiera_con_ids_inexistentes(): void
    {
        $this->comoRol('admin');
        $empresa = $this->empresaConContrato();
        ContratoEmpresa::where('empresa_id', $empresa->id)->sole()->desactivar();

        $this->postJson("/api/v1/admin/empresas/{$empresa->id}/renovar-contrato")->assertStatus(422)->assertJsonPath('codigo', 'sin_contrato_activo');
        $this->postJson('/api/v1/admin/comedores/999999/renovar-contrato')->assertStatus(404);
        $this->assertSame(0, EleccionEmpleado::count());
    }

    public function test_solo_el_administrador_renueva_contratos(): void
    {
        $empresa = $this->comoEmpresa();

        $this->postJson("/api/v1/admin/empresas/{$empresa->id}/renovar-contrato")->assertStatus(403);
    }
}
