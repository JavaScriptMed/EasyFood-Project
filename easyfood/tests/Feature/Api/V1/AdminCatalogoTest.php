<?php

namespace Tests\Feature\Api\V1;

use App\Models\ContratoComedor;
use App\Models\ContratoEmpresa;
use App\Models\Menu;
use App\Models\RegistroAuditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** Panel de administración: empresas, comedores, contratos por vencer y platillos. */
class AdminCatalogoTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-11 10:00');
        $this->comoRol('admin');
    }

    public function test_lista_las_empresas_con_contacto_contrato_y_estado_del_contrato(): void
    {
        $empresa = $this->empresaConContrato('Zapopan', ['porcentaje_subsidio' => 60, 'vigencia_fin' => '2026-11-30']);
        $this->empleadoDe($empresa);
        $this->empleadoDe($empresa)->desactivar();

        $respuesta = $this->getJson('/api/v1/admin/empresas')->assertOk();

        $fila = $respuesta->json('0');
        $this->assertSame($empresa->nombre, $fila['nombre']);
        $this->assertSame(['id', 'usuario_id', 'nombre', 'dominio', 'zona', 'direccion_entrega', 'activo'], array_slice(array_keys($fila), 0, 7));
        $this->assertSame(60, (int) $fila['contrato']['porcentaje_subsidio']);
        $this->assertSame(130, $fila['contrato']['precio_por_platillo']);
        $this->assertSame('2026-11-30', $fila['contrato']['vigencia_fin']);
        $this->assertSame(1, $fila['total_empleados'], 'solo cuenta los activos');
        $this->assertSame(['por_vencer', 19], [$fila['estado_contrato'], $fila['dias_para_vencer']]);
        $this->assertArrayHasKey('correo', $fila['contacto']);
    }

    public function test_muestra_una_empresa_y_responde_404_si_no_existe(): void
    {
        $empresa = $this->empresaConContrato();

        $this->getJson("/api/v1/admin/empresas/{$empresa->id}")->assertOk()->assertJsonPath('nombre', $empresa->nombre);
        $this->getJson('/api/v1/admin/empresas/999999')->assertStatus(404);
    }

    public function test_lista_los_comedores_con_su_contrato_y_avance_del_minimo(): void
    {
        $comedor = $this->comedorConContrato(100, 49);
        $comedor->update(['commercial_name' => 'Cocina Norte', 'zona' => 'Zapopan Norte']);
        $menu = $this->platillo($comedor, 'comida', ['estado_revision' => 'borrador']);
        \Illuminate\Support\Facades\DB::table('ordenes_produccion')->insert([
            'comedor_id' => $comedor->id, 'menu_id' => $menu->id, 'fecha' => '2026-11-05', 'porciones_totales' => 40, 'porciones_empresas' => 40,
            'porciones_individuales' => 0, 'hora_recoleccion' => '11:00',
        ]);

        $fila = $this->getJson('/api/v1/admin/comedores')->assertOk()->json('0');

        $this->assertSame(['Cocina Norte', 'Zapopan Norte'], [$fila['nombre'], $fila['zona']]);
        $this->assertSame([49, 100], [$fila['contrato']['precio_por_platillo'], $fila['contrato']['compra_minima_mensual']]);
        $this->assertSame([40, 40], [$fila['platillos_entregados_mes'], $fila['avance_minimo_porcentaje']]);
        $this->assertSame(0, $fila['platillos_publicados'], 'el platillo de prueba quedó en borrador');
    }

    public function test_contratos_por_vencer_junta_empresas_y_comedores_ordenados_por_dias(): void
    {
        $this->empresaConContrato('Zapopan', ['vigencia_fin' => '2026-11-20']);
        $comedor = $this->comedorNuevo();
        ContratoComedor::create(['comedor_id' => $comedor->id, 'precio_por_platillo' => 49, 'compra_minima_mensual' => 200, 'vigencia_inicio' => '2026-06-01', 'vigencia_fin' => '2026-11-13']);
        $this->empresaConContrato('Zapopan', ['vigencia_fin' => '2027-05-30']); // falta mucho

        $filas = $this->getJson('/api/v1/admin/contratos/por-vencer')->assertOk()->json();

        $this->assertSame(['comedor', 'empresa'], array_column($filas, 'tipo'));
        $this->assertSame([2, 9], array_column($filas, 'dias'));
        $this->getJson('/api/v1/admin/contratos/por-vencer?dias=500')->assertStatus(422)->assertJsonStructure(['mensaje', 'errores' => ['dias']]);
    }

    public function test_un_contrato_desactivado_no_aparece_como_por_vencer(): void
    {
        $empresa = $this->empresaConContrato('Zapopan', ['vigencia_fin' => '2026-11-20']);
        ContratoEmpresa::where('empresa_id', $empresa->id)->sole()->desactivar();

        $this->getJson('/api/v1/admin/contratos/por-vencer')->assertOk()->assertJsonCount(0);
    }

    // ------------------------------------------------------------------ platillos

    private function datosPlatillo(int $comedor, array $extra = []): array
    {
        return $extra + [
            'comedor_id' => $comedor, 'nombre' => 'Pollo en mole', 'descripcion' => 'Con arroz', 'tiempo_comida' => 'comida', 'calorias' => 650,
            'alergenos' => ['frutos secos'], 'precio_venta' => 49, 'estado_revision' => 'en_revision',
        ];
    }

    public function test_crea_un_platillo_con_los_nombres_del_front_y_lo_deja_en_la_bitacora(): void
    {
        $comedor = $this->comedorNuevo();

        $respuesta = $this->postJson('/api/v1/admin/platillos', $this->datosPlatillo($comedor->id))->assertCreated();

        $respuesta->assertJsonPath('nombre', 'Pollo en mole')->assertJsonPath('comedor_id', $comedor->id)->assertJsonPath('precio_venta', 49)
            ->assertJsonPath('estado_revision', 'en_revision')->assertJsonPath('alergenos.0', 'frutos secos')->assertJsonPath('activo', true);
        $this->assertSame('platillo.creado', RegistroAuditoria::sole()->accion);
        $this->assertSame(49.0, (float) Menu::sole()->price);
    }

    public function test_valida_el_platillo_con_mensajes_en_espanol(): void
    {
        $this->postJson('/api/v1/admin/platillos', ['nombre' => ''])->assertStatus(422)
            ->assertJsonStructure(['mensaje', 'errores' => ['comedor_id', 'nombre', 'tiempo_comida', 'precio_venta']]);

        $comedor = $this->comedorNuevo();
        $respuesta = $this->postJson('/api/v1/admin/platillos', $this->datosPlatillo($comedor->id, ['precio_venta' => 0, 'tiempo_comida' => 'merienda']));
        $respuesta->assertStatus(422)->assertJsonStructure(['errores' => ['precio_venta', 'tiempo_comida']]);
        $this->assertStringNotContainsString('required', $respuesta->getContent());
    }

    public function test_por_este_endpoint_no_se_puede_publicar_ni_rechazar(): void
    {
        $comedor = $this->comedorNuevo();

        $this->postJson('/api/v1/admin/platillos', $this->datosPlatillo($comedor->id, ['estado_revision' => 'publicado']))
            ->assertStatus(422)->assertJsonStructure(['errores' => ['estado_revision']]);
    }

    public function test_edita_un_platillo(): void
    {
        $menu = $this->platillo(null, 'comida', ['estado_revision' => 'borrador']);

        $this->putJson("/api/v1/admin/platillos/{$menu->id}", ['nombre' => 'Nuevo nombre', 'calorias' => 500])->assertOk()
            ->assertJsonPath('nombre', 'Nuevo nombre')->assertJsonPath('calorias', 500);
    }

    public function test_aprueba_un_platillo_en_revision_y_lo_publica(): void
    {
        $menu = $this->platillo(null, 'comida', ['estado_revision' => 'en_revision']);

        $this->postJson("/api/v1/admin/platillos/{$menu->id}/revision", ['decision' => 'aprobar'])->assertOk()
            ->assertJsonPath('estado_revision', 'publicado')->assertJsonPath('motivo_rechazo', null);
        $this->assertSame('platillo.aprobado', RegistroAuditoria::sole()->accion);
    }

    public function test_rechazar_exige_motivo_y_lo_guarda(): void
    {
        $menu = $this->platillo(null, 'comida', ['estado_revision' => 'en_revision']);

        $this->postJson("/api/v1/admin/platillos/{$menu->id}/revision", ['decision' => 'rechazar'])->assertStatus(422)->assertJsonStructure(['errores' => ['motivo']]);
        $this->postJson("/api/v1/admin/platillos/{$menu->id}/revision", ['decision' => 'rechazar', 'motivo' => 'Falta información de alérgenos'])->assertOk()
            ->assertJsonPath('estado_revision', 'rechazado')->assertJsonPath('motivo_rechazo', 'Falta información de alérgenos');
    }

    public function test_solo_se_revisa_lo_que_esta_en_revision(): void
    {
        $publicado = $this->platillo();

        $this->postJson("/api/v1/admin/platillos/{$publicado->id}/revision", ['decision' => 'aprobar'])->assertStatus(422)->assertJsonPath('codigo', 'platillo_no_en_revision');
    }

    public function test_filtra_los_platillos_por_comedor_y_estado(): void
    {
        $uno = $this->comedorNuevo();
        $this->platillo($uno);
        $this->platillo($uno, 'comida', ['estado_revision' => 'en_revision']);
        $this->platillo();

        $this->getJson('/api/v1/admin/platillos')->assertJsonCount(3);
        $this->getJson("/api/v1/admin/platillos?comedor_id={$uno->id}")->assertJsonCount(2);
        $this->getJson('/api/v1/admin/platillos?estado_revision=en_revision')->assertJsonCount(1);
        $this->getJson('/api/v1/admin/platillos?estado_revision=raro')->assertStatus(422);
    }
}
