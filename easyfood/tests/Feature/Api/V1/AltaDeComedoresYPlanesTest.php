<?php

namespace Tests\Feature\Api\V1;

use App\Mail\InvitacionDeAcceso;
use App\Models\ContratoComedor;
use App\Models\Paquete;
use App\Models\Provider;
use App\Models\RegistroAuditoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** Alta de comedores (con su contacto y su contrato) y de planes para clientes individuales. «Hoy» es el lunes 9 de nov de 2026. */
class AltaDeComedoresYPlanesTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->ahoraEs('2026-11-09 10:00');
        $this->zonaDePruebas('Centro');
        $this->zonaDePruebas('Zapopan', -103.55, 20.72, 0.05);
    }

    private function nuevoComedor(array $extra = []): array
    {
        return $extra + [
            'nombre' => 'Cocina Doña Chela', 'razon_social' => 'Alimentos Chela S.A. de C.V.', 'rfc' => 'ach010101ab1', 'contacto_nombre' => 'Marcela Ortiz', 'contacto_correo' => 'Marcela@Chela.mx',
            'telefono' => '33 1111 2222', 'direccion' => 'Calle Hidalgo 45, Zapopan', 'codigo_postal' => '45100', 'zona' => 'zapopan', 'capacidad_diaria' => 150,
            'vigencia_inicio' => '2026-12-01', 'precio_por_platillo' => 52.5, 'compra_minima_mensual' => 300,
        ];
    }

    // ------------------------------------------------------------------ comedores

    public function test_el_administrador_da_de_alta_un_comedor_con_su_contacto_su_contrato_y_su_invitacion(): void
    {
        $admin = $this->comoRol('admin');

        $r = $this->postJson('/api/v1/admin/comedores', $this->nuevoComedor())->assertCreated()->json();

        $this->assertSame(['Cocina Doña Chela', 'Alimentos Chela S.A. de C.V.', 'Zapopan', true], [$r['nombre'], $r['razon_social'], $r['zona'], $r['activo']]);
        $this->assertSame([52.5, 300, '2026-12-01', '2027-05-31'], [(float) $r['contrato']['precio_por_platillo'], $r['contrato']['compra_minima_mensual'], $r['contrato']['vigencia_inicio'], $r['contrato']['vigencia_fin']]);
        $this->assertSame(['Marcela Ortiz', 'marcela@chela.mx'], [$r['contacto']['nombre'], $r['contacto']['correo']]);

        $comedor = Provider::sole();
        $contacto = User::where('email', 'marcela@chela.mx')->firstOrFail();
        $this->assertSame($contacto->id, $comedor->user_id);
        $this->assertSame(['inactive', 'provider'], [$contacto->status, $contacto->role->name]);
        $this->assertSame('ACH010101AB1', $comedor->rfc);
        $this->assertSame(150, $comedor->daily_capacity);
        $this->assertSame('33 1111 2222', $contacto->profile->phone);
        Mail::assertQueued(InvitacionDeAcceso::class, fn ($m) => $m->hasTo('marcela@chela.mx') && str_contains($m->url, '/comedores/aceptar-invitacion?token='));
        $this->assertSame($admin->id, RegistroAuditoria::where('accion', 'crear')->where('entidad', 'comedores')->sole()->usuario_id);
    }

    public function test_el_contacto_acepta_la_invitacion_y_entra_a_los_datos_de_su_comedor(): void
    {
        $this->comoRol('admin');
        $id = $this->postJson('/api/v1/admin/comedores', $this->nuevoComedor())->assertCreated()->json('id');
        preg_match('/token=([A-Za-z0-9]+)/', Mail::queued(InvitacionDeAcceso::class)->last()->url, $m);

        $this->postJson("/api/v1/cuentas/invitaciones/{$m[1]}/aceptar", ['contrasena' => 'Clave1234'])->assertOk()->assertJsonPath('portal', 'comedores');
        Sanctum::actingAs(User::where('email', 'marcela@chela.mx')->firstOrFail());

        $this->getJson('/api/v1/comedor/perfil')->assertOk()->assertJsonPath('id', $id);
    }

    public function test_sin_razon_social_se_usa_el_nombre_y_la_capacidad_por_defecto_es_100(): void
    {
        $this->comoRol('admin');

        $this->postJson('/api/v1/admin/comedores', $this->nuevoComedor(['razon_social' => null, 'capacidad_diaria' => null, 'rfc' => null]))->assertCreated()->assertJsonPath('razon_social', 'Cocina Doña Chela');

        $this->assertSame([100, null], [Provider::sole()->daily_capacity, Provider::sole()->rfc]);
    }

    public function test_valida_el_alta_de_comedor_y_no_deja_nada_a_medias(): void
    {
        $this->comoRol('admin');
        $this->postJson('/api/v1/admin/comedores', $this->nuevoComedor())->assertCreated();
        $usuarios = User::count();

        $this->postJson('/api/v1/admin/comedores', $this->nuevoComedor(['contacto_correo' => 'otro@chela.mx', 'nombre' => 'COCINA DOÑA CHELA', 'rfc' => null]))->assertStatus(422)->assertJsonPath('codigo', 'comedor_repetido');
        $this->postJson('/api/v1/admin/comedores', $this->nuevoComedor(['contacto_correo' => 'otro@chela.mx', 'nombre' => 'Otra', 'razon_social' => 'Otra SA']))->assertStatus(422)->assertJsonPath('codigo', 'rfc_repetido');
        $this->postJson('/api/v1/admin/comedores', $this->nuevoComedor(['nombre' => 'Otra', 'razon_social' => 'Otra SA', 'rfc' => null, 'zona' => 'Narnia']))->assertStatus(422)->assertJsonPath('codigo', 'zona_inexistente');
        $this->postJson('/api/v1/admin/comedores', $this->nuevoComedor(['nombre' => 'Otra', 'razon_social' => 'Otra SA', 'rfc' => null]))->assertStatus(422)->assertJsonPath('codigo', 'correo_en_uso');
        $base = ['nombre' => 'Otra', 'razon_social' => 'Otra SA', 'rfc' => null, 'contacto_correo' => 'x@chela.mx'];
        $this->postJson('/api/v1/admin/comedores', $this->nuevoComedor($base + ['precio_por_platillo' => 0]))->assertStatus(422)->assertJsonStructure(['errores' => ['precio_por_platillo']]);
        $this->postJson('/api/v1/admin/comedores', $this->nuevoComedor($base + ['compra_minima_mensual' => 0]))->assertStatus(422)->assertJsonStructure(['errores' => ['compra_minima_mensual']]);
        $this->postJson('/api/v1/admin/comedores', $this->nuevoComedor(['rfc' => 'no-es-rfc'] + $base))->assertStatus(422)->assertJsonStructure(['errores' => ['rfc']]);
        $this->postJson('/api/v1/admin/comedores', ['nombre' => 'Sin datos'])->assertStatus(422)->assertJsonStructure(['errores' => ['contacto_correo', 'telefono', 'zona', 'precio_por_platillo']]);

        $this->assertSame([1, 1, $usuarios], [Provider::count(), ContratoComedor::count(), User::count()], 'ningún intento dejó filas sueltas');
        Mail::assertQueued(InvitacionDeAcceso::class, 1);
    }

    public function test_el_comedor_nuevo_ya_aparece_en_el_listado_y_puede_tener_platillos(): void
    {
        $this->comoRol('admin');
        $id = $this->postJson('/api/v1/admin/comedores', $this->nuevoComedor())->assertCreated()->json('id');

        $this->getJson('/api/v1/admin/comedores')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $id);
        $this->postJson('/api/v1/admin/platillos', ['comedor_id' => $id, 'nombre' => 'Caldo', 'tiempo_comida' => 'comida', 'precio_venta' => 70])->assertCreated();
    }

    public function test_dar_de_alta_comedores_es_solo_del_administrador(): void
    {
        foreach (['support', 'company', 'provider', 'customer'] as $rol) {
            $this->comoRol($rol);
            $this->postJson('/api/v1/admin/comedores', $this->nuevoComedor())->assertStatus(403);
        }
        $this->assertSame(0, Provider::count());
    }

    // ------------------------------------------------------------------ planes

    public function test_crea_un_plan_cuando_no_hay_uno_activo_de_esa_duracion(): void
    {
        $admin = $this->comoRol('admin');

        $this->postJson('/api/v1/admin/paquetes', ['nombre' => 'Semana completa', 'dias' => 6, 'descuento_porcentaje' => 12.5, 'detalle' => '6 comidas', 'destacado' => true])->assertCreated()
            ->assertJsonPath('nombre', 'Semana completa')->assertJsonPath('dias', 6)->assertJsonPath('descuento_porcentaje', 12.5)->assertJsonPath('destacado', true);

        $this->assertTrue(Paquete::sole()->activo);
        $this->assertSame($admin->id, RegistroAuditoria::where('accion', 'crear')->where('entidad', 'paquetes')->sole()->usuario_id);
        $this->getJson('/api/v1/paquetes')->assertOk()->assertJsonCount(1)->assertJsonPath('0.nombre', 'Semana completa');
    }

    public function test_no_crea_un_plan_repetido_de_una_duracion_ya_activa_con_dias_raros_o_descuento_invalido(): void
    {
        $this->comoRol('admin');
        Paquete::create(['nombre' => 'Un día', 'dias' => 1, 'descuento_porcentaje' => 0]);

        $this->postJson('/api/v1/admin/paquetes', ['nombre' => 'otro de un día', 'dias' => 1, 'descuento_porcentaje' => 5])->assertStatus(422)->assertJsonPath('codigo', 'plan_de_esos_dias_activo');
        $this->postJson('/api/v1/admin/paquetes', ['nombre' => 'UN DÍA', 'dias' => 2, 'descuento_porcentaje' => 5])->assertStatus(422)->assertJsonPath('codigo', 'plan_repetido');
        $this->postJson('/api/v1/admin/paquetes', ['nombre' => 'Tres días', 'dias' => 3, 'descuento_porcentaje' => 5])->assertStatus(422)->assertJsonStructure(['errores' => ['dias']]);
        $this->postJson('/api/v1/admin/paquetes', ['nombre' => 'Dos días', 'dias' => 2, 'descuento_porcentaje' => 101])->assertStatus(422)->assertJsonStructure(['errores' => ['descuento_porcentaje']]);
        $this->postJson('/api/v1/admin/paquetes', [])->assertStatus(422)->assertJsonStructure(['errores' => ['nombre', 'dias', 'descuento_porcentaje']]);

        $this->assertSame(1, Paquete::count());
    }

    public function test_desactivar_un_plan_permite_crear_otro_de_esa_duracion_y_reactivar_el_viejo_ya_no(): void
    {
        $this->comoRol('admin');
        $viejo = Paquete::create(['nombre' => 'Semana', 'dias' => 6, 'descuento_porcentaje' => 10]);

        $this->patchJson("/api/v1/admin/paquetes/{$viejo->id}/estado", ['activo' => false])->assertOk();
        $this->assertNotNull($viejo->fresh()->desactivado_en);
        $this->getJson('/api/v1/paquetes')->assertOk()->assertJsonCount(0);

        $nuevo = $this->postJson('/api/v1/admin/paquetes', ['nombre' => 'Semana 2027', 'dias' => 6, 'descuento_porcentaje' => 15])->assertCreated()->json('id');
        $this->patchJson("/api/v1/admin/paquetes/{$viejo->id}/estado", ['activo' => true])->assertStatus(422)->assertJsonPath('codigo', 'plan_de_esos_dias_activo');

        $this->patchJson("/api/v1/admin/paquetes/{$nuevo}/estado", ['activo' => false])->assertOk();
        $this->patchJson("/api/v1/admin/paquetes/{$viejo->id}/estado", ['activo' => true])->assertOk()->assertJsonPath('nombre', 'Semana');
        $this->patchJson("/api/v1/admin/paquetes/{$viejo->id}/estado", [])->assertStatus(422);
        $this->patchJson('/api/v1/admin/paquetes/999999/estado', ['activo' => false])->assertStatus(404);
    }

    public function test_crear_y_desactivar_planes_es_solo_del_administrador(): void
    {
        $plan = Paquete::create(['nombre' => 'Semana', 'dias' => 6, 'descuento_porcentaje' => 10]);
        foreach (['support', 'company', 'provider', 'customer'] as $rol) {
            $this->comoRol($rol);
            $this->postJson('/api/v1/admin/paquetes', ['nombre' => 'Nuevo', 'dias' => 1, 'descuento_porcentaje' => 5])->assertStatus(403);
            $this->patchJson("/api/v1/admin/paquetes/{$plan->id}/estado", ['activo' => false])->assertStatus(403);
        }
        $this->assertTrue($plan->fresh()->activo);
        $this->assertSame(1, DB::table('paquetes')->count());
    }
}
