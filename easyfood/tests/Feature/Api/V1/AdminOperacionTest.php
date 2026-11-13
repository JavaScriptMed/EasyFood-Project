<?php

namespace Tests\Feature\Api\V1;

use App\Models\Corte;
use App\Models\DiaSinServicio;
use App\Models\EntregaEmpresa;
use App\Models\Incidencia;
use App\Models\RegistroAuditoria;
use App\Models\Ruta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** Panel de administración: configuración, calendario, corte, repartidores, rutas, entregas, incidencias y bitácora. */
class AdminOperacionTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-11 10:00');
        $this->admin = $this->comoRol('admin');
    }

    // ------------------------------------------------------------------ configuración

    public function test_muestra_la_configuracion_de_operacion(): void
    {
        $this->getJson('/api/v1/admin/configuracion')->assertOk()->assertExactJson([
            'hora_corte' => '18:00', 'zona_horaria' => 'America/Mexico_City', 'dias_entrega' => 'lunes_sabado', 'regla_sin_eleccion' => 'ultimo_platillo',
            'capacidad_auto_entregas' => 30, 'capacidad_auto_platillos' => 120,
        ]);
    }

    public function test_guarda_la_configuracion_y_deja_constancia_de_quien_y_que_cambio(): void
    {
        $nueva = ['hora_corte' => '17:30', 'dias_entrega' => 'lunes_viernes', 'regla_sin_eleccion' => 'no_entregar', 'capacidad_auto_entregas' => 25, 'capacidad_auto_platillos' => 100];

        $this->putJson('/api/v1/admin/configuracion', $nueva)->assertOk()->assertJsonFragment(['hora_corte' => '17:30', 'capacidad_auto_entregas' => 25]);

        $this->assertDatabaseHas('configuracion', ['clave' => 'hora_corte', 'valor' => '17:30', 'actualizado_por' => $this->admin->id]);
        $registro = RegistroAuditoria::sole();
        $this->assertSame(['configuracion.actualizada', $this->admin->id], [$registro->accion, $registro->usuario_id]);
        $this->assertStringContainsString('hora_corte: 18:00 → 17:30', $registro->detalle);
        $this->assertStringContainsString('dias_entrega: lunes_sabado → lunes_viernes', $registro->detalle);
    }

    public function test_guardar_lo_mismo_no_llena_la_bitacora(): void
    {
        $this->putJson('/api/v1/admin/configuracion', $this->getJson('/api/v1/admin/configuracion')->json())->assertOk();

        $this->assertSame(0, RegistroAuditoria::count());
    }

    public function test_rechaza_valores_invalidos_de_configuracion(): void
    {
        $this->putJson('/api/v1/admin/configuracion', ['hora_corte' => '25:00', 'dias_entrega' => 'domingos', 'regla_sin_eleccion' => 'adivinar', 'capacidad_auto_entregas' => 0, 'capacidad_auto_platillos' => -5])
            ->assertStatus(422)->assertJsonStructure(['errores' => ['hora_corte', 'dias_entrega', 'regla_sin_eleccion', 'capacidad_auto_entregas', 'capacidad_auto_platillos']]);
    }

    // ------------------------------------------------------------------ calendario

    public function test_marca_un_dia_sin_servicio_y_lo_muestra_en_el_calendario_del_mes(): void
    {
        $this->postJson('/api/v1/admin/calendario/dias-sin-servicio', ['fecha' => '2026-11-16', 'tipo' => 'festivo', 'motivo' => 'Día de la Revolución'])
            ->assertCreated()->assertJsonPath('tipo', 'festivo')->assertJsonPath('empresa_id', null);

        $this->getJson('/api/v1/admin/calendario?mes=2026-11')->assertOk()->assertJsonPath('mes', '2026-11')->assertJsonCount(1, 'dias')->assertJsonPath('dias.0.fecha', '2026-11-16');
        $this->getJson('/api/v1/admin/calendario?mes=2026-12')->assertJsonCount(0, 'dias');
        $this->assertSame('calendario.dia_sin_servicio', RegistroAuditoria::sole()->accion);
    }

    public function test_cierra_una_empresa_un_dia_y_no_cierra_el_servicio_general(): void
    {
        $empresa = $this->empresaConContrato();

        $this->postJson('/api/v1/admin/calendario/cierres-empresa', ['fecha' => '2026-11-17', 'empresa_id' => $empresa->id, 'motivo' => 'Inventario'])
            ->assertCreated()->assertJsonPath('tipo', 'cierre_empresa')->assertJsonPath('empresa_nombre', $empresa->nombre);

        $this->postJson('/api/v1/admin/calendario/cierres-empresa', ['fecha' => '2026-11-17', 'empresa_id' => $empresa->id, 'motivo' => 'Otra vez'])
            ->assertStatus(422)->assertJsonPath('codigo', 'dia_ya_marcado');
    }

    public function test_no_marca_dos_veces_el_mismo_dia_ni_fechas_pasadas_ni_con_corte_ejecutado(): void
    {
        $datos = ['fecha' => '2026-11-16', 'tipo' => 'festivo', 'motivo' => 'x'];
        $this->postJson('/api/v1/admin/calendario/dias-sin-servicio', $datos)->assertCreated();
        $this->postJson('/api/v1/admin/calendario/dias-sin-servicio', $datos)->assertStatus(422)->assertJsonPath('codigo', 'dia_ya_marcado');
        $this->postJson('/api/v1/admin/calendario/dias-sin-servicio', ['fecha' => '2026-11-10'] + $datos)->assertStatus(422)->assertJsonPath('codigo', 'fecha_pasada');

        Corte::create(['fecha_entrega' => '2026-11-18', 'estado' => 'correcto', 'sin_cambios' => false]);
        $this->postJson('/api/v1/admin/calendario/dias-sin-servicio', ['fecha' => '2026-11-18'] + $datos)->assertStatus(422)->assertJsonPath('codigo', 'corte_ejecutado');
        $this->getJson('/api/v1/admin/calendario?mes=2026-11')->assertJsonPath('fechas_cerradas.0', '2026-11-18');
    }

    public function test_quita_un_dia_sin_servicio_futuro(): void
    {
        $dia = DiaSinServicio::create(['fecha' => '2026-11-16', 'tipo' => 'festivo', 'motivo' => 'Festivo']);

        $this->deleteJson("/api/v1/admin/calendario/dias-sin-servicio/{$dia->id}")->assertOk();

        $this->assertSame(0, DiaSinServicio::count());
        $this->deleteJson('/api/v1/admin/calendario/dias-sin-servicio/999')->assertStatus(404);
    }

    // ------------------------------------------------------------------ corte

    private function eleccionesPara(string $fecha): void
    {
        $menu = $this->platillo();
        $this->disponible($menu, $fecha);
        DB::table('elecciones_empleado')->insert([
            'empleado_id' => $this->empleadoDe($this->empresaConContrato('Zapopan'))->id, 'fecha' => $fecha, 'tiempo_comida' => 'comida', 'menu_id' => $menu->id,
            'precio_congelado' => 130, 'porcentaje_subsidio_congelado' => 60, 'origen' => 'manual',
        ]);
    }

    public function test_ejecutar_corte_ahora_cierra_la_siguiente_fecha_y_registra_quien_lo_hizo(): void
    {
        $this->eleccionesPara('2026-11-12');

        $respuesta = $this->postJson('/api/v1/admin/cortes/ejecutar')->assertOk();

        $respuesta->assertJsonPath('fecha_entrega', '2026-11-12')->assertJsonPath('sin_cambios', false)->assertJsonPath('elecciones_cerradas', 1)->assertJsonPath('corte.ejecutado_por', $this->admin->id);
        $this->assertSame('corte.ejecutado', RegistroAuditoria::sole()->accion);
    }

    public function test_ejecutarlo_otra_vez_no_cambia_nada_y_sigue_con_la_siguiente_fecha_pendiente(): void
    {
        $this->eleccionesPara('2026-11-12');
        $this->postJson('/api/v1/admin/cortes/ejecutar')->assertOk();

        $repetido = $this->postJson('/api/v1/admin/cortes/ejecutar', ['fecha_entrega' => '2026-11-12'])->assertOk();
        $repetido->assertJsonPath('sin_cambios', true)->assertJsonPath('elecciones_cerradas', 0);

        $siguiente = $this->postJson('/api/v1/admin/cortes/ejecutar')->assertOk();
        $siguiente->assertJsonPath('fecha_entrega', '2026-11-13')->assertJsonPath('sin_cambios', false);
    }

    public function test_lista_el_historial_de_cortes_del_mas_reciente_al_mas_antiguo(): void
    {
        $this->postJson('/api/v1/admin/cortes/ejecutar')->assertOk();
        $this->postJson('/api/v1/admin/cortes/ejecutar')->assertOk();

        $fechas = array_column($this->getJson('/api/v1/admin/cortes')->assertOk()->json(), 'fecha_entrega');

        $this->assertSame(['2026-11-13', '2026-11-12'], $fechas);
        $this->getJson('/api/v1/admin/cortes?limite=1')->assertJsonCount(1);
    }

    public function test_no_se_corta_el_domingo_ni_una_fecha_pasada(): void
    {
        $this->postJson('/api/v1/admin/cortes/ejecutar', ['fecha_entrega' => '2026-11-15'])->assertStatus(422)->assertJsonPath('codigo', 'dia_sin_servicio');
        $this->postJson('/api/v1/admin/cortes/ejecutar', ['fecha_entrega' => '2026-11-10'])->assertStatus(422)->assertJsonPath('codigo', 'fecha_pasada');
    }

    // ------------------------------------------------------------------ repartidores, rutas y entregas

    public function test_lista_repartidores_y_las_capacidades_del_auto(): void
    {
        $repartidor = $this->repartidorEn('Zapopan');

        $this->getJson('/api/v1/admin/repartidores')->assertOk()->assertJsonPath('0.id', $repartidor->id)->assertJsonPath('0.tipo_vehiculo', 'automovil')->assertJsonPath('0.estado', 'libre');
        $this->getJson('/api/v1/admin/repartidores?estado=en_ruta')->assertJsonCount(0);
        $this->getJson('/api/v1/admin/repartidores/capacidades')->assertOk()->assertExactJson(['automovil' => 30, 'entregas' => 30, 'platillos' => 120]);
    }

    public function test_alterna_la_disponibilidad_de_un_repartidor_libre(): void
    {
        $repartidor = $this->repartidorEn('Zapopan');

        $this->patchJson("/api/v1/admin/repartidores/{$repartidor->id}/disponibilidad")->assertOk()->assertJsonPath('estado', 'no_disponible');
        $this->patchJson("/api/v1/admin/repartidores/{$repartidor->id}/disponibilidad")->assertOk()->assertJsonPath('estado', 'libre');
        $this->assertSame(2, RegistroAuditoria::where('accion', 'repartidor.disponibilidad')->count());
    }

    public function test_un_repartidor_con_ruta_no_se_puede_apagar(): void
    {
        $repartidor = $this->repartidorEn('Zapopan');
        $repartidor->update(['estado' => 'en_ruta']);

        $this->patchJson("/api/v1/admin/repartidores/{$repartidor->id}/disponibilidad")->assertStatus(422)->assertJsonPath('codigo', 'repartidor_ocupado');
    }

    public function test_lista_las_rutas_de_una_fecha_con_sus_paradas_y_las_aprueba(): void
    {
        $ruta = Ruta::create(['fecha' => '2026-11-12', 'repartidor_id' => $this->repartidorEn('Zapopan')->id, 'zona' => 'Zapopan', 'ventana_inicio' => '12:00', 'ventana_fin' => '13:00',
            'capacidad_entregas' => 30, 'capacidad_platillos' => 120, 'entregas_asignadas' => 1, 'platillos_asignados' => 5]);
        $entrega = $this->entrega(['ruta_id' => $ruta->id]);
        DB::table('paradas')->insert(['ruta_id' => $ruta->id, 'orden' => 1, 'entrega_empresa_id' => $entrega, 'nombre_destinatario' => 'Nexo', 'direccion' => 'Av. 1', 'hora_estimada' => '12:30']);

        $this->getJson('/api/v1/admin/rutas')->assertStatus(422)->assertJsonStructure(['errores' => ['fecha']]);
        $fila = $this->getJson('/api/v1/admin/rutas?fecha=2026-11-12')->assertOk()->json('0');
        $this->assertSame(['borrador', 'Zapopan', 'Nexo'], [$fila['estado'], $fila['zona'], $fila['paradas'][0]['nombre_destinatario']]);

        $this->postJson('/api/v1/admin/rutas/aprobar', ['fecha' => '2026-11-12'])->assertOk()->assertJsonPath('aprobadas', 1);
        $this->postJson('/api/v1/admin/rutas/aprobar', ['fecha' => '2026-11-12'])->assertOk()->assertJsonPath('aprobadas', 0);
        $this->assertSame('aprobada', $ruta->fresh()->estado);
    }

    public function test_lista_las_entregas_a_empresas_de_una_fecha(): void
    {
        $this->entrega(['fecha' => '2026-11-12', 'platillos' => 20]);
        $this->entrega(['fecha' => '2026-11-13']);

        $filas = $this->getJson('/api/v1/admin/entregas?fecha=2026-11-12')->assertOk()->json();

        $this->assertCount(1, $filas);
        $this->assertSame([20, 'programada', 'Zapopan'], [$filas[0]['platillos'], $filas[0]['estado'], $filas[0]['zona']]);
        $this->assertSame(1, EntregaEmpresa::whereDate('fecha', '2026-11-13')->count());
    }

    // ------------------------------------------------------------------ incidencias y bitácora

    public function test_lista_incidencias_abiertas_con_las_inmediatas_primero_y_las_resuelve(): void
    {
        $normal = Incidencia::create(['fecha' => '2026-11-12', 'tipo' => 'otro', 'descripcion' => 'Algo', 'prioridad' => 'normal']);
        $urgente = Incidencia::create(['fecha' => '2026-11-12', 'tipo' => 'falta_repartidores', 'descripcion' => 'Sin repartidor', 'prioridad' => 'inmediata']);

        $this->assertSame([$urgente->id, $normal->id], array_column($this->getJson('/api/v1/admin/incidencias?abiertas=1')->assertOk()->json(), 'id'));
        $this->getJson('/api/v1/admin/incidencias?tipos[]=otro')->assertJsonCount(1);

        $this->postJson("/api/v1/admin/incidencias/{$urgente->id}/resolver", ['nota' => 'Se asignó otro auto'])->assertOk()->assertJsonPath('estado', 'resuelta')->assertJsonPath('nota', 'Se asignó otro auto');
        $this->getJson('/api/v1/admin/incidencias?abiertas=1')->assertJsonCount(1);
        $this->postJson("/api/v1/admin/incidencias/{$urgente->id}/resolver")->assertStatus(422)->assertJsonPath('codigo', 'incidencia_ya_resuelta');
    }

    public function test_la_bitacora_filtra_por_actor_rol_y_fechas_y_la_auditoria_por_entidad(): void
    {
        $this->putJson('/api/v1/admin/configuracion', ['hora_corte' => '17:00', 'dias_entrega' => 'lunes_sabado', 'regla_sin_eleccion' => 'ultimo_platillo', 'capacidad_auto_entregas' => 30, 'capacidad_auto_platillos' => 120])->assertOk();
        \App\Services\Bitacora::registrar(null, 'corte.ejecutado', 'cortes', 7, 'Corte automático');

        $todos = $this->getJson('/api/v1/admin/bitacora')->assertOk()->json();
        $this->assertCount(2, $todos);
        $this->assertSame([null, $this->admin->id], collect($todos)->pluck('usuario_id')->sortBy(fn ($v) => $v ?? 0)->values()->all());

        $this->getJson('/api/v1/admin/bitacora?usuario_id=sistema')->assertJsonCount(1)->assertJsonPath('0.accion', 'corte.ejecutado');
        $this->getJson("/api/v1/admin/bitacora?usuario_id={$this->admin->id}")->assertJsonCount(1)->assertJsonPath('0.usuario_rol', 'administrador');
        $this->getJson('/api/v1/admin/bitacora?rol=administrador')->assertJsonCount(1);
        $this->getJson('/api/v1/admin/bitacora?rol=cliente')->assertJsonCount(0);
        $this->getJson('/api/v1/admin/bitacora?desde=2099-01-01')->assertJsonCount(0);
        $this->getJson('/api/v1/admin/bitacora?desde=2026-11-11&hasta=2026-11-11')->assertJsonCount(2);
        $this->getJson('/api/v1/admin/bitacora/actores')->assertOk()->assertJsonCount(1)->assertJsonPath('0.rol', 'administrador');
        $this->getJson('/api/v1/admin/auditoria/cortes/7')->assertOk()->assertJsonCount(1);
    }
}
