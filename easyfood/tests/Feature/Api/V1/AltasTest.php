<?php

namespace Tests\Feature\Api\V1;

use App\Mail\InvitacionDeAcceso;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\Invitacion;
use App\Models\RegistroAuditoria;
use App\Models\Repartidor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** Las altas: empresa (con contacto y contrato), repartidor y empleados de una empresa. «Hoy» es el lunes 9 de nov de 2026. */
class AltasTest extends TestCase
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

    private function nuevaEmpresa(array $extra = []): array
    {
        return $extra + [
            'nombre' => 'Textiles del Pacífico', 'contacto_nombre' => 'Lucía Andrade', 'contacto_correo' => 'Lucia@Pacifico-Textil.mx', 'contacto_telefono' => '33 1234 5678',
            'direccion_entrega' => 'Av. Hilanderos 88, Tlaquepaque', 'zona' => 'zapopan', 'vigencia_inicio' => '2026-12-01', 'compra_minima_mensual' => 250, 'porcentaje_subsidio' => 55,
            'ventana_entrega_inicio' => '12:30', 'ventana_entrega_fin' => '13:15',
        ];
    }

    private function nuevoRepartidor(array $extra = []): array
    {
        return $extra + ['nombre' => 'Héctor Jiménez', 'correo' => 'hector@ejemplo.mx', 'telefono' => '33 9999 0000', 'placas' => 'jal-123-a', 'zona_base' => 'centro'];
    }

    // ------------------------------------------------------------------ empresa

    public function test_el_administrador_da_de_alta_una_empresa_con_su_contacto_su_contrato_y_su_invitacion(): void
    {
        $admin = $this->comoRol('admin');

        $r = $this->postJson('/api/v1/admin/empresas', $this->nuevaEmpresa())->assertCreated()->json();

        $this->assertSame(['Textiles del Pacífico', 'Zapopan', 'pacifico-textil.mx', true], [$r['nombre'], $r['zona'], $r['dominio'], $r['activo']], 'la zona queda con el nombre cargado; el dominio sale del correo');
        $this->assertSame([55.0, 250, 130.0, '2026-12-01', '2027-05-31', '12:30'], [(float) $r['contrato']['porcentaje_subsidio'], $r['contrato']['compra_minima_mensual'], (float) $r['contrato']['precio_por_platillo'],
            $r['contrato']['vigencia_inicio'], $r['contrato']['vigencia_fin'], $r['contrato']['hora_entrega']], 'contrato de 6 meses con el precio por defecto');
        $this->assertSame(['Lucía Andrade', 'lucia@pacifico-textil.mx'], [$r['contacto']['nombre'], $r['contacto']['correo']]);
        $contacto = User::where('email', 'lucia@pacifico-textil.mx')->firstOrFail();
        $this->assertSame(['inactive', 'company'], [$contacto->status, $contacto->role->name]);
        $this->assertSame($contacto->id, Empresa::sole()->usuario_id);
        $this->assertSame('33 1234 5678', $contacto->profile->phone);
        Mail::assertSent(InvitacionDeAcceso::class, fn ($m) => $m->hasTo('lucia@pacifico-textil.mx') && str_contains($m->url, '/empresas/aceptar-invitacion?token='));
        $this->assertSame($admin->id, RegistroAuditoria::where('accion', 'crear')->where('entidad', 'empresas')->sole()->usuario_id);
    }

    public function test_el_contacto_acepta_la_invitacion_y_entra_a_los_datos_de_su_empresa(): void
    {
        $this->comoRol('admin');
        $id = $this->postJson('/api/v1/admin/empresas', $this->nuevaEmpresa())->assertCreated()->json('id');
        preg_match('/token=([A-Za-z0-9]+)/', Mail::sent(InvitacionDeAcceso::class)->last()->url, $m);

        $this->postJson("/api/v1/cuentas/invitaciones/{$m[1]}/aceptar", ['contrasena' => 'Clave1234'])->assertOk()->assertJsonPath('portal', 'empresas')->assertJsonPath('organizacion_nombre', 'Textiles del Pacífico');
        $this->postJson('/api/auth/login', ['email' => 'lucia@pacifico-textil.mx', 'password' => 'Clave1234'])->assertOk();

        Sanctum::actingAs(User::where('email', 'lucia@pacifico-textil.mx')->firstOrFail());
        $this->getJson('/api/v1/empresa/perfil')->assertOk()->assertJsonPath('id', $id);
    }

    public function test_la_empresa_nueva_aparece_en_contratos_nuevos_del_mes_si_empieza_este_mes(): void
    {
        $this->comoRol('admin');
        $this->postJson('/api/v1/admin/empresas', $this->nuevaEmpresa(['vigencia_inicio' => '2026-11-02']))->assertCreated();

        $this->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('indicadores.contratos_nuevos.cantidad', 1)->assertJsonPath('indicadores.contratos_nuevos.contratos.0.empresa', 'Textiles del Pacífico');
    }

    public function test_valida_el_alta_de_empresa_y_no_deja_nada_a_medias(): void
    {
        $this->comoRol('admin');
        $this->postJson('/api/v1/admin/empresas', $this->nuevaEmpresa())->assertCreated();
        $usuarios = User::count();

        $this->postJson('/api/v1/admin/empresas', $this->nuevaEmpresa(['contacto_correo' => 'otra@ejemplo.mx', 'nombre' => 'TEXTILES DEL PACÍFICO']))->assertStatus(422)->assertJsonPath('codigo', 'empresa_repetida');
        $this->postJson('/api/v1/admin/empresas', $this->nuevaEmpresa(['nombre' => 'Otra', 'zona' => 'Narnia']))->assertStatus(422)->assertJsonPath('codigo', 'zona_inexistente')
            ->assertJsonPath('mensaje', 'Esa zona no existe. Zonas disponibles: Centro, Zapopan.');
        $this->postJson('/api/v1/admin/empresas', $this->nuevaEmpresa(['nombre' => 'Otra']))->assertStatus(422)->assertJsonPath('codigo', 'correo_en_uso'); // el contacto ya existe
        $this->postJson('/api/v1/admin/empresas', $this->nuevaEmpresa(['nombre' => 'Otra', 'contacto_correo' => 'x@ejemplo.mx', 'porcentaje_subsidio' => 101]))->assertStatus(422)->assertJsonStructure(['errores' => ['porcentaje_subsidio']]);
        $this->postJson('/api/v1/admin/empresas', $this->nuevaEmpresa(['nombre' => 'Otra', 'contacto_correo' => 'x@ejemplo.mx', 'compra_minima_mensual' => 0]))->assertStatus(422)->assertJsonStructure(['errores' => ['compra_minima_mensual']]);
        $this->postJson('/api/v1/admin/empresas', $this->nuevaEmpresa(['nombre' => 'Otra', 'contacto_correo' => 'x@ejemplo.mx', 'ventana_entrega_fin' => '12:00']))->assertStatus(422)->assertJsonStructure(['errores' => ['ventana_entrega_fin']]);
        $this->postJson('/api/v1/admin/empresas', ['nombre' => 'Sin datos'])->assertStatus(422)->assertJsonStructure(['errores' => ['contacto_correo', 'zona', 'vigencia_inicio']]);

        $this->assertSame([1, 1, $usuarios], [Empresa::count(), DB::table('contratos_empresa')->count(), User::count()], 'ningún intento dejó filas sueltas');
        Mail::assertSent(InvitacionDeAcceso::class, 1);
    }

    public function test_solo_el_administrador_da_de_alta_empresas_y_repartidores(): void
    {
        $this->postJson('/api/v1/admin/empresas', $this->nuevaEmpresa())->assertStatus(401);
        foreach (['support', 'customer', 'company', 'provider', 'employee'] as $rol) {
            $this->comoRol($rol);
            $this->postJson('/api/v1/admin/empresas', $this->nuevaEmpresa())->assertStatus(403);
            $this->postJson('/api/v1/admin/repartidores', $this->nuevoRepartidor())->assertStatus(403);
        }
        $this->assertSame(0, Empresa::count() + Repartidor::count());
    }

    // ------------------------------------------------------------------ repartidor

    public function test_da_de_alta_a_un_repartidor_con_su_cuenta_su_ficha_y_su_invitacion(): void
    {
        $this->comoRol('admin');

        $r = $this->postJson('/api/v1/admin/repartidores', $this->nuevoRepartidor())->assertCreated()->json();

        $this->assertSame(['Héctor Jiménez', 'R-001', 'JAL-123-A', 'Centro', 'libre'], [$r['nombre'], $r['numero_empleado'], $r['placas'], $r['zona_base'], $r['estado']], 'placas en mayúsculas; zona con su nombre cargado');
        $usuario = User::where('email', 'hector@ejemplo.mx')->firstOrFail();
        $this->assertSame(['inactive', 'driver'], [$usuario->status, $usuario->role->name]);
        $this->assertSame('33 9999 0000', $usuario->profile->phone);
        $this->assertSame(1, Invitacion::where('usuario_id', $usuario->id)->count());
        Mail::assertSent(InvitacionDeAcceso::class, fn ($m) => $m->hasTo('hector@ejemplo.mx'));
    }

    public function test_el_numero_de_empleado_se_asigna_el_siguiente_o_se_respeta_el_que_se_mande(): void
    {
        $this->comoRol('admin');
        $this->postJson('/api/v1/admin/repartidores', $this->nuevoRepartidor())->assertCreated()->assertJsonPath('numero_empleado', 'R-001');
        $this->postJson('/api/v1/admin/repartidores', $this->nuevoRepartidor(['correo' => 'b@ejemplo.mx']))->assertCreated()->assertJsonPath('numero_empleado', 'R-002');
        $this->postJson('/api/v1/admin/repartidores', $this->nuevoRepartidor(['correo' => 'c@ejemplo.mx', 'numero_empleado' => 'EMP-77']))->assertCreated()->assertJsonPath('numero_empleado', 'EMP-77');
        $this->postJson('/api/v1/admin/repartidores', $this->nuevoRepartidor(['correo' => 'd@ejemplo.mx', 'numero_empleado' => 'R-001']))->assertStatus(422)->assertJsonPath('codigo', 'numero_de_empleado_repetido');
    }

    public function test_solo_se_reparte_en_auto_y_se_validan_las_placas_la_zona_y_el_correo(): void
    {
        $this->comoRol('admin');

        $this->postJson('/api/v1/admin/repartidores', $this->nuevoRepartidor(['tipo_vehiculo' => 'bicicleta']))->assertStatus(422)->assertJsonPath('codigo', 'solo_automovil');
        $this->postJson('/api/v1/admin/repartidores', $this->nuevoRepartidor(['tipo_vehiculo' => 'automovil']))->assertCreated();
        $this->postJson('/api/v1/admin/repartidores', $this->nuevoRepartidor(['correo' => 'b@ejemplo.mx', 'placas' => '']))->assertStatus(422)->assertJsonStructure(['errores' => ['placas']]);
        $this->postJson('/api/v1/admin/repartidores', $this->nuevoRepartidor(['correo' => 'b@ejemplo.mx', 'zona_base' => 'Marte']))->assertStatus(422)->assertJsonPath('codigo', 'zona_inexistente');
        $this->postJson('/api/v1/admin/repartidores', $this->nuevoRepartidor())->assertStatus(422)->assertJsonPath('codigo', 'correo_en_uso');
        $this->postJson('/api/v1/admin/repartidores', $this->nuevoRepartidor(['correo' => 'no-es-correo']))->assertStatus(422)->assertJsonStructure(['errores' => ['correo']]);
        $this->assertSame(1, Repartidor::count());
    }

    public function test_el_repartidor_nuevo_aparece_en_la_lista_y_ya_cuenta_para_las_rutas(): void
    {
        $this->comoRol('admin');
        $this->postJson('/api/v1/admin/repartidores', $this->nuevoRepartidor())->assertCreated();

        $this->getJson('/api/v1/admin/repartidores')->assertOk()->assertJsonCount(1)->assertJsonPath('0.zona_base', 'Centro');
    }

    // ------------------------------------------------------------------ empleados

    public function test_la_empresa_agrega_empleados_y_cada_uno_recibe_su_invitacion(): void
    {
        $empresa = $this->comoEmpresa('Zapopan');
        $empresa->update(['dominio' => 'nexo.mx']);

        $r = $this->postJson('/api/v1/empresa/empleados', ['personas' => [
            ['nombre' => 'Ana Ruiz', 'correo' => 'Ana.Ruiz@nexo.mx'], ['nombre' => 'José Núñez'], ['nombre' => 'Ana de nuevo', 'correo' => 'ana.ruiz@nexo.mx'],
        ]])->assertCreated()->json();

        $this->assertSame([2, 1], [$r['agregados'], $r['omitidos']], 'el correo repetido en la lista se omite');
        $empleados = Empleado::where('empresa_id', $empresa->id)->with('usuario.role')->get();
        $this->assertEqualsCanonicalizing(['ana.ruiz@nexo.mx', 'jose.nunez@nexo.mx'], $empleados->pluck('usuario.email')->all(), 'sin correo se arma con el nombre y el dominio');
        $this->assertSame(['employee', 'inactive'], [$empleados[0]->usuario->role->name, $empleados[0]->usuario->status]);
        $this->assertTrue($empleados->every(fn ($e) => $e->activo));
        Mail::assertSent(InvitacionDeAcceso::class, 2);
        Mail::assertSent(InvitacionDeAcceso::class, fn ($m) => str_contains($m->url, '/empleados/aceptar-invitacion?token='));
        $this->assertSame(1, RegistroAuditoria::where('accion', 'crear')->where('entidad', 'empleados')->count());
    }

    public function test_un_correo_que_ya_tiene_cuenta_se_omite_sin_romper_el_resto(): void
    {
        $empresa = $this->comoEmpresa('Zapopan');
        $this->usuarioDeRol('customer', ['email' => 'ocupado@nexo.mx']);

        $r = $this->postJson('/api/v1/empresa/empleados', ['personas' => [['nombre' => 'Ocupado Pérez', 'correo' => 'ocupado@nexo.mx'], ['nombre' => 'Libre Pérez', 'correo' => 'libre@nexo.mx']]])->assertCreated()->json();

        $this->assertSame([1, 1], [$r['agregados'], $r['omitidos']]);
        $this->assertSame(1, Empleado::where('empresa_id', $empresa->id)->count());
    }

    public function test_el_empleado_acepta_la_invitacion_y_entra_a_su_portal_con_su_empresa(): void
    {
        $empresa = $this->comoEmpresa('Zapopan');
        $this->postJson('/api/v1/empresa/empleados', ['personas' => [['nombre' => 'Ana Ruiz', 'correo' => 'ana@nexo.mx']]])->assertCreated();
        preg_match('/token=([A-Za-z0-9]+)/', Mail::sent(InvitacionDeAcceso::class)->last()->url, $m);

        $this->postJson("/api/v1/cuentas/invitaciones/{$m[1]}/aceptar", ['contrasena' => 'Clave1234'])->assertOk()->assertJsonPath('portal', 'empleados');
        $this->postJson('/api/auth/login', ['email' => 'ana@nexo.mx', 'password' => 'Clave1234'])->assertOk()->assertJsonPath('user.role', 'employee');

        Sanctum::actingAs(User::where('email', 'ana@nexo.mx')->firstOrFail());
        $this->getJson('/api/v1/empleado/elecciones')->assertOk();
        $this->assertSame($empresa->id, Empleado::where('usuario_id', User::where('email', 'ana@nexo.mx')->value('id'))->value('empresa_id'));
    }

    public function test_valida_la_lista_de_empleados_y_solo_la_empresa_la_agrega(): void
    {
        $this->comoEmpresa('Zapopan');

        $this->postJson('/api/v1/empresa/empleados', ['personas' => []])->assertStatus(422)->assertJsonStructure(['errores' => ['personas']]);
        $this->postJson('/api/v1/empresa/empleados', ['personas' => [['nombre' => 'A']]])->assertStatus(422)->assertJsonStructure(['errores' => ['personas.0.nombre']]);
        $this->postJson('/api/v1/empresa/empleados', ['personas' => [['nombre' => 'Ana Ruiz', 'correo' => 'no-es-correo']]])->assertStatus(422)->assertJsonStructure(['errores' => ['personas.0.correo']]);
        $this->postJson('/api/v1/empresa/empleados', ['personas' => array_fill(0, 501, ['nombre' => 'Ana Ruiz'])])->assertStatus(422);
        $this->assertSame(0, Empleado::count());

        foreach (['admin', 'customer', 'employee', 'provider'] as $rol) {
            $this->comoRol($rol);
            $this->postJson('/api/v1/empresa/empleados', ['personas' => [['nombre' => 'Ana Ruiz']]])->assertStatus(403);
        }
        $this->assertSame(0, Empleado::count());
    }

    public function test_cada_empresa_solo_agrega_empleados_a_la_suya(): void
    {
        $mia = $this->comoEmpresa('Zapopan');
        $otra = $this->empresaConContrato('Centro');

        $this->postJson('/api/v1/empresa/empleados', ['personas' => [['nombre' => 'Ana Ruiz', 'correo' => 'ana@ejemplo.mx']], 'empresa_id' => $otra->id])->assertCreated();

        $this->assertSame([1, 0], [Empleado::where('empresa_id', $mia->id)->count(), Empleado::where('empresa_id', $otra->id)->count()]);
    }
}
