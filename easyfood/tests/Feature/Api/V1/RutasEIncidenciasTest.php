<?php

namespace Tests\Feature\Api\V1;

use App\Models\Incidencia;
use App\Models\RegistroAuditoria;
use App\Models\Repartidor;
use App\Models\Ruta;
use App\Models\User;
use App\Services\EjecutorDelCorte;
use App\Services\EleccionDePlatillos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Generar rutas a mano (completar lo que el corte dejó sin repartidor) y reasignar la ruta de una incidencia.
 * «Hoy» es el miércoles 11 de nov de 2026; la entrega es el jueves 12.
 */
class RutasEIncidenciasTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private const ENTREGA = '2026-11-12';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-11 10:00');
        $this->admin = $this->comoRol('admin');
    }

    /** Una empresa de la zona con 1 empleado que ya eligió su platillo para el jueves. */
    private function empresaQueEligio(string $zona = 'Zapopan'): void
    {
        $menu = $this->platillo();
        $this->disponible($menu, self::ENTREGA);
        $empresa = $this->empresaConContrato($zona);
        app(EleccionDePlatillos::class)->elegir($this->empleadoDe($empresa), self::ENTREGA, $menu->tiempo_comida, $menu);
    }

    private function cortar(): void
    {
        $this->ahoraEs('2026-11-11 18:30');
        app(EjecutorDelCorte::class)->ejecutar(self::ENTREGA);
    }

    private function rutaDe(Repartidor $repartidor, int $entregas = 1, int $platillos = 5, string $estado = 'borrador'): Ruta
    {
        return Ruta::create([
            'fecha' => self::ENTREGA, 'repartidor_id' => $repartidor->id, 'zona' => 'Zapopan', 'ventana_inicio' => '12:00', 'ventana_fin' => '13:00',
            'capacidad_entregas' => 30, 'capacidad_platillos' => 120, 'entregas_asignadas' => $entregas, 'platillos_asignados' => $platillos, 'estado' => $estado,
        ]);
    }

    private function incidenciaDe(Ruta $ruta, string $tipo = 'repartidor_no_llego'): Incidencia
    {
        return Incidencia::create(['fecha' => self::ENTREGA, 'tipo' => $tipo, 'descripcion' => 'No llegó', 'prioridad' => 'inmediata', 'ruta_id' => $ruta->id]);
    }

    // ------------------------------------------------------------------ generar rutas

    public function test_generar_crea_la_ruta_de_lo_que_quedo_sin_repartidor_y_cierra_su_incidencia(): void
    {
        $this->empresaQueEligio();
        $this->cortar();
        $this->assertSame(0, Ruta::count());
        $this->assertSame('abierta', Incidencia::sole()->estado);

        $repartidor = $this->repartidorEn('Zapopan');
        $this->postJson('/api/v1/admin/rutas/generar', ['fecha' => self::ENTREGA])->assertOk()
            ->assertJsonPath('creadas', 1)->assertJsonPath('sin_ruta', 0)->assertJsonPath('rutas.0.repartidor_id', $repartidor->id)->assertJsonPath('rutas.0.estado', 'borrador')
            ->assertJsonCount(1, 'rutas.0.paradas');

        $incidencia = Incidencia::sole();
        $this->assertSame('resuelta', $incidencia->estado);
        $this->assertNotNull($incidencia->resuelta_en);
        $this->assertSame('rutas.generadas', RegistroAuditoria::latest('id')->first()->accion);
    }

    public function test_generar_arma_por_separado_las_rutas_de_empresas_y_las_de_clientes_del_sabado(): void
    {
        $sabado = '2026-11-14';
        // Un empleado que eligió para el sábado y un cliente individual cuyo pedido llega ese sábado, los dos en la zona Centro.
        $this->zonaDePruebas('Centro');
        $menu = $this->platillo();
        $this->disponible($menu, $sabado);
        $this->disponible($menu, '2026-11-16');
        app(EleccionDePlatillos::class)->elegir($this->empleadoDe($this->empresaConContrato('Centro')), $sabado, $menu->tiempo_comida, $menu);
        $cliente = User::findOrFail($this->usuario());
        $direccion = \App\Models\Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. Vallarta 100', 'latitude' => 20.67, 'longitude' => -103.35]);
        $servicio = app(\App\Services\ContratacionDePaquetes::class);
        $servicio->pagar($servicio->pedir($cliente, \App\Models\Paquete::create(['nombre' => 'Un día', 'dias' => 1]), $direccion, [$menu]), 'tarjeta', '4821');

        $this->ahoraEs('2026-11-13 18:30');
        app(EjecutorDelCorte::class)->ejecutar($sabado);
        $this->ahoraEs('2026-11-14 18:30');
        app(\App\Services\CorteDeIndividuales::class)->ejecutar($sabado);
        $this->assertSame(0, Ruta::count(), 'todavía no hay repartidores');
        $this->assertSame(2, Incidencia::where('estado', 'abierta')->count());

        $this->ahoraEs('2026-11-14 19:00');
        $this->repartidorEn('Centro');
        $respuesta = $this->postJson('/api/v1/admin/rutas/generar', ['fecha' => $sabado])->assertOk()->assertJsonPath('creadas', 2)->assertJsonPath('sin_ruta', 0);

        $tipos = collect($respuesta->json('rutas'))->map(fn ($r) => collect($r['paradas'])->map(fn ($p) => $p['entrega_id'] ? 'empresa' : 'cliente')->unique()->all())->all();
        $this->assertEqualsCanonicalizing([['empresa'], ['cliente']], $tipos, 'cada ruta lleva un solo tipo de entrega');
        $this->assertSame(0, Incidencia::where('estado', 'abierta')->count());
    }

    public function test_generar_otra_vez_no_duplica_rutas_ni_incidencias_y_no_toca_las_aprobadas(): void
    {
        $this->empresaQueEligio('Zapopan');
        $this->empresaQueEligio('Tlaquepaque');
        $this->repartidorEn('Zapopan');
        $this->cortar();
        $this->assertSame(1, Incidencia::count()); // Tlaquepaque no tiene repartidor.
        $this->postJson('/api/v1/admin/rutas/aprobar', ['fecha' => self::ENTREGA])->assertOk();

        $this->postJson('/api/v1/admin/rutas/generar', ['fecha' => self::ENTREGA])->assertOk()->assertJsonPath('creadas', 0)->assertJsonPath('sin_ruta', 1);

        $this->assertSame(1, Ruta::count());
        $this->assertSame('aprobada', Ruta::sole()->estado);
        $this->assertSame(1, Incidencia::count());
        $this->assertSame('abierta', Incidencia::sole()->estado);
    }

    public function test_generar_exige_que_el_corte_ya_se_haya_ejecutado_y_una_fecha_valida(): void
    {
        $this->postJson('/api/v1/admin/rutas/generar', ['fecha' => self::ENTREGA])->assertStatus(422)->assertJsonPath('codigo', 'corte_pendiente');
        $this->postJson('/api/v1/admin/rutas/generar', ['fecha' => '2026-11-15'])->assertStatus(422)->assertJsonPath('codigo', 'dia_sin_servicio');
        $this->postJson('/api/v1/admin/rutas/generar', ['fecha' => '2026-11-10'])->assertStatus(422)->assertJsonPath('codigo', 'fecha_pasada');
        $this->postJson('/api/v1/admin/rutas/generar', [])->assertStatus(422)->assertJsonStructure(['errores' => ['fecha']]);
    }

    public function test_generar_es_solo_del_administrador(): void
    {
        foreach (['support', 'company', 'customer'] as $rol) {
            $this->comoRol($rol);
            $this->postJson('/api/v1/admin/rutas/generar', ['fecha' => self::ENTREGA])->assertStatus(403);
        }
    }

    // ------------------------------------------------------------------ candidatos

    public function test_los_candidatos_son_los_repartidores_libres_con_capacidad_ese_dia(): void
    {
        $actual = $this->repartidorEn('Zapopan');
        $libre = $this->repartidorEn('Zapopan');
        $apagado = $this->repartidorEn('Zapopan');
        $apagado->update(['estado' => 'no_disponible']);
        $lleno = $this->repartidorEn('Zapopan');
        $this->rutaDe($lleno, 30, 60);
        $medio = $this->repartidorEn('Zapopan');
        $this->rutaDe($medio, 20, 40);
        $ruta = $this->rutaDe($actual, 12, 30);
        $incidencia = $this->incidenciaDe($ruta);

        $filas = $this->getJson("/api/v1/admin/incidencias/{$incidencia->id}/candidatos")->assertOk()->json();

        $this->assertSame([$libre->id, $medio->id], array_column(array_column($filas, 'repartidor'), 'id'));
        $this->assertSame([[30, true], [10, false]], array_map(fn ($f) => [$f['capacidad_libre'], $f['alcanza']], $filas)); // al de 10 libres no le caben las 12 entregas
    }

    public function test_sin_ruta_o_ya_atendida_no_hay_candidatos(): void
    {
        $sinRuta = Incidencia::create(['fecha' => self::ENTREGA, 'tipo' => 'otro', 'descripcion' => 'Algo', 'prioridad' => 'normal']);
        $this->getJson("/api/v1/admin/incidencias/{$sinRuta->id}/candidatos")->assertStatus(422)->assertJsonPath('codigo', 'incidencia_sin_ruta');

        $atendida = $this->incidenciaDe($this->rutaDe($this->repartidorEn('Zapopan')));
        $atendida->update(['estado' => 'resuelta', 'resuelta_en' => now()]);
        $this->getJson("/api/v1/admin/incidencias/{$atendida->id}/candidatos")->assertStatus(422)->assertJsonPath('codigo', 'incidencia_cerrada');

        $this->getJson('/api/v1/admin/incidencias/999999/candidatos')->assertStatus(404);
    }

    // ------------------------------------------------------------------ reasignar

    public function test_reasignar_pasa_la_ruta_al_otro_repartidor_y_deja_constancia(): void
    {
        $actual = $this->repartidorEn('Zapopan');
        $nuevo = $this->repartidorEn('Zapopan');
        $ruta = $this->rutaDe($actual, 5, 20, 'aprobada');
        $incidencia = $this->incidenciaDe($ruta);

        $this->postJson("/api/v1/admin/incidencias/{$incidencia->id}/reasignar", ['repartidor_id' => $nuevo->id, 'nota' => 'Se enfermó'])->assertOk()
            ->assertJsonPath('estado', 'reasignada')->assertJsonPath('repartidor_reasignado_id', $nuevo->id)->assertJsonPath('nota', 'Se enfermó');

        $this->assertSame($nuevo->id, $ruta->fresh()->repartidor_id);
        $this->assertSame('aprobada', $ruta->fresh()->estado);
        $registro = RegistroAuditoria::where('accion', 'ruta.reasignada')->sole();
        $this->assertSame($this->admin->id, $registro->usuario_id);
        $this->assertStringContainsString("ruta {$ruta->id}", $registro->detalle);
    }

    public function test_no_reasigna_a_quien_no_es_candidato_ni_a_quien_no_le_cabe_la_ruta(): void
    {
        $actual = $this->repartidorEn('Zapopan');
        $apagado = $this->repartidorEn('Zapopan');
        $apagado->update(['estado' => 'no_disponible']);
        $casiLleno = $this->repartidorEn('Zapopan');
        $this->rutaDe($casiLleno, 25, 40);
        $ruta = $this->rutaDe($actual, 12, 30);
        $incidencia = $this->incidenciaDe($ruta);
        $url = "/api/v1/admin/incidencias/{$incidencia->id}/reasignar";

        $this->postJson($url, ['repartidor_id' => $apagado->id])->assertStatus(422)->assertJsonPath('codigo', 'repartidor_no_disponible');
        $this->postJson($url, ['repartidor_id' => $actual->id])->assertStatus(422)->assertJsonPath('codigo', 'repartidor_no_disponible');
        $this->postJson($url, ['repartidor_id' => $casiLleno->id])->assertStatus(422)->assertJsonPath('codigo', 'capacidad_insuficiente');
        $this->postJson($url, [])->assertStatus(422)->assertJsonStructure(['errores' => ['repartidor_id']]);

        $this->assertSame($actual->id, $ruta->fresh()->repartidor_id);
        $this->assertSame('abierta', $incidencia->fresh()->estado);
    }

    public function test_una_incidencia_reasignada_no_se_reasigna_de_nuevo(): void
    {
        $ruta = $this->rutaDe($this->repartidorEn('Zapopan'));
        $incidencia = $this->incidenciaDe($ruta);
        $uno = $this->repartidorEn('Zapopan');
        $otro = $this->repartidorEn('Zapopan');
        $url = "/api/v1/admin/incidencias/{$incidencia->id}/reasignar";

        $this->postJson($url, ['repartidor_id' => $uno->id])->assertOk();
        $this->postJson($url, ['repartidor_id' => $otro->id])->assertStatus(422)->assertJsonPath('codigo', 'incidencia_cerrada');

        $this->assertSame($uno->id, $ruta->fresh()->repartidor_id);
    }

    public function test_el_soporte_tambien_puede_reasignar(): void
    {
        $ruta = $this->rutaDe($this->repartidorEn('Zapopan'));
        $incidencia = $this->incidenciaDe($ruta);
        $nuevo = $this->repartidorEn('Zapopan');
        $this->comoRol('support');

        $this->getJson("/api/v1/admin/incidencias/{$incidencia->id}/candidatos")->assertOk()->assertJsonCount(1);
        $this->postJson("/api/v1/admin/incidencias/{$incidencia->id}/reasignar", ['repartidor_id' => $nuevo->id])->assertOk();
    }
}
