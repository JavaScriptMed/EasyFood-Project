<?php

namespace Tests\Feature\Api\V1;

use App\Models\Configuracion;
use App\Models\ContratoEmpresa;
use App\Models\Invitacion;
use App\Models\Paquete;
use App\Models\RegistroAuditoria;
use App\Models\Repartidor;
use App\Models\Ruta;
use App\Models\User;
use App\Services\Invitaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** Editar y desactivar: empresas, empleados, repartidores, capacidad del auto, planes y personas con acceso. «Hoy» es el lunes 9 de nov de 2026. */
class EdicionesTest extends TestCase
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

    private function bitacora(string $accion): RegistroAuditoria
    {
        return RegistroAuditoria::where('accion', $accion)->latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------------ empresas

    private function datosDeEmpresa(array $extra = []): array
    {
        return $extra + [
            'nombre' => 'Nexo Renombrada', 'direccion_entrega' => 'Av. Nueva 99', 'contacto_nombre' => 'Rosa Prado', 'contacto_telefono' => '33 5555 0000',
            'porcentaje_subsidio' => 40, 'compra_minima_mensual' => 300, 'ventana_entrega_inicio' => '12:00', 'ventana_entrega_fin' => '12:45',
        ];
    }

    public function test_edita_la_empresa_su_contacto_y_su_contrato_vigente(): void
    {
        $admin = $this->comoRol('admin');
        $empresa = $this->empresaConContrato('Zapopan');

        $this->putJson("/api/v1/admin/empresas/{$empresa->id}", $this->datosDeEmpresa())->assertOk()
            ->assertJsonPath('nombre', 'Nexo Renombrada')->assertJsonPath('direccion_entrega', 'Av. Nueva 99')->assertJsonPath('contacto.nombre', 'Rosa Prado')
            ->assertJsonPath('contrato.compra_minima_mensual', 300)->assertJsonPath('contrato.ventana_entrega_inicio', '12:00')->assertJsonPath('zona', 'Zapopan');

        $contrato = ContratoEmpresa::where('empresa_id', $empresa->id)->sole();
        $this->assertEquals(40, $contrato->porcentaje_subsidio);
        $this->assertEquals(130, $contrato->precio_por_platillo, 'el precio acordado no cambia al editar');
        $this->assertSame($admin->id, $this->bitacora('actualizar')->usuario_id);
        $this->assertSame('33 5555 0000', \App\Models\UserProfile::where('user_id', $empresa->usuario_id)->value('phone'));
    }

    public function test_no_edita_con_un_nombre_repetido_una_ventana_al_reves_ni_sin_contrato(): void
    {
        $this->comoRol('admin');
        $empresa = $this->empresaConContrato();
        $otra = $this->empresaConContrato();
        $url = "/api/v1/admin/empresas/{$empresa->id}";

        $this->putJson($url, $this->datosDeEmpresa(['nombre' => strtoupper($otra->nombre)]))->assertStatus(422)->assertJsonPath('codigo', 'empresa_repetida');
        $this->putJson($url, $this->datosDeEmpresa(['ventana_entrega_inicio' => '13:00', 'ventana_entrega_fin' => '12:00']))->assertStatus(422)->assertJsonPath('codigo', 'ventana_invalida');
        $this->putJson($url, $this->datosDeEmpresa(['porcentaje_subsidio' => 101, 'compra_minima_mensual' => -1]))->assertStatus(422)->assertJsonStructure(['errores' => ['porcentaje_subsidio', 'compra_minima_mensual']]);
        $this->putJson($url, $this->datosDeEmpresa(['nombre' => $empresa->nombre]))->assertOk(); // quedarse con su propio nombre sí se puede

        ContratoEmpresa::where('empresa_id', $empresa->id)->update(['activo' => false, 'desactivado_en' => now()]);
        $this->putJson($url, $this->datosDeEmpresa())->assertStatus(422)->assertJsonPath('codigo', 'sin_contrato');
    }

    public function test_desactiva_y_reactiva_una_empresa_sin_borrarla(): void
    {
        $this->comoRol('admin');
        $empresa = $this->empresaConContrato();

        $this->patchJson("/api/v1/admin/empresas/{$empresa->id}/estado", ['activo' => false])->assertOk()->assertJsonPath('activo', false);
        $this->assertNotNull($empresa->fresh()->desactivado_en);
        $this->assertSame('desactivar', $this->bitacora('desactivar')->accion);

        $this->patchJson("/api/v1/admin/empresas/{$empresa->id}/estado", ['activo' => true])->assertOk()->assertJsonPath('activo', true);
        $this->assertNull($empresa->fresh()->desactivado_en);
        $this->patchJson("/api/v1/admin/empresas/{$empresa->id}/estado", [])->assertStatus(422);
        $this->patchJson('/api/v1/admin/empresas/999999/estado', ['activo' => false])->assertStatus(404);
    }

    public function test_editar_empresas_es_solo_del_administrador(): void
    {
        $empresa = $this->empresaConContrato();
        foreach (['support', 'company', 'provider', 'customer'] as $rol) {
            $this->comoRol($rol);
            $this->putJson("/api/v1/admin/empresas/{$empresa->id}", $this->datosDeEmpresa())->assertStatus(403);
            $this->patchJson("/api/v1/admin/empresas/{$empresa->id}/estado", ['activo' => false])->assertStatus(403);
        }
        $this->assertTrue($empresa->fresh()->activo);
    }

    // ------------------------------------------------------------------ empleados

    public function test_la_empresa_edita_a_sus_empleados_y_no_a_los_de_otra(): void
    {
        $empresa = $this->comoEmpresa();
        $empleado = $this->empleadoDe($empresa);
        $ajeno = $this->empleadoDe($this->empresaConContrato());

        $this->putJson("/api/v1/empresa/empleados/{$empleado->id}", ['nombre' => 'Ana María Ruiz', 'departamento' => 'Logística'])->assertOk()
            ->assertJsonPath('nombre', 'Ana María Ruiz')->assertJsonPath('departamento', 'Logística');
        $this->assertSame('Ana María Ruiz', $empleado->usuario->fresh()->name);

        $this->putJson("/api/v1/empresa/empleados/{$empleado->id}", ['nombre' => 'Ana María Ruiz', 'departamento' => ''])->assertOk()->assertJsonPath('departamento', null);
        $this->putJson("/api/v1/empresa/empleados/{$ajeno->id}", ['nombre' => 'Intruso'])->assertStatus(404);
        $this->putJson("/api/v1/empresa/empleados/{$empleado->id}", ['nombre' => ''])->assertStatus(422)->assertJsonStructure(['errores' => ['nombre']]);
    }

    // ------------------------------------------------------------------ repartidores

    public function test_edita_un_repartidor_con_zona_existente_y_placas_en_mayusculas(): void
    {
        $this->comoRol('admin');
        $repartidor = $this->repartidorEn('Centro');

        $this->putJson("/api/v1/admin/repartidores/{$repartidor->id}", ['nombre' => 'Mario Solís', 'telefono' => '33 1111 2222', 'numero_empleado' => 'R-050', 'placas' => 'jal-999-z', 'zona_base' => 'zapopan'])->assertOk()
            ->assertJsonPath('nombre', 'Mario Solís')->assertJsonPath('placas', 'JAL-999-Z')->assertJsonPath('zona_base', 'Zapopan')->assertJsonPath('numero_empleado', 'R-050');
    }

    public function test_no_edita_un_repartidor_con_bicicleta_zona_inexistente_ni_numero_repetido(): void
    {
        $this->comoRol('admin');
        $repartidor = $this->repartidorEn('Centro');
        $otro = $this->repartidorEn('Centro');
        $url = "/api/v1/admin/repartidores/{$repartidor->id}";
        $base = ['nombre' => 'Mario', 'placas' => 'JAL-1-A', 'zona_base' => 'Centro'];

        $this->putJson($url, ['tipo_vehiculo' => 'bicicleta'] + $base)->assertStatus(422)->assertJsonPath('codigo', 'solo_automovil');
        $this->putJson($url, ['zona_base' => 'Narnia'] + $base)->assertStatus(422)->assertJsonPath('codigo', 'zona_inexistente');
        $this->putJson($url, ['numero_empleado' => $otro->numero_empleado] + $base)->assertStatus(422)->assertJsonPath('codigo', 'numero_empleado_repetido');
        $this->putJson($url, ['placas' => ''] + $base)->assertStatus(422)->assertJsonStructure(['errores' => ['placas']]);
    }

    public function test_desactivar_un_repartidor_lo_deja_no_disponible_y_reactivarlo_lo_libera(): void
    {
        $this->comoRol('admin');
        $repartidor = $this->repartidorEn('Centro');

        $this->patchJson("/api/v1/admin/repartidores/{$repartidor->id}/estado", ['activo' => false])->assertOk()->assertJsonPath('activo', false)->assertJsonPath('estado', 'no_disponible');
        $this->patchJson("/api/v1/admin/repartidores/{$repartidor->id}/estado", ['activo' => true])->assertOk()->assertJsonPath('activo', true)->assertJsonPath('estado', 'libre');
    }

    public function test_no_desactiva_a_un_repartidor_con_rutas_por_hacer_pero_si_con_rutas_pasadas(): void
    {
        $this->comoRol('admin');
        $conRutaFutura = $this->repartidorEn('Centro');
        $conRutaPasada = $this->repartidorEn('Centro');
        $ruta = fn (Repartidor $r, string $fecha) => Ruta::create(['fecha' => $fecha, 'repartidor_id' => $r->id, 'zona' => 'Centro', 'ventana_inicio' => '12:00', 'ventana_fin' => '13:00', 'capacidad_entregas' => 30, 'capacidad_platillos' => 120]);
        $ruta($conRutaFutura, '2026-11-10');
        $ruta($conRutaPasada, '2026-11-06');

        $this->patchJson("/api/v1/admin/repartidores/{$conRutaFutura->id}/estado", ['activo' => false])->assertStatus(422)->assertJsonPath('codigo', 'repartidor_con_rutas');
        $this->assertTrue($conRutaFutura->fresh()->activo);

        $this->patchJson("/api/v1/admin/repartidores/{$conRutaPasada->id}/estado", ['activo' => false])->assertOk();
        $this->assertFalse($conRutaPasada->fresh()->activo);
    }

    public function test_guarda_la_capacidad_del_auto_y_las_rutas_existentes_la_conservan(): void
    {
        $admin = $this->comoRol('admin');
        $ruta = Ruta::create(['fecha' => '2026-11-10', 'repartidor_id' => $this->repartidorEn('Centro')->id, 'zona' => 'Centro', 'ventana_inicio' => '12:00', 'ventana_fin' => '13:00', 'capacidad_entregas' => 30, 'capacidad_platillos' => 120]);

        $this->putJson('/api/v1/admin/repartidores/capacidades', ['automovil' => 18])->assertOk()->assertJsonPath('automovil', 18);

        $this->assertSame('18', Configuracion::findOrFail('capacidad_auto_entregas')->valor);
        $this->assertSame($admin->id, Configuracion::findOrFail('capacidad_auto_entregas')->actualizado_por);
        $this->assertSame(30, $ruta->fresh()->capacidad_entregas);
        $this->assertStringContainsString('de 30 a 18', $this->bitacora('actualizar')->detalle);
        $this->putJson('/api/v1/admin/repartidores/capacidades', ['automovil' => 0])->assertStatus(422)->assertJsonStructure(['errores' => ['automovil']]);
        $this->putJson('/api/v1/admin/repartidores/capacidades', [])->assertStatus(422);
    }

    // ------------------------------------------------------------------ planes

    public function test_edita_un_plan_sin_cambiar_sus_dias_y_no_repite_nombres(): void
    {
        $this->comoRol('admin');
        $uno = Paquete::create(['nombre' => 'Un día', 'dias' => 1, 'descuento_porcentaje' => 0]);
        $seis = Paquete::create(['nombre' => 'Semana', 'dias' => 6, 'descuento_porcentaje' => 10]);

        $this->putJson("/api/v1/admin/paquetes/{$seis->id}", ['nombre' => 'Semana completa', 'descuento_porcentaje' => 15.5, 'detalle' => '6 comidas', 'destacado' => true])->assertOk()
            ->assertJsonPath('nombre', 'Semana completa')->assertJsonPath('descuento_porcentaje', 15.5)->assertJsonPath('destacado', true)->assertJsonPath('dias', 6);
        $this->assertEquals(15.5, $seis->fresh()->descuento_porcentaje);
        $this->assertSame('Un día', $uno->fresh()->nombre);
    }

    public function test_un_plan_rechaza_descuentos_fuera_de_rango_y_nombres_repetidos(): void
    {
        $this->comoRol('admin');
        $uno = Paquete::create(['nombre' => 'Un día', 'dias' => 1, 'descuento_porcentaje' => 0]);
        $seis = Paquete::create(['nombre' => 'Semana', 'dias' => 6, 'descuento_porcentaje' => 10]);

        $this->putJson("/api/v1/admin/paquetes/{$seis->id}", ['nombre' => 'un día', 'descuento_porcentaje' => 5, 'destacado' => false])->assertStatus(422)->assertJsonPath('codigo', 'plan_repetido');
        $this->putJson("/api/v1/admin/paquetes/{$seis->id}", ['nombre' => 'Semana', 'descuento_porcentaje' => 120, 'destacado' => false])->assertStatus(422)->assertJsonStructure(['errores' => ['descuento_porcentaje']]);
        $this->putJson('/api/v1/admin/paquetes/999999', ['nombre' => 'Xx', 'descuento_porcentaje' => 1, 'destacado' => false])->assertStatus(404);
    }

    // ------------------------------------------------------------------ personas con acceso

    /** Una persona invitada a la empresa que ya aceptó (cuenta activa). */
    private function invitadaDeEmpresa(User $actor, int $empresaId, string $nombre = 'Persona Invitada', bool $aceptada = true): User
    {
        [$invitacion] = app(Invitaciones::class)->invitar($actor, 'empresa', $empresaId, $nombre, Str($nombre)->slug().'@ejemplo.mx', 'empresa');
        if ($aceptada) {
            $invitacion->update(['aceptada_en' => now()]);
            $invitacion->usuario->update(['status' => 'active']);
        }

        return $invitacion->usuario;
    }

    public function test_el_administrador_edita_el_nombre_de_una_persona_con_acceso(): void
    {
        $admin = $this->comoRol('admin');
        $empresa = $this->empresaConContrato();
        $persona = $this->invitadaDeEmpresa($admin, $empresa->id);

        $this->putJson("/api/v1/cuentas/usuarios-acceso/{$persona->id}", ['nombre' => 'Persona Renombrada', 'rol' => 'empresa'])->assertOk()
            ->assertJsonPath('nombre', 'Persona Renombrada')->assertJsonPath('rol', 'empresa')->assertJsonPath('es_principal', false)->assertJsonPath('invitacion.estado', 'aceptada');
    }

    public function test_cambiar_de_rol_a_alguien_de_la_plataforma_cierra_sus_sesiones_pero_nadie_cambia_el_suyo(): void
    {
        $admin = $this->comoRol('admin');
        $soporte = $this->usuarioDeRol('support');
        $soporte->createToken('t');

        $this->putJson("/api/v1/cuentas/usuarios-acceso/{$soporte->id}", ['nombre' => $soporte->name, 'rol' => 'administrador'])->assertOk()->assertJsonPath('rol', 'administrador');
        $this->assertSame(0, $soporte->tokens()->count());
        $this->assertSame('admin', $soporte->fresh()->role->name);

        $this->putJson("/api/v1/cuentas/usuarios-acceso/{$admin->id}", ['nombre' => $admin->name, 'rol' => 'soporte'])->assertStatus(422)->assertJsonPath('codigo', 'no_tu_propio_rol');
        $this->putJson("/api/v1/cuentas/usuarios-acceso/{$admin->id}", ['nombre' => 'Admin Renombrado', 'rol' => 'administrador'])->assertOk()->assertJsonPath('nombre', 'Admin Renombrado');
    }

    public function test_el_titular_de_una_empresa_no_cambia_de_rol(): void
    {
        $this->comoRol('admin');
        $empresa = $this->empresaConContrato();
        User::findOrFail($empresa->usuario_id)->update(['role_id' => self::ROLES_API['company']]);

        $this->putJson("/api/v1/cuentas/usuarios-acceso/{$empresa->usuario_id}", ['nombre' => 'Titular', 'rol' => 'comedor'])->assertStatus(422)->assertJsonPath('codigo', 'rol_no_corresponde');
        $this->putJson("/api/v1/cuentas/usuarios-acceso/{$empresa->usuario_id}", ['nombre' => 'Titular Renombrado', 'rol' => 'empresa'])->assertOk()->assertJsonPath('es_principal', true);
    }

    public function test_un_rol_de_otra_organizacion_no_se_asigna(): void
    {
        $admin = $this->comoRol('admin');
        $persona = $this->invitadaDeEmpresa($admin, $this->empresaConContrato()->id);

        $this->putJson("/api/v1/cuentas/usuarios-acceso/{$persona->id}", ['nombre' => 'Persona', 'rol' => 'administrador'])->assertStatus(422)->assertJsonPath('codigo', 'rol_no_corresponde');
        $this->putJson("/api/v1/cuentas/usuarios-acceso/{$persona->id}", ['nombre' => 'Persona', 'rol' => 'empleado'])->assertStatus(422)->assertJsonStructure(['errores' => ['rol']]);
    }

    public function test_desactivar_a_una_persona_cierra_sus_sesiones_y_se_puede_reactivar(): void
    {
        $admin = $this->comoRol('admin');
        $persona = $this->invitadaDeEmpresa($admin, $this->empresaConContrato()->id);
        $persona->createToken('t');

        $this->patchJson("/api/v1/cuentas/usuarios-acceso/{$persona->id}/estado", ['activo' => false])->assertOk()->assertJsonPath('activo', false);
        $this->assertSame(0, $persona->tokens()->count());
        $this->assertSame('inactive', $persona->fresh()->status);

        $this->patchJson("/api/v1/cuentas/usuarios-acceso/{$persona->id}/estado", ['activo' => true])->assertOk()->assertJsonPath('activo', true);
    }

    public function test_no_te_desactivas_a_ti_mismo(): void
    {
        $admin = $this->comoRol('admin');

        $this->patchJson("/api/v1/cuentas/usuarios-acceso/{$admin->id}/estado", ['activo' => false])->assertStatus(422)->assertJsonPath('codigo', 'no_te_desactives');
        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_no_activa_a_quien_todavia_no_acepta_su_invitacion(): void
    {
        $admin = $this->comoRol('admin');
        $pendiente = $this->invitadaDeEmpresa($admin, $this->empresaConContrato()->id, 'Pendiente Persona', false);

        $this->patchJson("/api/v1/cuentas/usuarios-acceso/{$pendiente->id}/estado", ['activo' => true])->assertStatus(422)->assertJsonPath('codigo', 'invitacion_pendiente');
        $this->assertSame('inactive', $pendiente->fresh()->status);
    }

    public function test_la_empresa_administra_a_su_gente_y_no_a_la_de_otra_ni_a_los_empleados(): void
    {
        $empresa = $this->comoEmpresa();
        $propia = $this->invitadaDeEmpresa(User::findOrFail($empresa->usuario_id), $empresa->id, 'Propia Persona');
        $otraEmpresa = $this->empresaConContrato();
        $ajena = $this->invitadaDeEmpresa($this->usuarioDeRol('admin'), $otraEmpresa->id, 'Ajena Persona');
        $empleado = $this->empleadoDe($empresa);

        $this->putJson("/api/v1/cuentas/usuarios-acceso/{$propia->id}", ['nombre' => 'Propia Editada', 'rol' => 'empresa'])->assertOk();
        $this->patchJson("/api/v1/cuentas/usuarios-acceso/{$propia->id}/estado", ['activo' => false])->assertOk();
        $this->putJson("/api/v1/cuentas/usuarios-acceso/{$ajena->id}", ['nombre' => 'Hackeada', 'rol' => 'empresa'])->assertStatus(403);
        $this->patchJson("/api/v1/cuentas/usuarios-acceso/{$ajena->id}/estado", ['activo' => false])->assertStatus(403);
        $this->putJson("/api/v1/cuentas/usuarios-acceso/{$empleado->usuario_id}", ['nombre' => 'Empleado', 'rol' => 'empresa'])->assertStatus(404);
        $this->assertSame('active', $ajena->fresh()->status);
    }

    public function test_un_cliente_o_un_empleado_no_administra_accesos(): void
    {
        foreach (['customer', 'employee', 'support'] as $rol) {
            $this->comoRol($rol);
            $this->putJson('/api/v1/cuentas/usuarios-acceso/1', ['nombre' => 'X', 'rol' => 'soporte'])->assertStatus(403);
            $this->patchJson('/api/v1/cuentas/usuarios-acceso/1/estado', ['activo' => false])->assertStatus(403);
        }
    }

    // ------------------------------------------------------------------ corregir el correo de quien aún no acepta

    public function test_la_empresa_corrige_el_correo_de_un_empleado_que_no_ha_aceptado_y_se_le_manda_otra_invitacion(): void
    {
        $empresa = $this->comoEmpresa();
        $this->postJson('/api/v1/empresa/empleados', ['personas' => [['nombre' => 'José Pérez']]])->assertCreated();
        $empleado = \App\Models\Empleado::where('empresa_id', $empresa->id)->latest('id')->firstOrFail();
        $viejoToken = Invitacion::where('usuario_id', $empleado->usuario_id)->value('token_hash');
        Mail::fake();

        $this->putJson("/api/v1/empresa/empleados/{$empleado->id}", ['nombre' => 'José Pérez', 'correo' => 'Jose.Perez@Nexo.mx'])->assertOk()->assertJsonPath('correo', 'jose.perez@nexo.mx');

        $this->assertSame('jose.perez@nexo.mx', $empleado->usuario->fresh()->email);
        $invitacion = Invitacion::where('usuario_id', $empleado->usuario_id)->sole();
        $this->assertSame('jose.perez@nexo.mx', $invitacion->correo);
        $this->assertNotSame($viejoToken, $invitacion->token_hash, 'el enlace anterior deja de servir');
        Mail::assertSent(\App\Mail\InvitacionDeAcceso::class, fn ($m) => $m->hasTo('jose.perez@nexo.mx'));
        $this->assertSame('corregir_correo', RegistroAuditoria::where('accion', 'corregir_correo')->sole()->accion);
    }

    public function test_el_listado_de_empleados_dice_quien_ya_acepto_su_invitacion(): void
    {
        $empresa = $this->comoEmpresa();
        $this->postJson('/api/v1/empresa/empleados', ['personas' => [['nombre' => 'Pendiente Uno']]])->assertCreated();
        $this->empleadoDe($empresa); // sin invitación (cuenta de ejemplo)

        $filas = $this->getJson('/api/v1/empresa/empleados')->assertOk()->json();

        $this->assertSame(['enviada', null], [$filas[0]['invitacion']['estado'], $filas[1]['invitacion']]);
    }

    public function test_no_cambia_el_correo_de_quien_ya_acepto_ni_uno_que_ya_existe(): void
    {
        $empresa = $this->comoEmpresa();
        $this->postJson('/api/v1/empresa/empleados', ['personas' => [['nombre' => 'Ana Nueva'], ['nombre' => 'Beto Nuevo']]])->assertCreated();
        [$ana, $beto] = \App\Models\Empleado::where('empresa_id', $empresa->id)->latest('id')->take(2)->get()->reverse()->values()->all();
        Invitacion::where('usuario_id', $ana->usuario_id)->update(['aceptada_en' => now()]);

        $this->putJson("/api/v1/empresa/empleados/{$ana->id}", ['nombre' => 'Ana Nueva', 'correo' => 'otro@nexo.mx'])->assertStatus(422)->assertJsonPath('codigo', 'correo_no_editable');
        $this->putJson("/api/v1/empresa/empleados/{$beto->id}", ['nombre' => 'Beto Nuevo', 'correo' => $ana->usuario->email])->assertStatus(422)->assertJsonPath('codigo', 'correo_en_uso');
        $this->putJson("/api/v1/empresa/empleados/{$beto->id}", ['nombre' => 'Beto Nuevo', 'correo' => 'no-es-correo'])->assertStatus(422)->assertJsonStructure(['errores' => ['correo']]);

        $this->assertNotSame('otro@nexo.mx', $ana->usuario->fresh()->email);
        $this->putJson("/api/v1/empresa/empleados/{$ana->id}", ['nombre' => 'Ana Renombrada', 'correo' => $ana->usuario->email])->assertOk()->assertJsonPath('nombre', 'Ana Renombrada'); // mismo correo: no es un cambio
    }

    public function test_un_rechazo_de_correo_no_deja_el_nombre_a_medias(): void
    {
        $empresa = $this->comoEmpresa();
        $existente = $this->empleadoDe($empresa);
        $this->postJson('/api/v1/empresa/empleados', ['personas' => [['nombre' => 'Carla Nueva']]])->assertCreated();
        $carla = \App\Models\Empleado::where('empresa_id', $empresa->id)->latest('id')->firstOrFail();

        $this->putJson("/api/v1/empresa/empleados/{$carla->id}", ['nombre' => 'Nombre que no debe quedar', 'correo' => $existente->usuario->email])->assertStatus(422);

        $this->assertSame('Carla Nueva', $carla->usuario->fresh()->name);
    }

    public function test_el_administrador_corrige_el_correo_del_contacto_de_una_empresa_nueva(): void
    {
        $this->comoRol('admin');
        $this->zonaDePruebas('Zapopan', -103.55, 20.72, 0.05);
        $id = $this->postJson('/api/v1/admin/empresas', ['nombre' => 'Empresa Nueva', 'contacto_nombre' => 'Rosa', 'contacto_correo' => 'rosa@mal-escrito.mx', 'direccion_entrega' => 'Calle 1', 'zona' => 'Zapopan',
            'vigencia_inicio' => '2026-12-01', 'compra_minima_mensual' => 100, 'porcentaje_subsidio' => 50, 'ventana_entrega_inicio' => '12:00', 'ventana_entrega_fin' => '12:30'])->assertCreated()->json('id');

        $this->putJson("/api/v1/admin/empresas/{$id}", $this->datosDeEmpresa(['nombre' => 'Empresa Nueva', 'contacto_correo' => 'rosa@bien-escrito.mx']))->assertOk()->assertJsonPath('contacto.correo', 'rosa@bien-escrito.mx');

        $contacto = User::where('email', 'rosa@bien-escrito.mx')->firstOrFail();
        $this->assertSame('inactive', $contacto->status);
        $this->assertSame('rosa@bien-escrito.mx', Invitacion::where('usuario_id', $contacto->id)->value('correo'));
    }

    public function test_una_persona_con_acceso_corrige_su_correo_solo_si_no_acepto_y_el_titular_aparece_una_sola_vez(): void
    {
        $admin = $this->comoRol('admin');
        $empresa = $this->empresaConContrato();
        $pendiente = $this->invitadaDeEmpresa($admin, $empresa->id, 'Pendiente Acceso', false);
        $aceptada = $this->invitadaDeEmpresa($admin, $empresa->id, 'Aceptada Acceso');

        $this->putJson("/api/v1/cuentas/usuarios-acceso/{$pendiente->id}", ['nombre' => 'Pendiente Acceso', 'rol' => 'empresa', 'correo' => 'nuevo@ejemplo.mx'])->assertOk()->assertJsonPath('correo', 'nuevo@ejemplo.mx');
        $this->putJson("/api/v1/cuentas/usuarios-acceso/{$aceptada->id}", ['nombre' => 'Aceptada Acceso', 'rol' => 'empresa', 'correo' => 'otro@ejemplo.mx'])->assertStatus(422)->assertJsonPath('codigo', 'correo_no_editable');

        // El contacto de una empresa dada de alta tiene invitación pero es el titular: una sola fila, con su invitación.
        $contacto = User::findOrFail($empresa->usuario_id);
        $contacto->update(['role_id' => self::ROLES_API['company']]);
        Invitacion::create(['usuario_id' => $contacto->id, 'correo' => $contacto->email, 'rol_id' => $contacto->role_id, 'organizacion_tipo' => 'empresa', 'empresa_id' => $empresa->id,
            'token_hash' => hash('sha256', 'x'), 'enviada_en' => now(), 'vence_en' => now()->addDays(7)]);
        $filas = $this->getJson("/api/v1/cuentas/usuarios-acceso?tipo=empresa&id={$empresa->id}")->assertOk()->json();

        $this->assertSame(count($filas), count(array_unique(array_column($filas, 'usuario_id'))), 'ningún usuario sale dos veces');
        $fila = collect($filas)->firstWhere('usuario_id', $contacto->id);
        $this->assertSame([true, 'enviada'], [$fila['es_principal'], $fila['invitacion']['estado']]);
        $this->putJson("/api/v1/cuentas/usuarios-acceso/{$contacto->id}", ['nombre' => 'Titular', 'rol' => 'comedor'])->assertStatus(422)->assertJsonPath('codigo', 'rol_no_corresponde');
    }
}
