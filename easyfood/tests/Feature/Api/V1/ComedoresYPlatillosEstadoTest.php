<?php

namespace Tests\Feature\Api\V1;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\ContratoComedor;
use App\Models\Menu;
use App\Models\Provider;
use App\Models\RegistroAuditoria;
use App\Services\EleccionDePlatillos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** Editar y desactivar comedores, y quitar o volver a poner un platillo en la oferta (admin y comedor). «Hoy» es el lunes 9 de nov de 2026. */
class ComedoresYPlatillosEstadoTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private const ENTREGA = '2026-11-12';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->ahoraEs('2026-11-09 10:00');
        $this->zonaDePruebas('Centro');
        $this->zonaDePruebas('Zapopan', -103.55, 20.72, 0.05);
    }

    private function datosDeComedor(array $extra = []): array
    {
        return $extra + [
            'nombre' => 'Cocina Renombrada', 'zona' => 'zapopan', 'contacto_nombre' => 'Marcela Ortiz', 'contacto_telefono' => '33 7777 8888',
            'precio_por_platillo' => 55.5, 'compra_minima_mensual' => 350,
        ];
    }

    /** Un empleado que ya eligió el platillo para el jueves. */
    private function eligio(Menu $menu): void
    {
        $this->disponible($menu, self::ENTREGA);
        app(EleccionDePlatillos::class)->elegir($this->empleadoDe($this->empresaConContrato()), self::ENTREGA, $menu->tiempo_comida, $menu);
    }

    // ------------------------------------------------------------------ editar comedor

    public function test_el_administrador_edita_el_comedor_su_contacto_y_su_contrato(): void
    {
        $admin = $this->comoRol('admin');
        $comedor = $this->comedorConContrato(200, 49);
        $comedor->update(['user_id' => $this->usuario()]);

        $this->putJson("/api/v1/admin/comedores/{$comedor->id}", $this->datosDeComedor())->assertOk()
            ->assertJsonPath('nombre', 'Cocina Renombrada')->assertJsonPath('zona', 'Zapopan')->assertJsonPath('contacto.nombre', 'Marcela Ortiz')
            ->assertJsonPath('contrato.precio_por_platillo', 55.5)->assertJsonPath('contrato.compra_minima_mensual', 350);

        $comedor->refresh();
        $this->assertSame(['Cocina Renombrada', 'Zapopan', '33 7777 8888'], [$comedor->commercial_name, $comedor->zona, $comedor->phone]);
        $this->assertSame($admin->id, RegistroAuditoria::where('accion', 'actualizar')->where('entidad', 'comedores')->sole()->usuario_id);
    }

    public function test_no_edita_con_nombre_repetido_zona_inexistente_precio_en_cero_ni_sin_contrato(): void
    {
        $this->comoRol('admin');
        $comedor = $this->comedorConContrato();
        $otro = $this->comedorConContrato();
        $url = "/api/v1/admin/comedores/{$comedor->id}";

        $this->putJson($url, $this->datosDeComedor(['nombre' => strtoupper($otro->business_name)]))->assertStatus(422)->assertJsonPath('codigo', 'comedor_repetido');
        $this->putJson($url, $this->datosDeComedor(['zona' => 'Narnia']))->assertStatus(422)->assertJsonPath('codigo', 'zona_inexistente');
        $this->putJson($url, $this->datosDeComedor(['precio_por_platillo' => 0]))->assertStatus(422)->assertJsonStructure(['errores' => ['precio_por_platillo']]);
        $this->putJson($url, $this->datosDeComedor(['compra_minima_mensual' => 0]))->assertStatus(422)->assertJsonStructure(['errores' => ['compra_minima_mensual']]);
        $this->putJson($url, $this->datosDeComedor(['nombre' => $comedor->business_name]))->assertOk(); // quedarse con su propio nombre sí

        ContratoComedor::where('comedor_id', $comedor->id)->update(['activo' => false, 'desactivado_en' => now()]);
        $this->putJson($url, $this->datosDeComedor())->assertStatus(422)->assertJsonPath('codigo', 'sin_contrato');
        $this->putJson('/api/v1/admin/comedores/999999', $this->datosDeComedor())->assertStatus(404);
    }

    public function test_el_correo_del_contacto_de_un_comedor_nuevo_se_corrige_antes_de_que_acepte(): void
    {
        $this->comoRol('admin');
        $id = $this->postJson('/api/v1/admin/comedores', ['nombre' => 'Cocina Nueva', 'contacto_nombre' => 'Rosa', 'contacto_correo' => 'rosa@mal.mx', 'telefono' => '33 1111 2222', 'direccion' => 'Calle 1',
            'zona' => 'Centro', 'vigencia_inicio' => '2026-12-01', 'precio_por_platillo' => 50, 'compra_minima_mensual' => 200])->assertCreated()->json('id');

        $this->putJson("/api/v1/admin/comedores/{$id}", $this->datosDeComedor(['nombre' => 'Cocina Nueva', 'contacto_correo' => 'rosa@bien.mx']))->assertOk()->assertJsonPath('contacto.correo', 'rosa@bien.mx');

        $this->assertSame('rosa@bien.mx', Provider::findOrFail($id)->email);
    }

    // ------------------------------------------------------------------ estado del comedor

    public function test_desactivar_un_comedor_deja_de_ofrecer_sus_platillos_y_al_reactivarlo_vuelven(): void
    {
        $this->comoRol('admin');
        $comedor = $this->comedorConContrato();
        $menu = $this->platillo($comedor);
        $this->disponible($menu, self::ENTREGA);
        $empleado = $this->empleadoDe($this->empresaConContrato());

        $this->patchJson("/api/v1/admin/comedores/{$comedor->id}/estado", ['activo' => false])->assertOk()->assertJsonPath('activo', false);
        $this->assertTrue($menu->fresh()->is_available, 'el estado del platillo no cambia');
        try {
            app(EleccionDePlatillos::class)->elegir($empleado, self::ENTREGA, $menu->tiempo_comida, $menu);
            $this->fail('un platillo de un comedor inactivo no se debe poder elegir');
        } catch (ReglaDeNegocioException $e) {
            $this->assertSame('platillo_no_disponible', $e->codigo);
        }

        $this->patchJson("/api/v1/admin/comedores/{$comedor->id}/estado", ['activo' => true])->assertOk()->assertJsonPath('activo', true);
        app(EleccionDePlatillos::class)->elegir($empleado, self::ENTREGA, $menu->tiempo_comida, $menu);
        $this->assertSame(1, \App\Models\EleccionEmpleado::count());
        $this->assertSame(['desactivar', 'reactivar'], RegistroAuditoria::where('entidad', 'comedores')->orderBy('id')->pluck('accion')->all());
    }

    public function test_no_desactiva_un_comedor_con_platillos_por_entregar(): void
    {
        $this->comoRol('admin');
        $comedor = $this->comedorConContrato();
        $this->eligio($this->platillo($comedor));

        $this->patchJson("/api/v1/admin/comedores/{$comedor->id}/estado", ['activo' => false])->assertStatus(422)->assertJsonPath('codigo', 'comedor_con_pedidos');

        $this->assertTrue((bool) $comedor->fresh()->is_active);
        $this->patchJson("/api/v1/admin/comedores/{$comedor->id}/estado", [])->assertStatus(422);
        $this->patchJson('/api/v1/admin/comedores/999999/estado', ['activo' => false])->assertStatus(404);
    }

    public function test_editar_y_desactivar_comedores_es_solo_del_administrador(): void
    {
        $comedor = $this->comedorConContrato();
        foreach (['support', 'company', 'provider', 'customer'] as $rol) {
            $this->comoRol($rol);
            $this->putJson("/api/v1/admin/comedores/{$comedor->id}", $this->datosDeComedor())->assertStatus(403);
            $this->patchJson("/api/v1/admin/comedores/{$comedor->id}/estado", ['activo' => false])->assertStatus(403);
        }
        $this->assertTrue((bool) $comedor->fresh()->is_active);
    }

    // ------------------------------------------------------------------ estado del platillo

    public function test_el_administrador_quita_y_vuelve_a_poner_un_platillo(): void
    {
        $this->comoRol('admin');
        $menu = $this->platillo();

        $this->patchJson("/api/v1/admin/platillos/{$menu->id}/estado", ['activo' => false])->assertOk()->assertJsonPath('activo', false);
        $this->assertFalse($menu->fresh()->is_available);
        $this->patchJson("/api/v1/admin/platillos/{$menu->id}/estado", ['activo' => true])->assertOk()->assertJsonPath('activo', true);

        $this->assertSame(['platillo.desactivado', 'platillo.reactivado'], RegistroAuditoria::where('entidad', 'platillos')->orderBy('id')->pluck('accion')->all());
        $this->patchJson("/api/v1/admin/platillos/{$menu->id}/estado", [])->assertStatus(422);
        $this->patchJson('/api/v1/admin/platillos/999999/estado', ['activo' => false])->assertStatus(404);
    }

    public function test_no_quita_un_platillo_con_elecciones_por_entregar(): void
    {
        $this->comoRol('admin');
        $menu = $this->platillo();
        $this->eligio($menu);

        $this->patchJson("/api/v1/admin/platillos/{$menu->id}/estado", ['activo' => false])->assertStatus(422)->assertJsonPath('codigo', 'platillo_con_pedidos');

        $this->assertTrue($menu->fresh()->is_available);
    }

    public function test_el_comedor_quita_sus_platillos_y_no_los_de_otro(): void
    {
        $comedor = $this->comoComedor();
        $propio = $this->platillo($comedor);
        $ajeno = $this->platillo();

        $this->patchJson("/api/v1/comedor/platillos/{$propio->id}/estado", ['activo' => false])->assertOk()->assertJsonPath('activo', false);
        $this->patchJson("/api/v1/comedor/platillos/{$ajeno->id}/estado", ['activo' => false])->assertStatus(404);

        $this->assertFalse($propio->fresh()->is_available);
        $this->assertTrue($ajeno->fresh()->is_available);
        $this->patchJson("/api/v1/comedor/platillos/{$propio->id}/estado", ['activo' => true])->assertOk();
    }

    public function test_cambiar_el_estado_de_un_platillo_no_es_de_clientes_ni_de_empresas(): void
    {
        $menu = $this->platillo();
        foreach (['company', 'customer'] as $rol) {
            $this->comoRol($rol);
            $this->patchJson("/api/v1/admin/platillos/{$menu->id}/estado", ['activo' => false])->assertStatus(403);
            $this->patchJson("/api/v1/comedor/platillos/{$menu->id}/estado", ['activo' => false])->assertStatus(403);
        }
        $this->assertTrue($menu->fresh()->is_available);
    }
}
