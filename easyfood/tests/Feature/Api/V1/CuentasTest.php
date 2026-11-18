<?php

namespace Tests\Feature\Api\V1;

use App\Mail\InvitacionDeAcceso;
use App\Mail\RestablecerContrasena;
use App\Models\Invitacion;
use App\Models\Restablecimiento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Cuentas: invitar a una persona, aceptar la invitación y restablecer la contraseña. Los correos se capturan (Mail::fake) y los enlaces se
 * leen de ahí o de storage/logs/enlaces.log. «Hoy» es el lunes 9 de nov de 2026 a las 10:00.
 */
class CuentasTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->ahoraEs('2026-11-09 10:00');
        // El log de enlaces de esta prueba va a un archivo temporal, aparte del real.
        $this->log = sys_get_temp_dir().'/enlaces-prueba-'.uniqid().'.log';
        config(['logging.channels.enlaces.path' => $this->log]);
        Log::forgetChannel('enlaces');
    }

    protected function tearDown(): void
    {
        File::delete($this->log);
        parent::tearDown();
    }

    /** El token del enlace que viajó en el último correo de invitación. */
    private function tokenDeLaInvitacion(): string
    {
        $mail = Mail::queued(InvitacionDeAcceso::class)->last();
        preg_match('/token=([A-Za-z0-9]+)/', $mail->url, $m);

        return $m[1];
    }

    private function invitar(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/cuentas/invitaciones', $extra + ['organizacion_tipo' => 'plataforma', 'nombre' => 'Sofía Ramírez', 'correo' => 'Sofia@Ejemplo.mx', 'rol' => 'soporte']);
    }

    // ------------------------------------------------------------------ invitar

    public function test_el_administrador_invita_y_se_crea_una_cuenta_inactiva_con_su_correo_y_su_enlace(): void
    {
        $admin = $this->comoRol('admin');

        $r = $this->invitar()->assertCreated()->json();

        $this->assertSame(['Sofía Ramírez', 'sofia@ejemplo.mx', 'soporte', false, false], [$r['nombre'], $r['correo'], $r['rol'], $r['activo'], $r['es_principal']]);
        $this->assertSame(['enviada', 'plataforma'], [$r['invitacion']['estado'], $r['invitacion']['organizacion_tipo']]);
        $this->assertSame('', $r['invitacion']['token'], 'el enlace no se devuelve: solo viaja en el correo');
        $usuario = User::where('email', 'sofia@ejemplo.mx')->firstOrFail();
        $this->assertSame(['inactive', 'support'], [$usuario->status, $usuario->role->name]);
        Mail::assertQueued(InvitacionDeAcceso::class, fn ($m) => $m->hasTo('sofia@ejemplo.mx') && str_contains($m->url, '/admin/aceptar-invitacion?token='));
        $this->assertSame(1, \App\Models\RegistroAuditoria::where('accion', 'invitar')->where('usuario_id', $admin->id)->count(), 'la bitácora dice quién invitó');
    }

    public function test_el_enlace_se_ve_facil_en_el_log_de_enlaces_pero_la_base_solo_guarda_su_hash(): void
    {
        $this->comoRol('admin');
        $this->invitar()->assertCreated();
        $token = $this->tokenDeLaInvitacion();

        $this->assertStringContainsString("INVITACIÓN para sofia@ejemplo.mx\nhttp://localhost:3000/admin/aceptar-invitacion?token={$token}", file_get_contents($this->log));
        $guardado = Invitacion::sole()->makeVisible('token_hash')->token_hash;
        $this->assertSame(hash('sha256', $token), $guardado);
        $this->assertStringNotContainsString($token, json_encode(DB::table('invitaciones')->get()), 'el enlace no está en la base');
    }

    public function test_en_produccion_el_enlace_no_se_escribe_en_ningun_log(): void
    {
        $this->comoRol('admin');
        $this->app['env'] = 'production';

        $this->invitar()->assertCreated();

        $this->assertFileDoesNotExist($this->log);
        Mail::assertQueued(InvitacionDeAcceso::class); // el correo sí sale
    }

    public function test_el_enlace_va_al_portal_del_rol(): void
    {
        $this->comoRol('admin');
        $empresa = $this->empresaConContrato();
        $comedor = $this->comedorConContrato();

        $this->invitar(['organizacion_tipo' => 'empresa', 'organizacion_id' => $empresa->id, 'rol' => 'empresa', 'correo' => 'a@ejemplo.mx'])->assertCreated();
        $this->invitar(['organizacion_tipo' => 'comedor', 'organizacion_id' => $comedor->id, 'rol' => 'comedor', 'correo' => 'b@ejemplo.mx'])->assertCreated();

        $urls = Mail::queued(InvitacionDeAcceso::class)->map(fn ($m) => parse_url($m->url, PHP_URL_PATH))->all();
        $this->assertSame(['/empresas/aceptar-invitacion', '/comedores/aceptar-invitacion'], $urls);
    }

    public function test_valida_lo_que_se_manda_y_no_repite_correos(): void
    {
        $this->comoRol('admin');
        $this->invitar()->assertCreated();

        $this->invitar(['correo' => 'SOFIA@ejemplo.mx'])->assertStatus(422)->assertJsonPath('codigo', 'correo_en_uso');
        $this->invitar(['correo' => 'no-es-correo'])->assertStatus(422)->assertJsonStructure(['errores' => ['correo']]);
        $this->invitar(['nombre' => 'A', 'correo' => 'x@ejemplo.mx'])->assertStatus(422)->assertJsonStructure(['errores' => ['nombre']]);
        $this->invitar(['rol' => 'empresa', 'correo' => 'y@ejemplo.mx'])->assertStatus(422)->assertJsonPath('codigo', 'rol_no_corresponde');
        $this->invitar(['rol' => 'empleado', 'correo' => 'z@ejemplo.mx'])->assertStatus(422)->assertJsonPath('codigo', 'rol_no_corresponde');
        $this->invitar(['organizacion_tipo' => 'empresa', 'organizacion_id' => 99999, 'rol' => 'empresa', 'correo' => 'w@ejemplo.mx'])->assertStatus(422)->assertJsonPath('codigo', 'organizacion_inexistente');
        $this->invitar(['organizacion_tipo' => 'empresa', 'rol' => 'empresa', 'correo' => 'v@ejemplo.mx'])->assertStatus(422)->assertJsonStructure(['errores' => ['organizacion_id']]);
        $this->assertSame(1, Invitacion::count());
        Mail::assertQueued(InvitacionDeAcceso::class, 1);
    }

    public function test_cada_quien_invita_solo_dentro_de_lo_suyo(): void
    {
        $empresa = $this->comoEmpresa();
        $otra = $this->empresaConContrato('Centro');
        $comedor = $this->comedorConContrato();

        $this->invitar(['organizacion_tipo' => 'empresa', 'organizacion_id' => $empresa->id, 'rol' => 'empresa'])->assertCreated();
        $this->invitar(['organizacion_tipo' => 'empresa', 'organizacion_id' => $otra->id, 'rol' => 'empresa', 'correo' => 'o@ejemplo.mx'])->assertStatus(403);
        $this->invitar(['organizacion_tipo' => 'comedor', 'organizacion_id' => $comedor->id, 'rol' => 'comedor', 'correo' => 'c@ejemplo.mx'])->assertStatus(403);
        $this->invitar(['rol' => 'administrador', 'correo' => 'p@ejemplo.mx'])->assertStatus(403);

        $this->comoRol('customer');
        $this->invitar()->assertStatus(403);
        $this->comoRol('employee');
        $this->invitar()->assertStatus(403);
        $this->comoRol('support');
        $this->invitar()->assertStatus(403);
        $this->assertSame(1, Invitacion::count());
    }

    public function test_sin_sesion_no_se_invita_ni_se_ve_quien_tiene_acceso(): void
    {
        $this->invitar()->assertStatus(401);
        $this->getJson('/api/v1/cuentas/usuarios-acceso?tipo=plataforma')->assertStatus(401);
    }

    // ------------------------------------------------------------------ usuarios con acceso

    public function test_lista_a_la_titular_y_a_las_personas_invitadas_con_el_estado_de_su_invitacion(): void
    {
        $empresa = $this->comoEmpresa();
        $this->invitar(['organizacion_tipo' => 'empresa', 'organizacion_id' => $empresa->id, 'rol' => 'empresa', 'correo' => 'uno@ejemplo.mx', 'nombre' => 'Uno Pérez'])->assertCreated();
        $this->ahoraEs('2026-11-20 10:00'); // la invitación (7 días) ya venció
        $this->invitar(['organizacion_tipo' => 'empresa', 'organizacion_id' => $empresa->id, 'rol' => 'empresa', 'correo' => 'dos@ejemplo.mx', 'nombre' => 'Dos Pérez'])->assertCreated();

        $filas = $this->getJson("/api/v1/cuentas/usuarios-acceso?tipo=empresa&id={$empresa->id}")->assertOk()->json();

        $this->assertSame([true, false, false], array_column($filas, 'es_principal'), 'la titular primero y luego las invitadas, la más reciente antes');
        $this->assertSame([null, 'enviada', 'vencida'], array_map(fn ($f) => $f['invitacion']['estado'] ?? null, $filas));
        $this->assertSame(['dos@ejemplo.mx', 'uno@ejemplo.mx'], array_column(array_slice($filas, 1), 'correo'));
        $this->getJson('/api/v1/cuentas/usuarios-acceso?tipo=empresa')->assertStatus(422);
    }

    public function test_no_se_ve_quien_tiene_acceso_a_otra_organizacion(): void
    {
        $this->comoEmpresa();
        $otra = $this->empresaConContrato('Centro');

        $this->getJson("/api/v1/cuentas/usuarios-acceso?tipo=empresa&id={$otra->id}")->assertStatus(403);
        $this->getJson('/api/v1/cuentas/usuarios-acceso?tipo=plataforma')->assertStatus(403);
    }

    public function test_la_lista_de_la_plataforma_trae_a_administracion_y_soporte(): void
    {
        $this->comoRol('admin');
        $this->usuarioDeRol('support', ['name' => 'Sara Soporte']);
        $this->invitar()->assertCreated();

        $filas = $this->getJson('/api/v1/cuentas/usuarios-acceso?tipo=plataforma')->assertOk()->json();

        $this->assertEqualsCanonicalizing(['administrador', 'soporte', 'soporte'], array_column($filas, 'rol'));
        $this->assertSame(2, count(array_filter($filas, fn ($f) => $f['es_principal'])), 'quienes ya estaban no tienen invitación');
    }

    // ------------------------------------------------------------------ reenviar

    public function test_reenviar_da_un_enlace_nuevo_y_el_anterior_deja_de_servir(): void
    {
        $this->comoRol('admin');
        $id = $this->invitar()->json('invitacion.id');
        $viejo = $this->tokenDeLaInvitacion();
        $this->ahoraEs('2026-11-12 10:00');

        $r = $this->postJson("/api/v1/cuentas/invitaciones/{$id}/reenviar")->assertOk()->json();

        $this->assertSame(['enviada', '2026-11-19'], [$r['estado'], substr($r['vence_en'], 0, 10)]);
        $nuevo = $this->tokenDeLaInvitacion();
        $this->assertNotSame($viejo, $nuevo);
        Mail::assertQueued(InvitacionDeAcceso::class, 2);
        $this->getJson("/api/v1/cuentas/invitaciones/{$viejo}")->assertOk()->assertJsonPath('estado', 'invalida');
        $this->getJson("/api/v1/cuentas/invitaciones/{$nuevo}")->assertOk()->assertJsonPath('estado', 'enviada');
    }

    public function test_reenviar_una_invitacion_vencida_la_revive_y_una_aceptada_no_se_reenvia(): void
    {
        $this->comoRol('admin');
        $id = $this->invitar()->json('invitacion.id');
        $this->ahoraEs('2026-11-30 10:00');
        $this->getJson('/api/v1/cuentas/usuarios-acceso?tipo=plataforma')->assertJsonPath('1.invitacion.estado', 'vencida');

        $this->postJson("/api/v1/cuentas/invitaciones/{$id}/reenviar")->assertOk()->assertJsonPath('estado', 'enviada');

        $this->postJson("/api/v1/cuentas/invitaciones/{$this->tokenDeLaInvitacion()}/aceptar", ['contrasena' => 'Clave1234'])->assertOk();
        $this->postJson("/api/v1/cuentas/invitaciones/{$id}/reenviar")->assertStatus(422)->assertJsonPath('codigo', 'invitacion_aceptada');
    }

    public function test_solo_quien_administra_esa_organizacion_reenvia_su_invitacion(): void
    {
        $this->comoRol('admin');
        $empresa = $this->empresaConContrato();
        $id = $this->invitar(['organizacion_tipo' => 'empresa', 'organizacion_id' => $empresa->id, 'rol' => 'empresa'])->json('invitacion.id');

        $this->comoEmpresa('Centro');
        $this->postJson("/api/v1/cuentas/invitaciones/{$id}/reenviar")->assertStatus(403);
        $this->comoRol('customer');
        $this->postJson("/api/v1/cuentas/invitaciones/{$id}/reenviar")->assertStatus(403);
        $this->comoRol('admin');
        $this->postJson('/api/v1/cuentas/invitaciones/99999/reenviar')->assertStatus(404);
    }

    // ------------------------------------------------------------------ aceptar (público)

    public function test_el_enlace_del_correo_muestra_de_quien_es_y_a_donde_entra(): void
    {
        $this->comoRol('admin');
        $empresa = $this->empresaConContrato();
        $this->invitar(['organizacion_tipo' => 'empresa', 'organizacion_id' => $empresa->id, 'rol' => 'empresa', 'nombre' => 'Rita Hernández'])->assertCreated();
        Sanctum::actingAs($this->usuarioDeRol('customer')); // el enlace es público: no importa quién lo abra

        $r = $this->getJson("/api/v1/cuentas/invitaciones/{$this->tokenDeLaInvitacion()}")->assertOk()->json();

        $this->assertSame(['enviada', 'Rita Hernández', $empresa->nombre, 'empresa', 'empresa', 'empresas'], [$r['estado'], $r['nombre_invitado'], $r['organizacion_nombre'], $r['organizacion_tipo'], $r['rol'], $r['portal']]);
        $this->assertSame('invalida', $this->getJson('/api/v1/cuentas/invitaciones/inventado')->assertOk()->json('estado'));
    }

    public function test_aceptar_activa_la_cuenta_con_la_contrasena_elegida_y_ya_entra_al_portal_de_su_rol(): void
    {
        $this->comoRol('admin');
        $empresa = $this->empresaConContrato();
        $this->invitar(['organizacion_tipo' => 'empresa', 'organizacion_id' => $empresa->id, 'rol' => 'empresa', 'correo' => 'rita@ejemplo.mx', 'nombre' => 'Rita Hernández'])->assertCreated();
        $token = $this->tokenDeLaInvitacion();
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->postJson('/api/auth/login', ['email' => 'rita@ejemplo.mx', 'password' => 'Clave1234'])->assertStatus(422); // todavía no tiene contraseña
        $r = $this->postJson("/api/v1/cuentas/invitaciones/{$token}/aceptar", ['contrasena' => 'Clave1234'])->assertOk()->json();

        $this->assertSame(['empresas', 'empresa', 'Rita Hernández', $empresa->nombre], [$r['portal'], $r['rol'], $r['nombre'], $r['organizacion_nombre']]);
        $usuario = User::where('email', 'rita@ejemplo.mx')->firstOrFail();
        $this->assertSame('active', $usuario->status);
        $this->assertTrue(Hash::check('Clave1234', $usuario->password));
        $this->assertNotNull(Invitacion::sole()->aceptada_en);
        $this->postJson('/api/auth/login', ['email' => 'rita@ejemplo.mx', 'password' => 'Clave1234'])->assertOk()->assertJsonPath('user.role', 'company');
    }

    public function test_una_persona_invitada_a_una_empresa_ya_ve_los_datos_de_esa_empresa_y_solo_de_ella(): void
    {
        $this->comoRol('admin');
        $empresa = $this->empresaConContrato();
        $otra = $this->empresaConContrato('Centro');
        $this->invitar(['organizacion_tipo' => 'empresa', 'organizacion_id' => $empresa->id, 'rol' => 'empresa', 'correo' => 'rita@ejemplo.mx'])->assertCreated();
        $this->postJson("/api/v1/cuentas/invitaciones/{$this->tokenDeLaInvitacion()}/aceptar", ['contrasena' => 'Clave1234'])->assertOk();

        Sanctum::actingAs(User::where('email', 'rita@ejemplo.mx')->firstOrFail());

        $this->getJson('/api/v1/empresa/perfil')->assertOk()->assertJsonPath('id', $empresa->id)->assertJsonPath('nombre', $empresa->nombre);
        $this->getJson("/api/v1/cuentas/usuarios-acceso?tipo=empresa&id={$empresa->id}")->assertOk();
        $this->getJson("/api/v1/cuentas/usuarios-acceso?tipo=empresa&id={$otra->id}")->assertStatus(403);
    }

    public function test_aceptar_rechaza_una_contrasena_debil_una_invitacion_vencida_o_ya_aceptada(): void
    {
        $this->comoRol('admin');
        $this->invitar()->assertCreated();
        $token = $this->tokenDeLaInvitacion();
        $url = "/api/v1/cuentas/invitaciones/{$token}/aceptar";

        $this->postJson($url, ['contrasena' => 'corta1'])->assertStatus(422)->assertJsonPath('mensaje', 'La contraseña debe tener al menos 8 caracteres.');
        $this->postJson($url, ['contrasena' => 'sololetrasaqui'])->assertStatus(422)->assertJsonPath('mensaje', 'La contraseña debe incluir letras y números.');
        $this->postJson($url, ['contrasena' => '123456789'])->assertStatus(422)->assertJsonPath('mensaje', 'La contraseña debe incluir letras y números.');
        $this->postJson('/api/v1/cuentas/invitaciones/inventado/aceptar', ['contrasena' => 'Clave1234'])->assertStatus(422)->assertJsonPath('codigo', 'invitacion_invalida');
        $this->assertSame('inactive', User::where('email', 'sofia@ejemplo.mx')->value('status'), 'nada cambió');

        $this->ahoraEs('2026-11-17 10:01');
        $this->postJson($url, ['contrasena' => 'Clave1234'])->assertStatus(422)->assertJsonPath('codigo', 'invitacion_vencida');

        $this->ahoraEs('2026-11-12 10:00');
        $this->postJson($url, ['contrasena' => 'Clave1234'])->assertOk();
        $this->postJson($url, ['contrasena' => 'OtraClave99'])->assertStatus(422)->assertJsonPath('codigo', 'invitacion_aceptada');
        $this->assertTrue(Hash::check('Clave1234', User::where('email', 'sofia@ejemplo.mx')->value('password')), 'la segunda vez no cambia la contraseña');
    }

    public function test_aceptar_deja_constancia_en_la_bitacora_a_nombre_de_quien_acepto(): void
    {
        $this->comoRol('admin');
        $this->invitar()->assertCreated();
        $this->postJson("/api/v1/cuentas/invitaciones/{$this->tokenDeLaInvitacion()}/aceptar", ['contrasena' => 'Clave1234'])->assertOk();

        $registro = \App\Models\RegistroAuditoria::where('accion', 'aceptar_invitacion')->sole();
        $this->assertSame(User::where('email', 'sofia@ejemplo.mx')->value('id'), $registro->usuario_id);
    }

    // ------------------------------------------------------------------ restablecer la contraseña

    private function conCuentaActiva(string $correo = 'rita@ejemplo.mx', string $rol = 'company'): User
    {
        return $this->usuarioDeRol($rol, ['email' => $correo, 'name' => 'Rita Hernández', 'password' => Hash::make('Vieja1234')]);
    }

    private function tokenDelRestablecimiento(int $cual = -1): string
    {
        $correos = Mail::queued(RestablecerContrasena::class);
        preg_match('/token=([A-Za-z0-9]+)/', $correos->values()[$cual < 0 ? $correos->count() - 1 : $cual]->url, $m);

        return $m[1];
    }

    public function test_pedir_el_restablecimiento_manda_el_enlace_del_portal_de_esa_persona_y_lo_deja_en_el_log(): void
    {
        $this->conCuentaActiva();

        $this->postJson('/api/v1/cuentas/restablecer/solicitar', ['correo' => 'RITA@ejemplo.mx'])->assertOk()->assertExactJson(['enviado' => true, 'enlace_simulado' => null]);

        Mail::assertQueued(RestablecerContrasena::class, fn ($m) => $m->hasTo('rita@ejemplo.mx') && str_contains($m->url, '/empresas/restablecer-contrasena?token=') && $m->minutos === 60);
        $this->assertStringContainsString('RESTABLECER CONTRASEÑA para rita@ejemplo.mx', file_get_contents($this->log));
        $this->assertSame(hash('sha256', $this->tokenDelRestablecimiento()), Restablecimiento::sole()->makeVisible('token_hash')->token_hash);
    }

    public function test_responde_igual_si_el_correo_no_existe_esta_inactivo_o_no_tiene_portal_y_no_manda_nada(): void
    {
        $this->conCuentaActiva('inactiva@ejemplo.mx')->update(['status' => 'inactive']);
        $this->conCuentaActiva('cliente@ejemplo.mx', 'customer');

        foreach (['nadie@ejemplo.mx', 'inactiva@ejemplo.mx', 'cliente@ejemplo.mx'] as $correo) {
            $this->postJson('/api/v1/cuentas/restablecer/solicitar', ['correo' => $correo])->assertOk()->assertExactJson(['enviado' => true, 'enlace_simulado' => null]);
        }

        Mail::assertNothingQueued();
        $this->assertSame(0, Restablecimiento::count());
        $this->postJson('/api/v1/cuentas/restablecer/solicitar', ['correo' => 'no-es-correo'])->assertStatus(422);
    }

    public function test_el_enlace_se_valida_y_muestra_el_correo_con_asteriscos(): void
    {
        $this->conCuentaActiva();
        $this->postJson('/api/v1/cuentas/restablecer/solicitar', ['correo' => 'rita@ejemplo.mx']);

        $this->getJson('/api/v1/cuentas/restablecer/'.$this->tokenDelRestablecimiento())->assertOk()->assertExactJson(['valido' => true, 'correo_oculto' => 'r***@ejemplo.mx']);
        $this->getJson('/api/v1/cuentas/restablecer/inventado')->assertOk()->assertExactJson(['valido' => false, 'motivo' => 'invalido']);
    }

    public function test_el_enlace_vence_a_los_60_minutos(): void
    {
        $this->conCuentaActiva();
        $this->postJson('/api/v1/cuentas/restablecer/solicitar', ['correo' => 'rita@ejemplo.mx']);
        $token = $this->tokenDelRestablecimiento();

        $this->ahoraEs('2026-11-09 10:59');
        $this->getJson("/api/v1/cuentas/restablecer/{$token}")->assertJsonPath('valido', true);
        $this->ahoraEs('2026-11-09 11:00');
        $this->getJson("/api/v1/cuentas/restablecer/{$token}")->assertExactJson(['valido' => false, 'motivo' => 'vencido']);
        $this->postJson("/api/v1/cuentas/restablecer/{$token}", ['contrasena' => 'Nueva1234'])->assertStatus(422)->assertJsonPath('codigo', 'enlace_invalido');
    }

    public function test_cambia_la_contrasena_una_sola_vez_y_cierra_las_sesiones_abiertas(): void
    {
        $usuario = $this->conCuentaActiva();
        $sesion = $usuario->createToken('abierta')->plainTextToken;
        $this->postJson('/api/v1/cuentas/restablecer/solicitar', ['correo' => 'rita@ejemplo.mx']);
        $token = $this->tokenDelRestablecimiento();

        $this->postJson("/api/v1/cuentas/restablecer/{$token}", ['contrasena' => 'Nueva1234'])->assertOk();

        $this->assertTrue(Hash::check('Nueva1234', $usuario->fresh()->password));
        $this->assertSame(0, $usuario->tokens()->count(), 'se cerraron las sesiones');
        $this->assertNotEmpty($sesion);
        $this->getJson("/api/v1/cuentas/restablecer/{$token}")->assertExactJson(['valido' => false, 'motivo' => 'usado']);
        $this->postJson("/api/v1/cuentas/restablecer/{$token}", ['contrasena' => 'Otra123456'])->assertStatus(422)->assertJsonPath('codigo', 'enlace_invalido');
        $this->assertTrue(Hash::check('Nueva1234', $usuario->fresh()->password), 'el segundo intento no cambia nada');
        $this->postJson('/api/auth/login', ['email' => 'rita@ejemplo.mx', 'password' => 'Nueva1234'])->assertOk();
        $this->assertSame($usuario->id, \App\Models\RegistroAuditoria::where('accion', 'restablecer_contrasena')->sole()->usuario_id);
    }

    public function test_pedir_otro_enlace_deja_sin_efecto_los_anteriores(): void
    {
        $this->conCuentaActiva();
        $this->postJson('/api/v1/cuentas/restablecer/solicitar', ['correo' => 'rita@ejemplo.mx']);
        $primero = $this->tokenDelRestablecimiento();
        $this->postJson('/api/v1/cuentas/restablecer/solicitar', ['correo' => 'rita@ejemplo.mx']);
        $segundo = $this->tokenDelRestablecimiento();

        $this->assertNotSame($primero, $segundo);
        $this->getJson("/api/v1/cuentas/restablecer/{$primero}")->assertExactJson(['valido' => false, 'motivo' => 'usado']);
        $this->postJson("/api/v1/cuentas/restablecer/{$primero}", ['contrasena' => 'Nueva1234'])->assertStatus(422);
        $this->postJson("/api/v1/cuentas/restablecer/{$segundo}", ['contrasena' => 'Nueva1234'])->assertOk();
    }

    public function test_la_nueva_contrasena_cumple_las_mismas_reglas(): void
    {
        $this->conCuentaActiva();
        $this->postJson('/api/v1/cuentas/restablecer/solicitar', ['correo' => 'rita@ejemplo.mx']);
        $url = '/api/v1/cuentas/restablecer/'.$this->tokenDelRestablecimiento();

        $this->postJson($url, ['contrasena' => 'corta1'])->assertStatus(422)->assertJsonPath('mensaje', 'La contraseña debe tener al menos 8 caracteres.');
        $this->postJson($url, ['contrasena' => 'sinnumeros'])->assertStatus(422)->assertJsonPath('mensaje', 'La contraseña debe incluir letras y números.');
        $this->postJson($url, [])->assertStatus(422);
        $this->getJson(substr($url, 0, -1).'x')->assertOk(); // sigue siendo un enlace desconocido, no un error
    }

    public function test_pedir_demasiados_enlaces_se_frena(): void
    {
        // Tres por hora para un mismo correo; a la cuarta se frena (ProteccionesTest cubre el detalle y el límite por IP).
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/cuentas/restablecer/solicitar', ['correo' => 'nadie@ejemplo.mx'])->assertOk();
        }

        $this->postJson('/api/v1/cuentas/restablecer/solicitar', ['correo' => 'nadie@ejemplo.mx'])->assertStatus(429);
    }

    // ------------------------------------------------------------------ la base

    public function test_la_base_exige_enlaces_con_hash_hexadecimal_y_no_deja_borrarlos(): void
    {
        $usuario = $this->usuario();
        $fila = ['usuario_id' => $usuario, 'vence_en' => '2026-11-10 10:00:00-06'];

        $this->rechaza('restablecimientos_token_hexadecimal', fn () => DB::table('restablecimientos')->insert($fila + ['token_hash' => str_repeat('z', 64)]));
        $id = DB::table('restablecimientos')->insertGetId($fila + ['token_hash' => str_repeat('a', 64)]);
        $this->rechaza('restablecimientos_token_hash_unique', fn () => DB::table('restablecimientos')->insert($fila + ['token_hash' => str_repeat('a', 64)]));
        $this->rechaza('No se puede borrar', fn () => DB::table('restablecimientos')->where('id', $id)->delete());
    }
}
