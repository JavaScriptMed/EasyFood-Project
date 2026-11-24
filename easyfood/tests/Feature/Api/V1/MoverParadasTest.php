<?php

namespace Tests\Feature\Api\V1;

use App\Models\Address;
use App\Models\EntregaEmpresa;
use App\Models\Paquete;
use App\Models\Parada;
use App\Models\Repartidor;
use App\Models\RegistroAuditoria;
use App\Models\Ruta;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ContratacionDePaquetes;
use App\Services\CorteDeIndividuales;
use App\Services\EjecutorDelCorte;
use App\Services\EleccionDePlatillos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * POST /admin/paradas/{id}/mover: la administración pasa una entrega pendiente de una ruta a otra del mismo día, zona y tipo para balancear
 * a los repartidores. Respeta la capacidad del auto, no vacía la ruta de origen, no toca rutas que ya salieron y no empalma al repartidor.
 */
class MoverParadasTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private const SABADO = '2026-11-14';

    private User $usuarioRepartidor;

    private Ruta $origen;     // empresas A (12:30), B (13:00) y C (11:00); platillos: A 2, B 1, C 1

    private Ruta $destino;    // vacía, de otro repartidor, misma zona y fecha

    private Ruta $deClientes;

    private Parada $paradaA;

    private Parada $paradaB;

    private Parada $paradaC;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ahoraEs('2026-11-11 10:00');
        $this->zonaDePruebas('Centro');
        $this->zonaDePruebas('Zapopan', -103.5, 20.8);
        $menu = $this->platillo(null, 'comida', ['name' => 'Pollo en mole']);
        foreach ([self::SABADO, '2026-11-16', '2026-11-17'] as $dia) {
            $this->disponible($menu, $dia);
        }
        // A (12:30, 2 platillos), B (13:00, 1) y C (11:00, 1): todas en Centro, el mismo sábado.
        foreach ([['12:30', '12:50', 2], ['13:00', '13:20', 1], ['11:00', '11:20', 1]] as [$inicio, $fin, $empleados]) {
            $empresa = $this->empresaConContrato('Centro', ['ventana_entrega_inicio' => $inicio, 'ventana_entrega_fin' => $fin, 'hora_entrega' => $inicio]);
            foreach (range(1, $empleados) as $i) {
                app(EleccionDePlatillos::class)->elegir($this->empleadoDe($empresa), self::SABADO, 'comida', $menu);
            }
        }
        $servicio = app(ContratacionDePaquetes::class);
        $paquete = Paquete::create(['nombre' => 'Dos días', 'dias' => 2, 'descuento_porcentaje' => 0]);
        foreach (['Clara', 'Beto'] as $nombre) {
            $cliente = $this->usuarioDeRol('customer', ['name' => "{$nombre} Cliente"]);
            UserProfile::create(['user_id' => $cliente->id, 'first_name' => $nombre, 'last_name' => 'Cliente', 'phone' => '33 1111 2222']);
            $direccion = Address::create(['user_id' => $cliente->id, 'street_address' => "Av. Vallarta {$nombre}", 'latitude' => 20.67, 'longitude' => -103.35]);
            $servicio->pagar($servicio->pedir($cliente, $paquete, $direccion, [$menu, $menu]), 'tarjeta', '4242');
        }

        $this->usuarioRepartidor = $this->usuarioDeRol('driver', ['name' => 'Rodrigo Repartidor']);
        Repartidor::create(['usuario_id' => $this->usuarioRepartidor->id, 'numero_empleado' => 'R-1', 'placas' => 'JAL-123-A', 'zona_base' => 'Centro']);

        $this->ahoraEs('2026-11-13 18:30');
        app(EjecutorDelCorte::class)->ejecutar(self::SABADO);
        app(CorteDeIndividuales::class)->ejecutar(self::SABADO);
        Ruta::query()->update(['estado' => 'aprobada']);

        $this->origen = Ruta::whereHas('paradas', fn ($p) => $p->whereNotNull('entrega_empresa_id'))->sole();
        $this->deClientes = Ruta::whereHas('paradas', fn ($p) => $p->whereNotNull('pedido_dia_id'))->sole();
        $this->destino = $this->rutaVacia($this->repartidorEn('Centro'), '12:00:00', '12:30:00');
        $paradas = $this->origen->paradas()->get()->keyBy(fn ($p) => $p->entregaEmpresa->ventana_inicio);
        [$this->paradaC, $this->paradaA, $this->paradaB] = [$paradas['11:00:00'], $paradas['12:30:00'], $paradas['13:00:00']];

        $this->ahoraEs('2026-11-14 10:00');
        $this->comoRol('admin');
    }

    private function rutaVacia(Repartidor $repartidor, string $inicio, string $fin, string $zona = 'Centro', string $fecha = self::SABADO, string $estado = 'aprobada'): Ruta
    {
        return Ruta::create([
            'fecha' => $fecha, 'repartidor_id' => $repartidor->id, 'zona' => $zona, 'ventana_inicio' => $inicio, 'ventana_fin' => $fin,
            'capacidad_entregas' => 30, 'capacidad_platillos' => 120, 'entregas_asignadas' => 0, 'platillos_asignados' => 0, 'hora_fin_estimada' => $fin, 'estado' => $estado,
        ]);
    }

    private function mover(Parada $parada, Ruta $ruta): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/admin/paradas/{$parada->id}/mover", ['ruta_id' => $ruta->id]);
    }

    public function test_el_estado_inicial_es_el_esperado(): void
    {
        $this->assertSame([3, 4], [$this->origen->entregas_asignadas, $this->origen->platillos_asignados]);
        $this->assertSame([1, 2, 3], $this->origen->paradas()->orderBy('orden')->pluck('orden')->all());
        $this->assertSame('11:00:00', $this->paradaC->entregaEmpresa->ventana_inicio, 'la primera por hora es la de las 11:00');
        $this->assertSame(1, $this->paradaC->orden);
    }

    public function test_mueve_una_entrega_y_recalcula_las_dos_rutas(): void
    {
        $r = $this->mover($this->paradaA, $this->destino)->assertOk()->json();

        $this->assertStringContainsString('pasó a la ruta de', $r['mensaje']);
        $this->assertCount(2, $r['rutas']);
        $origen = $this->origen->fresh();
        $destino = $this->destino->fresh();

        $this->assertSame([2, 2, '11:00:00', '13:20:00'], [$origen->entregas_asignadas, $origen->platillos_asignados, $origen->ventana_inicio, $origen->ventana_fin]);
        $this->assertSame([1, 2, '12:30:00', '12:50:00'], [$destino->entregas_asignadas, $destino->platillos_asignados, $destino->ventana_inicio, $destino->ventana_fin]);
        $this->assertSame([1, 2], $this->origen->paradas()->orderBy('orden')->pluck('orden')->all(), 'el orden queda seguido, sin huecos');
        $this->assertSame([$this->paradaC->id, $this->paradaB->id], $this->origen->paradas()->orderBy('orden')->pluck('id')->all());
        $this->assertSame([$this->destino->id, 1], [$this->paradaA->fresh()->ruta_id, $this->paradaA->fresh()->orden]);
        $this->assertSame($this->destino->id, EntregaEmpresa::find($this->paradaA->entrega_empresa_id)->ruta_id, 'la entrega de la empresa apunta a su ruta nueva');
        $this->assertTrue(RegistroAuditoria::where('accion', 'rutas.parada_movida')->where('entidad_id', (string) $this->paradaA->id)->exists());

        $paradaEnVista = collect($r['rutas'])->flatMap(fn ($ruta) => $ruta['paradas'])->firstWhere('id', $this->paradaA->id);
        $this->assertSame($this->destino->id, $paradaEnVista['ruta_id']);
        // Y el listado de la administración ya la ve en su ruta nueva.
        $listado = collect($this->getJson('/api/v1/admin/rutas?fecha='.self::SABADO)->assertOk()->json())->firstWhere('id', $this->destino->id);
        $this->assertSame([$this->paradaA->id], collect($listado['paradas'])->pluck('id')->all());
    }

    public function test_puede_mover_una_tras_otra_y_la_ruta_de_origen_conserva_al_menos_una(): void
    {
        $this->mover($this->paradaA, $this->destino)->assertOk();
        $this->mover($this->paradaB, $this->destino)->assertOk();

        $this->assertSame([2, 3, '12:30:00', '13:20:00'], [$this->destino->fresh()->entregas_asignadas, $this->destino->fresh()->platillos_asignados, $this->destino->fresh()->ventana_inicio, $this->destino->fresh()->ventana_fin]);
        $this->assertSame([1, 2], $this->destino->paradas()->orderBy('orden')->pluck('orden')->all());
        $this->assertSame($this->paradaA->id, $this->destino->paradas()->orderBy('orden')->first()->id, 'las empresas se ordenan por su hora de entrega');

        $this->mover($this->paradaC, $this->destino)->assertStatus(422)->assertJsonPath('codigo', 'ruta_quedaria_vacia');
        $this->assertSame(1, $this->origen->paradas()->count());
    }

    public function test_respeta_la_capacidad_de_entregas_y_de_platillos(): void
    {
        $this->destino->update(['capacidad_entregas' => 1, 'entregas_asignadas' => 1]);
        $this->mover($this->paradaA, $this->destino)->assertStatus(422)->assertJsonPath('codigo', 'capacidad_insuficiente')
            ->assertJsonPath('mensaje', fn ($m) => str_contains($m, 'ya lleva 1 de 1 entregas permitidas para su vehículo'));

        $this->destino->update(['capacidad_entregas' => 30, 'entregas_asignadas' => 0, 'capacidad_platillos' => 1, 'platillos_asignados' => 0]);
        $this->mover($this->paradaA, $this->destino)->assertStatus(422)->assertJsonPath('codigo', 'capacidad_insuficiente')
            ->assertJsonPath('mensaje', fn ($m) => str_contains($m, 'platillos permitidos'));

        $this->assertSame(3, $this->origen->fresh()->entregas_asignadas, 'nada cambió');
        $this->assertSame($this->origen->id, $this->paradaA->fresh()->ruta_id);
    }

    public function test_solo_entre_rutas_del_mismo_dia_zona_y_tipo(): void
    {
        $this->mover($this->paradaA, $this->rutaVacia($this->repartidorEn('Zapopan'), '12:00:00', '12:30:00', 'Zapopan'))->assertStatus(422)->assertJsonPath('codigo', 'otra_zona');
        $this->mover($this->paradaA, $this->rutaVacia($this->repartidorEn('Centro'), '12:00:00', '12:30:00', 'Centro', '2026-11-16'))->assertStatus(422)->assertJsonPath('codigo', 'otra_fecha');
        $this->mover($this->paradaA, $this->deClientes)->assertStatus(422)->assertJsonPath('codigo', 'otro_tipo_de_ruta');
        $this->mover($this->paradaA, $this->origen)->assertStatus(422)->assertJsonPath('codigo', 'misma_ruta');
        $this->assertSame($this->origen->id, $this->paradaA->fresh()->ruta_id);
    }

    public function test_no_toca_rutas_que_ya_salieron_ni_entregas_que_ya_van_en_camino(): void
    {
        $this->destino->update(['estado' => 'borrador']);
        $this->mover($this->paradaA, $this->destino)->assertOk(); // en borrador también se puede

        // El repartidor inicia su primera parada: la ruta pasa a en curso.
        Sanctum::actingAs($this->usuarioRepartidor);
        $this->postJson("/api/v1/repartidor/paradas/{$this->paradaC->id}/iniciar")->assertOk();
        $this->comoRol('admin');

        $this->mover($this->paradaC, $this->destino)->assertStatus(422)->assertJsonPath('codigo', 'parada_cerrada');
        $this->mover($this->paradaB, $this->destino)->assertStatus(422)->assertJsonPath('codigo', 'ruta_ya_iniciada');
        $this->mover($this->paradaA, $this->origen)->assertStatus(422)->assertJsonPath('codigo', 'ruta_ya_iniciada'); // tampoco se le agregan entregas
        $this->assertSame('en_curso', $this->origen->fresh()->estado);
        $this->assertSame(2, $this->origen->fresh()->entregas_asignadas, 'la ruta que salió no cambió');

        $otra = $this->rutaVacia($this->repartidorEn('Centro'), '14:00:00', '14:30:00');
        $this->mover($this->paradaB, $otra)->assertStatus(422)->assertJsonPath('codigo', 'ruta_ya_iniciada');
    }

    public function test_no_deja_empalmada_la_ruta_de_destino_con_otra_del_mismo_repartidor(): void
    {
        // El repartidor de la ruta destino ya tiene otra ruta de 12:40 a 13:30: la entrega de las 12:30 a las 12:50 se cruzaría.
        $this->rutaVacia($this->destino->repartidor, '12:40:00', '13:30:00')->update(['entregas_asignadas' => 0]);

        $this->mover($this->paradaA, $this->destino)->assertStatus(422)->assertJsonPath('codigo', 'ruta_se_empalma');
        $this->assertSame($this->origen->id, $this->paradaA->fresh()->ruta_id);
        $this->assertSame(3, $this->origen->fresh()->entregas_asignadas);
        $this->assertSame([1, 2, 3], $this->origen->paradas()->orderBy('orden')->pluck('orden')->all(), 'el rechazo deshace todo');
    }

    public function test_entre_rutas_de_clientes_se_reordena_y_recalcula_la_hora_de_cada_entrega(): void
    {
        $paradas = $this->deClientes->paradas()->orderBy('orden')->get();
        $this->assertCount(2, $paradas);
        $this->assertSame(['13:30:00', '13:40:00'], $paradas->pluck('hora_estimada')->all(), 'salen cuando su repartidor termina la ruta de empresas, una cada 10 minutos');
        $otraDeClientes = $this->rutaVacia($this->repartidorEn('Centro'), '12:00:00', '12:10:00');

        $this->mover($paradas[0], $otraDeClientes)->assertOk();

        $this->assertSame([1, 1, '13:30:00'], [$this->deClientes->fresh()->entregas_asignadas, $this->deClientes->paradas()->sole()->orden, $this->deClientes->paradas()->sole()->hora_estimada],
            'la que se quedó pasa a ser la primera y la hora se recalcula');
        $this->assertSame($paradas[1]->id, $this->deClientes->paradas()->sole()->id);
        $movida = $otraDeClientes->fresh();
        $this->assertSame([1, 1, '12:10:00', '12:10:00'], [$movida->entregas_asignadas, $movida->paradas()->sole()->orden, $movida->paradas()->sole()->hora_estimada, $movida->ventana_fin]);
        $this->assertNotNull(Parada::find($paradas[0]->id)->pedido_dia_id);

        // Y no se puede vaciar una ruta de clientes: la que quedó con una sola entrega la conserva.
        $this->mover($paradas[1], $otraDeClientes)->assertStatus(422)->assertJsonPath('codigo', 'ruta_quedaria_vacia');
    }

    public function test_solo_la_administracion_puede_mover_entregas(): void
    {
        foreach (['support', 'driver', 'customer'] as $rol) {
            $this->comoRol($rol);
            $this->mover($this->paradaA, $this->destino)->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/admin/paradas/{$this->paradaA->id}/mover", ['ruta_id' => $this->destino->id])->assertUnauthorized();
        $this->assertSame($this->origen->id, $this->paradaA->fresh()->ruta_id);
    }

    public function test_pide_una_ruta_que_exista_y_una_parada_que_exista(): void
    {
        $this->postJson("/api/v1/admin/paradas/{$this->paradaA->id}/mover", [])->assertStatus(422);
        $this->postJson("/api/v1/admin/paradas/{$this->paradaA->id}/mover", ['ruta_id' => 999999])->assertNotFound();
        $this->postJson('/api/v1/admin/paradas/999999/mover', ['ruta_id' => $this->destino->id])->assertNotFound();
    }
}
