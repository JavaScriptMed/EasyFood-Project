<?php

namespace Tests\Feature\Api\V1;

use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\Paquete;
use App\Models\Pedido;
use App\Models\RegistroAuditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * El dashboard del administrador (GET /admin/dashboard), los indicadores del portal de empresas, la captura de calificaciones
 * (empleados y clientes), los costos operativos y los dos parámetros nuevos de configuración. «Hoy» es el jueves 12 de nov de 2026.
 */
class DashboardApiTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-12 12:00');
        $this->zonaDePruebas();
    }

    /** Una entrega ya entregada a la empresa en la que el empleado pidió un platillo de comida. */
    private function entregaDe(Empleado $empleado, string $fecha, string $estado = 'entregada'): array
    {
        $menu = $this->platillo($this->comedorConContrato(200, 49));
        $eleccion = $this->eleccion($empleado->id, $menu->id, ['fecha' => $fecha]);
        $entrega = DB::table('entregas_empresa')->insertGetId(['empresa_id' => $empleado->empresa_id, 'fecha' => $fecha, 'platillos' => 1, 'ventana_inicio' => '12:30', 'ventana_fin' => '12:50', 'estado' => $estado]);

        return [$menu, $eleccion, $entrega];
    }

    // ------------------------------------------------------------------ dashboard del administrador

    public function test_el_dashboard_trae_el_resumen_las_alertas_y_los_10_indicadores(): void
    {
        $this->comoRol('admin');
        DB::table('incidencias')->insert(['fecha' => '2026-11-12', 'tipo' => 'comida_fria', 'descripcion' => 'Llegó fría', 'prioridad' => 'inmediata']);

        $r = $this->getJson('/api/v1/admin/dashboard')->assertOk()->json();

        $this->assertEqualsCanonicalizing(['resumen', 'alertas', 'incidencias_del_dia', 'requieren_atencion', 'indicadores'], array_keys($r));
        $this->assertEqualsCanonicalizing(
            ['periodo', 'platillos_entregados', 'calificacion_entrega', 'calificacion_menu', 'paquete_popular', 'ahorro_estimado', 'incidencias', 'contratos_nuevos', 'balance', 'costo_maquila', 'facturacion_b2b'],
            array_keys($r['indicadores']),
        );
        $this->assertSame([1, 1], [$r['incidencias_del_dia'], $r['requieren_atencion']]);
        $this->assertSame('comida_fria', $r['alertas'][0]['tipo']);
        $this->assertEquals(49.0, $r['indicadores']['costo_maquila']['limite']);
    }

    public function test_el_dashboard_es_solo_del_administrador(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertStatus(401);

        foreach (['support', 'customer', 'company', 'provider', 'employee'] as $rol) {
            $this->comoRol($rol);
            $this->getJson('/api/v1/admin/dashboard')->assertStatus(403);
        }
    }

    public function test_los_indicadores_de_la_empresa_son_solo_de_su_empresa(): void
    {
        $empresa = $this->comoEmpresa();
        $empleado = $this->empleadoDe($empresa);
        $this->entregaDe($empleado, '2026-11-09');
        $otra = $this->empleadoDe($this->empresaConContrato('Centro'));
        $this->entregaDe($otra, '2026-11-09');

        $r = $this->getJson('/api/v1/empresa/indicadores')->assertOk()->json();

        $this->assertSame(1, $r['platillos_entregados']['semana']);
        $this->assertEqualsCanonicalizing(['periodo', 'platillos_entregados', 'calificacion_entrega', 'calificacion_menu', 'ahorro_estimado', 'incidencias', 'facturacion'], array_keys($r));
        $this->assertArrayNotHasKey('balance', $r, 'la utilidad no es de las empresas');
        foreach (['admin', 'customer', 'employee'] as $rol) {
            $this->comoRol($rol);
            $this->getJson('/api/v1/empresa/indicadores')->assertStatus(403);
        }
    }

    public function test_cualquiera_con_sesion_ve_la_calificacion_semanal_del_menu(): void
    {
        $this->getJson('/api/v1/calificaciones/menu-semanal')->assertStatus(401);
        $menu = $this->platillo()->id;
        DB::table('calificaciones_platillo')->insert([
            ['calificador_id' => $this->usuario(), 'menu_id' => $menu, 'fecha' => '2026-11-09', 'estrellas' => 5],
            ['calificador_id' => $this->usuario(), 'menu_id' => $menu, 'fecha' => '2026-11-10', 'estrellas' => 4],
        ]);

        foreach (['customer', 'admin', 'company'] as $rol) {
            $this->comoRol($rol);
            $this->getJson('/api/v1/calificaciones/menu-semanal')->assertOk()->assertJsonPath('promedio', 4.5)->assertJsonPath('calificaciones', 2);
        }
    }

    // ------------------------------------------------------------------ calificaciones de empleados

    public function test_el_empleado_ve_lo_que_recibio_y_no_ha_calificado_y_lo_califica(): void
    {
        $empleado = $this->comoEmpleado();
        $this->entregaDe($empleado, '2026-11-10');
        $this->entregaDe($empleado, '2026-11-11', 'programada'); // todavía no se entrega

        $this->getJson('/api/v1/empleado/calificaciones/pendientes')->assertOk()->assertJsonCount(1)->assertJsonPath('0.fecha', '2026-11-10')
            ->assertJsonPath('0.entrega_calificada', false)->assertJsonPath('0.platillos.0.calificado', false);

        $this->postJson('/api/v1/empleado/calificaciones/entrega', ['fecha' => '2026-11-10', 'puntualidad' => 5, 'trato_chofer' => 4, 'empaque' => 3, 'comentario' => 'Todo bien'])->assertCreated();
        $this->postJson('/api/v1/empleado/calificaciones/platillo', ['fecha' => '2026-11-10', 'tiempo_comida' => 'comida', 'estrellas' => 5])->assertCreated();

        $this->getJson('/api/v1/empleado/calificaciones/pendientes')->assertOk()->assertJsonCount(0);
        $this->assertSame([5, 4, 3], array_values(DB::table('calificaciones_entrega')->first(['puntualidad', 'trato_chofer', 'empaque']) ? (array) DB::table('calificaciones_entrega')->first(['puntualidad', 'trato_chofer', 'empaque']) : []));
        $this->assertSame(5, (int) DB::table('calificaciones_platillo')->value('estrellas'));
    }

    public function test_solo_se_califica_lo_entregado_una_vez_y_con_estrellas_de_1_a_5(): void
    {
        $empleado = $this->comoEmpleado();
        $this->entregaDe($empleado, '2026-11-10');
        $this->entregaDe($empleado, '2026-11-11', 'programada');
        $buena = ['fecha' => '2026-11-10', 'puntualidad' => 5, 'trato_chofer' => 5, 'empaque' => 5];

        $this->postJson('/api/v1/empleado/calificaciones/entrega', ['fecha' => '2026-11-11'] + $buena)->assertStatus(422)->assertJsonPath('codigo', 'sin_entrega_que_calificar');
        $this->postJson('/api/v1/empleado/calificaciones/entrega', ['fecha' => '2026-10-20'] + $buena)->assertStatus(422)->assertJsonPath('codigo', 'fuera_de_plazo'); // hace más de 14 días
        $this->postJson('/api/v1/empleado/calificaciones/entrega', ['fecha' => '2026-11-13'] + $buena)->assertStatus(422)->assertJsonPath('codigo', 'fuera_de_plazo');
        $this->postJson('/api/v1/empleado/calificaciones/entrega', ['puntualidad' => 6, 'trato_chofer' => 0] + $buena)->assertStatus(422)->assertJsonStructure(['errores' => ['puntualidad', 'trato_chofer']]);
        $this->postJson('/api/v1/empleado/calificaciones/entrega', $buena)->assertCreated();
        $this->postJson('/api/v1/empleado/calificaciones/entrega', $buena)->assertStatus(422)->assertJsonPath('codigo', 'ya_calificada');
        $this->postJson('/api/v1/empleado/calificaciones/platillo', ['fecha' => '2026-11-11', 'tiempo_comida' => 'comida', 'estrellas' => 5])->assertStatus(422)->assertJsonPath('codigo', 'sin_platillo_que_calificar');
        $this->postJson('/api/v1/empleado/calificaciones/platillo', ['fecha' => '2026-11-10', 'tiempo_comida' => 'comida', 'estrellas' => 9])->assertStatus(422);
        $this->postJson('/api/v1/empleado/calificaciones/platillo', ['fecha' => '2026-11-10', 'tiempo_comida' => 'cena', 'estrellas' => 4])->assertStatus(422)->assertJsonPath('codigo', 'sin_platillo_que_calificar');
        $this->postJson('/api/v1/empleado/calificaciones/platillo', ['fecha' => '2026-11-10', 'tiempo_comida' => 'comida', 'estrellas' => 4])->assertCreated();
        $this->postJson('/api/v1/empleado/calificaciones/platillo', ['fecha' => '2026-11-10', 'tiempo_comida' => 'comida', 'estrellas' => 2])->assertStatus(422)->assertJsonPath('codigo', 'ya_calificada');
    }

    public function test_un_empleado_no_califica_la_entrega_de_un_dia_en_que_no_pidio(): void
    {
        $empresa = $this->empresaConContrato();
        $otroEmpleado = $this->empleadoDe($empresa);
        $this->entregaDe($otroEmpleado, '2026-11-10');
        $this->comoEmpleado($empresa); // este empleado es de la misma empresa pero ese día no pidió nada

        $this->postJson('/api/v1/empleado/calificaciones/entrega', ['fecha' => '2026-11-10', 'puntualidad' => 5, 'trato_chofer' => 5, 'empaque' => 5])->assertStatus(422)->assertJsonPath('codigo', 'sin_entrega_que_calificar');
    }

    public function test_la_base_no_deja_calificar_una_entrega_que_no_se_entrego_ni_repetir_ni_salirse_de_1_a_5(): void
    {
        $empleado = $this->empleadoDe($this->empresaConContrato());
        [, , $programada] = $this->entregaDe($empleado, '2026-11-11', 'programada');
        [, , $entregada] = $this->entregaDe($empleado, '2026-11-10');
        $u = $this->usuario();
        $fila = ['calificador_id' => $u, 'puntualidad' => 4, 'trato_chofer' => 4, 'empaque' => 4];

        $this->rechaza('Solo se califica una entrega que ya se entregó', fn () => DB::table('calificaciones_entrega')->insert($fila + ['entrega_empresa_id' => $programada]));
        $this->rechaza('calificaciones_entrega_estrellas_validas', fn () => DB::table('calificaciones_entrega')->insert(['puntualidad' => 6] + $fila + ['entrega_empresa_id' => $entregada]));
        $this->rechaza('calificaciones_entrega_una_sola_entrega', fn () => DB::table('calificaciones_entrega')->insert($fila));
        $this->assertTrue(DB::table('calificaciones_entrega')->insert($fila + ['entrega_empresa_id' => $entregada]));
        $this->rechaza('calificaciones_entrega_unica_empresa', fn () => DB::table('calificaciones_entrega')->insert($fila + ['entrega_empresa_id' => $entregada]));
        $this->rechaza('calificaciones_platillo_estrellas_validas', fn () => DB::table('calificaciones_platillo')->insert(['calificador_id' => $u, 'menu_id' => $this->menu(), 'fecha' => '2026-11-10', 'estrellas' => 0]));
    }

    // ------------------------------------------------------------------ calificaciones de clientes

    private function pedidoEntregadoDeCliente(): array
    {
        $cliente = $this->usuarioDeRol('customer');
        $direccion = DB::table('addresses')->insertGetId(['user_id' => $cliente->id, 'street_address' => 'Av. 1', 'latitude' => 20.67, 'longitude' => -103.35]);
        $paquete = Paquete::firstOrCreate(['nombre' => 'Un día'], ['dias' => 1, 'descuento_porcentaje' => 0]);
        $pedido = DB::table('pedidos')->insertGetId(['folio' => uniqid('GDL-'), 'cliente_id' => $cliente->id, 'paquete_id' => $paquete->id, 'direccion_id' => $direccion, 'fecha_inicio' => '2026-11-10', 'fecha_fin' => '2026-11-10',
            'total' => 100, 'estado' => 'activo', 'pagado_en' => '2026-11-09 10:00:00-06']);
        $dia = DB::table('pedido_dias')->insertGetId(['pedido_id' => $pedido, 'fecha' => '2026-11-10', 'estado' => 'entregada', 'entregada_en' => '2026-11-10 13:00:00-06']);
        $menu = $this->platillo()->id;
        DB::table('pedido_dia_platillos')->insert(['pedido_dia_id' => $dia, 'tiempo_comida' => 'comida', 'menu_id' => $menu, 'precio_congelado' => 100]);

        return [$cliente, $pedido, $menu];
    }

    public function test_el_cliente_califica_la_entrega_y_el_platillo_de_su_pedido(): void
    {
        [$cliente, $pedido, $menu] = $this->pedidoEntregadoDeCliente();
        \Laravel\Sanctum\Sanctum::actingAs($cliente);

        $this->postJson("/api/v1/cliente/pedidos/{$pedido}/calificaciones/entrega", ['fecha' => '2026-11-10', 'puntualidad' => 4, 'trato_chofer' => 5, 'empaque' => 2])->assertCreated();
        $this->postJson("/api/v1/cliente/pedidos/{$pedido}/calificaciones/platillo", ['fecha' => '2026-11-10', 'platillo_id' => $menu, 'estrellas' => 3])->assertCreated();

        $this->postJson("/api/v1/cliente/pedidos/{$pedido}/calificaciones/entrega", ['fecha' => '2026-11-10', 'puntualidad' => 4, 'trato_chofer' => 5, 'empaque' => 2])->assertStatus(422)->assertJsonPath('codigo', 'ya_calificada');
        $this->postJson("/api/v1/cliente/pedidos/{$pedido}/calificaciones/platillo", ['fecha' => '2026-11-10', 'platillo_id' => $this->menu(), 'estrellas' => 3])->assertStatus(422)->assertJsonPath('codigo', 'sin_platillo_que_calificar');
        $this->assertSame(1, DB::table('calificaciones_entrega')->whereNotNull('pedido_dia_id')->count());
    }

    public function test_el_cliente_no_califica_un_pedido_ajeno_ni_un_dia_sin_entregar(): void
    {
        [, $pedido] = $this->pedidoEntregadoDeCliente();
        $this->comoRol('customer');
        $this->postJson("/api/v1/cliente/pedidos/{$pedido}/calificaciones/entrega", ['fecha' => '2026-11-10', 'puntualidad' => 5, 'trato_chofer' => 5, 'empaque' => 5])->assertStatus(404);

        [$dueno, $suyo] = $this->pedidoEntregadoDeCliente();
        DB::table('pedido_dias')->where('pedido_id', $suyo)->update(['estado' => 'programada', 'entregada_en' => null]);
        \Laravel\Sanctum\Sanctum::actingAs($dueno);
        $this->postJson("/api/v1/cliente/pedidos/{$suyo}/calificaciones/entrega", ['fecha' => '2026-11-10', 'puntualidad' => 5, 'trato_chofer' => 5, 'empaque' => 5])->assertStatus(422)->assertJsonPath('codigo', 'sin_entrega_que_calificar');
    }

    // ------------------------------------------------------------------ costos operativos

    public function test_el_administrador_registra_lista_y_anula_costos_operativos_con_bitacora(): void
    {
        $admin = $this->comoRol('admin');

        $id = $this->postJson('/api/v1/admin/costos-operativos', ['fecha' => '2026-11-10', 'categoria' => 'reparto', 'descripcion' => 'Combustible de la semana', 'monto' => 1234.5])
            ->assertCreated()->assertJsonPath('monto', 1234.5)->assertJsonPath('registrado_por', $admin->name)->json('id');

        $this->getJson('/api/v1/admin/costos-operativos')->assertOk()->assertJsonCount(1)->assertJsonPath('0.categoria', 'reparto');
        $this->getJson('/api/v1/admin/dashboard')->assertJsonPath('indicadores.balance.semana.costos_operativos', 1234.5);

        $this->postJson("/api/v1/admin/costos-operativos/{$id}/anular")->assertOk()->assertJsonPath('activo', false);
        $this->postJson("/api/v1/admin/costos-operativos/{$id}/anular")->assertStatus(422)->assertJsonPath('codigo', 'costo_ya_anulado');
        $this->getJson('/api/v1/admin/dashboard')->assertJsonPath('indicadores.balance.semana.costos_operativos', 0);
        $this->assertSame(['costo_operativo.registrado', 'costo_operativo.anulado'], RegistroAuditoria::whereIn('accion', ['costo_operativo.registrado', 'costo_operativo.anulado'])->orderBy('id')->pluck('accion')->all());
        $this->assertSame(1, DB::table('costos_operativos')->count(), 'se anula, no se borra');
    }

    public function test_valida_el_costo_y_solo_el_administrador_lo_maneja(): void
    {
        $this->comoRol('admin');
        $valido = ['fecha' => '2026-11-10', 'categoria' => 'reparto', 'descripcion' => 'Combustible', 'monto' => 100];

        $this->postJson('/api/v1/admin/costos-operativos', ['monto' => 0] + $valido)->assertStatus(422)->assertJsonStructure(['errores' => ['monto']]);
        $this->postJson('/api/v1/admin/costos-operativos', ['categoria' => 'viajes'] + $valido)->assertStatus(422)->assertJsonStructure(['errores' => ['categoria']]);
        $this->postJson('/api/v1/admin/costos-operativos', ['fecha' => '2026-11-20'] + $valido)->assertStatus(422)->assertJsonStructure(['errores' => ['fecha']]);

        foreach (['support', 'customer', 'company'] as $rol) {
            $this->comoRol($rol);
            $this->postJson('/api/v1/admin/costos-operativos', $valido)->assertStatus(403);
            $this->getJson('/api/v1/admin/costos-operativos')->assertStatus(403);
        }
    }

    public function test_la_base_exige_costos_positivos_y_con_categoria_conocida(): void
    {
        $fila = ['fecha' => '2026-11-10', 'categoria' => 'reparto', 'descripcion' => 'x', 'monto' => 10, 'registrado_por' => $this->usuario()];
        $this->rechaza('costos_operativos_monto_positivo', fn () => DB::table('costos_operativos')->insert(['monto' => 0] + $fila));
        $this->rechaza('costos_operativos_categoria_valido', fn () => DB::table('costos_operativos')->insert(['categoria' => 'viajes'] + $fila));
        $this->rechaza('costos_operativos_desactivacion_coherente', fn () => DB::table('costos_operativos')->insert(['activo' => false] + $fila));
        $id = DB::table('costos_operativos')->insertGetId($fila);
        $this->rechaza('No se puede borrar', fn () => DB::table('costos_operativos')->where('id', $id)->delete());
    }

    // ------------------------------------------------------------------ configuración

    public function test_el_ahorro_y_el_limite_de_maquila_se_editan_desde_la_configuracion(): void
    {
        $this->comoRol('admin');
        $base = ['hora_corte' => '18:00', 'dias_entrega' => 'lunes_sabado', 'regla_sin_eleccion' => 'ultimo_platillo', 'capacidad_auto_entregas' => 30, 'capacidad_auto_platillos' => 120];

        $this->getJson('/api/v1/configuracion/operacion')->assertOk()->assertJsonPath('ahorro_apps_porcentaje', 30)->assertJsonPath('costo_maquila_limite', 49);
        $this->putJson('/api/v1/admin/configuracion', $base)->assertOk()->assertJsonPath('ahorro_apps_porcentaje', 30); // sin mandarlos, quedan igual
        $this->putJson('/api/v1/admin/configuracion', $base + ['ahorro_apps_porcentaje' => '35.5', 'costo_maquila_limite' => '52'])->assertOk()
            ->assertJsonPath('ahorro_apps_porcentaje', 35.5)->assertJsonPath('costo_maquila_limite', 52);
        $this->getJson('/api/v1/admin/dashboard')->assertJsonPath('indicadores.ahorro_estimado.porcentaje_sobreprecio', 35.5)->assertJsonPath('indicadores.costo_maquila.limite', 52);

        $this->putJson('/api/v1/admin/configuracion', $base + ['ahorro_apps_porcentaje' => '101'])->assertStatus(422)->assertJsonStructure(['errores' => ['ahorro_apps_porcentaje']]);
        $this->putJson('/api/v1/admin/configuracion', $base + ['costo_maquila_limite' => '0'])->assertStatus(422)->assertJsonStructure(['errores' => ['costo_maquila_limite']]);
        $this->assertNotNull(RegistroAuditoria::where('accion', 'configuracion.actualizada')->where('detalle', 'like', '%ahorro_apps_porcentaje%')->first());
    }

    public function test_la_base_limita_el_ahorro_de_0_a_100_y_el_limite_a_un_monto_positivo(): void
    {
        $this->rechaza('configuracion_ahorro_apps_valido', fn () => DB::table('configuracion')->where('clave', 'ahorro_apps_porcentaje')->update(['valor' => '150']));
        $this->rechaza('configuracion_ahorro_apps_valido', fn () => DB::table('configuracion')->where('clave', 'ahorro_apps_porcentaje')->update(['valor' => 'mucho']));
        $this->rechaza('configuracion_costo_maquila_valido', fn () => DB::table('configuracion')->where('clave', 'costo_maquila_limite')->update(['valor' => '0']));
        $this->assertSame(1, DB::table('configuracion')->where('clave', 'ahorro_apps_porcentaje')->update(['valor' => '100']));
    }

    public function test_el_conteo_de_quejas_acepta_los_dos_tipos_nuevos_de_incidencia(): void
    {
        foreach (['comida_fria', 'entrega_tardia'] as $tipo) {
            $this->assertTrue(DB::table('incidencias')->insert(['fecha' => '2026-11-12', 'tipo' => $tipo, 'descripcion' => 'x']));
        }
        $this->rechaza('incidencias_tipo_valido', fn () => DB::table('incidencias')->insert(['fecha' => '2026-11-12', 'tipo' => 'comida_cruda', 'descripcion' => 'x']));
        $this->comoRol('admin');
        $this->getJson('/api/v1/admin/incidencias?tipos[]=comida_fria&tipos[]=entrega_tardia')->assertOk()->assertJsonCount(2);
        $this->assertSame(0, Pedido::count());
        $this->assertNotNull(Empresa::class);
    }
}
