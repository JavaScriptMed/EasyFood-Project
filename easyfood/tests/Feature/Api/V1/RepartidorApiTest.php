<?php

namespace Tests\Feature\Api\V1;

use App\Models\Address;
use App\Models\EntregaEmpresa;
use App\Models\Incidencia;
use App\Models\Paquete;
use App\Models\Parada;
use App\Models\Pedido;
use App\Models\PedidoDia;
use App\Models\RegistroAuditoria;
use App\Models\Repartidor;
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
 * API del repartidor: ponerse en línea o fuera de línea, ver sus rutas del día, iniciar la siguiente parada y cerrarla con foto de
 * evidencia o con un motivo. Solo el rol driver. El sábado 14 de nov de 2026 salen dos rutas del mismo repartidor: la de una empresa
 * (12:30) y, después, la de un cliente individual.
 */
class RepartidorApiTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private const SABADO = '2026-11-14';

    private Repartidor $repartidor;

    private User $usuarioRepartidor;

    private Ruta $rutaEmpresa;

    private Ruta $rutaCliente;

    private Pedido $pedido;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ahoraEs('2026-11-11 10:00'); // miércoles
        $this->zonaDePruebas('Centro');
        $this->armarElSabado();
        $this->ahoraEs('2026-11-14 12:00'); // sábado: salen a repartir
    }

    /** Una empresa con 2 empleados que eligieron, un cliente con un pedido de 2 días y un repartidor; los cortes y las rutas aprobadas. */
    private function armarElSabado(): void
    {
        $menu = $this->platillo(null, 'comida', ['name' => 'Pollo en mole']);
        foreach ([self::SABADO, '2026-11-16', '2026-11-17'] as $dia) {
            $this->disponible($menu, $dia);
        }
        $empresa = $this->empresaConContrato('Centro', ['ventana_entrega_inicio' => '12:30', 'ventana_entrega_fin' => '12:50', 'hora_entrega' => '12:40']);
        UserProfile::create(['user_id' => $empresa->usuario_id, 'first_name' => 'Rita', 'last_name' => 'RRHH', 'phone' => '33 3000 0001']);
        foreach (range(1, 2) as $i) {
            app(EleccionDePlatillos::class)->elegir($this->empleadoDe($empresa), self::SABADO, 'comida', $menu);
        }

        $cliente = $this->usuarioDeRol('customer', ['name' => 'Clara Cliente']);
        UserProfile::create(['user_id' => $cliente->id, 'first_name' => 'Clara', 'last_name' => 'Cliente', 'phone' => '33 1111 2222']);
        $direccion = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. Vallarta 100', 'internal_number' => '4B', 'delivery_instructions' => 'Portón negro', 'latitude' => 20.67, 'longitude' => -103.35]);
        $servicio = app(ContratacionDePaquetes::class);
        $this->pedido = $servicio->pagar($servicio->pedir($cliente, Paquete::create(['nombre' => 'Dos días', 'dias' => 2, 'descuento_porcentaje' => 0]), $direccion, [$menu, $menu]), 'tarjeta', '4242');

        $this->usuarioRepartidor = $this->usuarioDeRol('driver', ['name' => 'Rodrigo Repartidor']);
        $this->repartidor = Repartidor::create(['usuario_id' => $this->usuarioRepartidor->id, 'numero_empleado' => 'R-1', 'placas' => 'JAL-123-A', 'zona_base' => 'Centro']);

        $this->ahoraEs('2026-11-13 18:30'); // viernes: corte de empresas y de clientes
        app(EjecutorDelCorte::class)->ejecutar(self::SABADO);
        app(CorteDeIndividuales::class)->ejecutar(self::SABADO);
        Ruta::query()->update(['estado' => 'aprobada']);

        $this->rutaEmpresa = Ruta::whereHas('paradas', fn ($p) => $p->whereNotNull('entrega_empresa_id'))->sole();
        $this->rutaCliente = Ruta::whereHas('paradas', fn ($p) => $p->whereNotNull('pedido_dia_id'))->sole();
    }

    private function comoRepartidor(): static
    {
        Sanctum::actingAs($this->usuarioRepartidor);

        return $this;
    }

    private function paradaDeEmpresa(): Parada
    {
        return $this->rutaEmpresa->paradas()->sole();
    }

    private function paradaDeCliente(): Parada
    {
        return $this->rutaCliente->paradas()->sole();
    }

    private function foto(string $nombre = 'evidencia.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    }

    private function entregar(Parada $parada, ?UploadedFile $foto = null)
    {
        return $this->post("/api/v1/repartidor/paradas/{$parada->id}/entregar", ['foto' => $foto ?? $this->foto()], ['Accept' => 'application/json']);
    }

    // ------------------------------------------------------------------ quién entra

    public function test_solo_el_rol_driver_entra_a_la_api_del_repartidor(): void
    {
        $rutas = ['GET' => ['/api/v1/repartidor/rutas', '/api/v1/repartidor/disponibilidad'], 'PATCH' => ['/api/v1/repartidor/disponibilidad'], 'POST' => ['/api/v1/repartidor/paradas/1/iniciar', '/api/v1/repartidor/paradas/1/entregar', '/api/v1/repartidor/paradas/1/no-entregada']];

        foreach (['customer', 'admin', 'company', 'provider', 'employee', 'support'] as $rol) {
            $this->comoRol($rol);
            foreach ($rutas as $metodo => $urls) {
                foreach ($urls as $url) {
                    $this->json($metodo, $url, ['en_linea' => true, 'motivo' => 'x'])->assertStatus(403);
                }
            }
        }

        auth()->forgetGuards();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/repartidor/rutas')->assertStatus(401);
    }

    public function test_un_usuario_driver_sin_ficha_de_repartidor_activo_no_entra(): void
    {
        Sanctum::actingAs($this->usuarioDeRol('driver'));
        $this->getJson('/api/v1/repartidor/rutas')->assertStatus(403);

        $this->repartidor->desactivar();
        $this->comoRepartidor()->getJson('/api/v1/repartidor/disponibilidad')->assertStatus(403);
    }

    // ------------------------------------------------------------------ 1. en línea / fuera de línea

    public function test_se_pone_en_linea_y_fuera_de_linea_desde_su_propia_sesion(): void
    {
        $this->comoRepartidor()->getJson('/api/v1/repartidor/disponibilidad')->assertOk()
            ->assertExactJson(['en_linea' => true, 'estado' => 'libre', 'ruta_en_curso_id' => null]);

        $this->patchJson('/api/v1/repartidor/disponibilidad', ['en_linea' => false])->assertOk()
            ->assertExactJson(['en_linea' => false, 'estado' => 'no_disponible', 'ruta_en_curso_id' => null]);
        $this->assertSame('no_disponible', $this->repartidor->fresh()->estado);

        $this->patchJson('/api/v1/repartidor/disponibilidad', ['en_linea' => false])->assertOk()->assertJsonPath('en_linea', false); // repetirlo no cambia nada
        $this->patchJson('/api/v1/repartidor/disponibilidad', ['en_linea' => true])->assertOk()->assertJsonPath('estado', 'libre');
        $this->patchJson('/api/v1/repartidor/disponibilidad', ['en_linea' => true])->assertOk()->assertJsonPath('estado', 'libre');

        $this->assertSame(2, RegistroAuditoria::where('accion', 'repartidor.disponibilidad')->count(), 'solo deja bitácora cuando de verdad cambia');
        $this->patchJson('/api/v1/repartidor/disponibilidad', [])->assertStatus(422)->assertJsonStructure(['errores' => ['en_linea']]);
        $this->patchJson('/api/v1/repartidor/disponibilidad', ['en_linea' => 'tal vez'])->assertStatus(422);
    }

    public function test_no_se_puede_poner_fuera_de_linea_con_una_ruta_en_curso(): void
    {
        $this->comoRepartidor()->postJson("/api/v1/repartidor/paradas/{$this->paradaDeEmpresa()->id}/iniciar")->assertOk();

        $this->patchJson('/api/v1/repartidor/disponibilidad', ['en_linea' => false])->assertStatus(422)->assertJsonPath('codigo', 'ruta_en_curso');
        $this->assertSame('en_ruta', $this->repartidor->fresh()->estado, 'sigue en línea');
        $this->getJson('/api/v1/repartidor/disponibilidad')->assertOk()->assertJsonPath('ruta_en_curso_id', $this->rutaEmpresa->id)->assertJsonPath('en_linea', true);

        $this->patchJson('/api/v1/repartidor/disponibilidad', ['en_linea' => true])->assertOk()->assertJsonPath('estado', 'en_ruta');
    }

    public function test_con_rutas_aprobadas_sin_empezar_si_puede_ponerse_fuera_de_linea(): void
    {
        $this->comoRepartidor()->patchJson('/api/v1/repartidor/disponibilidad', ['en_linea' => false])->assertOk();

        $this->assertSame('aprobada', $this->rutaEmpresa->fresh()->estado, 'sus rutas siguen siendo suyas');
    }

    // ------------------------------------------------------------------ 2. ver sus rutas

    public function test_ve_sus_rutas_del_dia_con_las_paradas_y_todo_lo_necesario_para_la_calle(): void
    {
        $rutas = $this->comoRepartidor()->getJson('/api/v1/repartidor/rutas?fecha='.self::SABADO)->assertOk()->assertJsonCount(2)->json();

        [$deEmpresa, $deCliente] = $rutas; // ordenadas por la hora de salida: primero la de la empresa
        $this->assertSame([$this->rutaEmpresa->id, 'empresas', 'aprobada', 'Centro', '12:30', '12:50', 1, 0], [$deEmpresa['id'], $deEmpresa['tipo'], $deEmpresa['estado'], $deEmpresa['zona'], $deEmpresa['ventana_inicio'], $deEmpresa['ventana_fin'], $deEmpresa['paradas_totales'], $deEmpresa['paradas_cerradas']]);
        $this->assertSame([$this->rutaCliente->id, 'clientes'], [$deCliente['id'], $deCliente['tipo']]);
        $this->assertSame($deEmpresa['paradas'][0]['id'], $deEmpresa['siguiente_parada_id']);
        $this->assertNull($deEmpresa['iniciada_en']);

        $cliente = $deCliente['paradas'][0];
        $this->assertSame([1, 'pendiente', 'cliente', 'Clara Cliente', 'Av. Vallarta 100 int. 4B', '33 1111 2222', 'Portón negro', $this->pedido->folio], [
            $cliente['orden'], $cliente['estado'], $cliente['tipo'], $cliente['destinatario'], $cliente['direccion'], $cliente['telefono'], $cliente['referencias'], $cliente['folio'],
        ]);
        $this->assertEqualsWithDelta(20.67, $cliente['ubicacion']['latitud'], 0.000001);
        $this->assertEqualsWithDelta(-103.35, $cliente['ubicacion']['longitud'], 0.000001);
        $this->assertSame('12:50', $deCliente['ventana_inicio'], 'sale cuando termina la ruta de la empresa');
        $this->assertSame('13:00', $cliente['hora_estimada']);
        $this->assertSame(2, $cliente['total_platillos']);
        $this->assertSame([['nombre' => 'Pollo en mole', 'tiempo_comida' => 'comida', 'cantidad' => 1, 'dia_de_comida' => '2026-11-16', 'es_extra' => false], ['nombre' => 'Pollo en mole', 'tiempo_comida' => 'comida', 'cantidad' => 1, 'dia_de_comida' => '2026-11-17', 'es_extra' => false]], $cliente['platillos']);

        $empresa = $deEmpresa['paradas'][0];
        $this->assertSame(['empresa', '33 3000 0001', 2, null], [$empresa['tipo'], $empresa['telefono'], $empresa['total_platillos'], $empresa['ubicacion']], 'las empresas no tienen punto guardado');
        $this->assertSame([['nombre' => 'Pollo en mole', 'tiempo_comida' => 'comida', 'cantidad' => 2, 'dia_de_comida' => self::SABADO, 'es_extra' => false]], $empresa['platillos']);
        $this->assertSame(EntregaEmpresa::sole()->empresa->nombre, $empresa['destinatario']);
        foreach (['iniciada_en', 'cerrada_en', 'evidencia_foto_url', 'motivo_no_entrega'] as $campo) {
            $this->assertNull($empresa[$campo]);
        }
    }

    public function test_por_defecto_trae_las_de_hoy_y_solo_las_propias_aprobadas(): void
    {
        $this->comoRepartidor()->getJson('/api/v1/repartidor/rutas')->assertOk()->assertJsonCount(2); // hoy es el sábado 14
        $this->getJson('/api/v1/repartidor/rutas?fecha=2026-11-13')->assertOk()->assertExactJson([]);
        $this->getJson('/api/v1/repartidor/rutas?fecha=14/11')->assertStatus(422)->assertJsonStructure(['errores' => ['fecha']]);

        $this->rutaCliente->update(['estado' => 'borrador']);
        $this->getJson('/api/v1/repartidor/rutas')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $this->rutaEmpresa->id);

        $otro = Repartidor::create(['usuario_id' => $this->usuarioDeRol('driver')->id, 'numero_empleado' => 'R-2', 'placas' => 'JAL-999-Z', 'zona_base' => 'Centro']);
        $this->rutaEmpresa->update(['repartidor_id' => $otro->id]);
        $this->getJson('/api/v1/repartidor/rutas')->assertOk()->assertExactJson([]);
        Sanctum::actingAs($otro->usuario);
        $this->getJson('/api/v1/repartidor/rutas')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $this->rutaEmpresa->id);
    }

    // ------------------------------------------------------------------ 3. iniciar la siguiente parada

    public function test_iniciar_la_primera_parada_pasa_la_ruta_a_en_curso_y_al_repartidor_a_en_ruta(): void
    {
        $parada = $this->paradaDeEmpresa();

        $r = $this->comoRepartidor()->postJson("/api/v1/repartidor/paradas/{$parada->id}/iniciar")->assertOk()->json();

        $this->assertSame([$parada->id, 'en_camino'], [$r['parada']['id'], $r['parada']['estado']]);
        $this->assertNotNull($r['parada']['iniciada_en']);
        $this->assertSame(['en_curso', $parada->id, 1, 0], [$r['ruta']['estado'], $r['ruta']['siguiente_parada_id'], $r['ruta']['paradas_totales'], $r['ruta']['paradas_cerradas']]);
        $this->assertArrayNotHasKey('paradas', $r['ruta']);
        $this->assertSame('en_curso', $this->rutaEmpresa->fresh()->estado);
        $this->assertNotNull($this->rutaEmpresa->fresh()->iniciada_en);
        $this->assertSame('en_ruta', $this->repartidor->fresh()->estado);
        $this->assertSame('en_ruta', EntregaEmpresa::sole()->estado, 'la entrega a la empresa también va en ruta');
        $this->assertSame(['ruta.iniciada'], RegistroAuditoria::pluck('accion')->filter(fn ($a) => $a === 'ruta.iniciada')->values()->all());
    }

    public function test_las_paradas_van_en_orden_y_no_se_puede_saltar_ninguna(): void
    {
        // Una ruta de clientes con tres paradas: se arma a mano sobre la del cliente.
        $segundo = $this->parada($this->rutaCliente, 2, 'Segunda');
        $tercero = $this->parada($this->rutaCliente, 3, 'Tercera');
        $primera = $this->paradaDeClienteOrden1();
        $this->comoRepartidor();

        $this->postJson("/api/v1/repartidor/paradas/{$tercero->id}/iniciar")->assertStatus(422)->assertJsonPath('codigo', 'orden_de_paradas');
        $this->postJson("/api/v1/repartidor/paradas/{$segundo->id}/iniciar")->assertStatus(422)->assertJsonPath('codigo', 'orden_de_paradas');
        $this->assertSame('aprobada', $this->rutaCliente->fresh()->estado, 'un intento rechazado no la inicia');

        $this->postJson("/api/v1/repartidor/paradas/{$primera->id}/iniciar")->assertOk();
        $this->postJson("/api/v1/repartidor/paradas/{$primera->id}/iniciar")->assertStatus(422)->assertJsonPath('codigo', 'parada_en_camino');
        $this->postJson("/api/v1/repartidor/paradas/{$segundo->id}/iniciar")->assertStatus(422)->assertJsonPath('codigo', 'orden_de_paradas')
            ->assertJsonPath('mensaje', 'Primero atiende la parada 1 (Clara Cliente): las paradas van en orden.');

        $this->entregar($primera)->assertOk();
        $this->postJson("/api/v1/repartidor/paradas/{$tercero->id}/iniciar")->assertStatus(422)->assertJsonPath('codigo', 'orden_de_paradas');
        $this->postJson("/api/v1/repartidor/paradas/{$segundo->id}/iniciar")->assertOk()->assertJsonPath('ruta.siguiente_parada_id', $segundo->id);
        $this->assertSame('en_curso', $this->rutaCliente->fresh()->estado, 'la ruta ya estaba en curso: no vuelve a empezar');
    }

    public function test_no_inicia_una_ruta_ajena_un_borrador_ni_una_parada_que_no_existe(): void
    {
        $parada = $this->paradaDeEmpresa();
        $otro = $this->usuarioDeRol('driver');
        Repartidor::create(['usuario_id' => $otro->id, 'numero_empleado' => 'R-2', 'placas' => 'JAL-999-Z', 'zona_base' => 'Centro']);

        Sanctum::actingAs($otro);
        $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/iniciar")->assertStatus(404);
        $this->post("/api/v1/repartidor/paradas/{$parada->id}/entregar", ['foto' => $this->foto()], ['Accept' => 'application/json'])->assertStatus(404);
        $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/no-entregada", ['motivo' => 'Nadie abrió'])->assertStatus(404);

        $this->comoRepartidor();
        $this->postJson('/api/v1/repartidor/paradas/999999/iniciar')->assertStatus(404);
        $this->rutaEmpresa->update(['estado' => 'borrador']);
        $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/iniciar")->assertStatus(404);
        $this->assertSame('pendiente', $parada->fresh()->estado);
    }

    public function test_fuera_de_linea_no_inicia_y_con_una_ruta_en_curso_no_empieza_otra(): void
    {
        $this->comoRepartidor()->patchJson('/api/v1/repartidor/disponibilidad', ['en_linea' => false])->assertOk();
        $this->postJson("/api/v1/repartidor/paradas/{$this->paradaDeEmpresa()->id}/iniciar")->assertStatus(422)->assertJsonPath('codigo', 'fuera_de_linea');
        $this->assertSame('aprobada', $this->rutaEmpresa->fresh()->estado);

        $this->patchJson('/api/v1/repartidor/disponibilidad', ['en_linea' => true])->assertOk();
        $this->postJson("/api/v1/repartidor/paradas/{$this->paradaDeEmpresa()->id}/iniciar")->assertOk();
        $this->postJson("/api/v1/repartidor/paradas/{$this->paradaDeCliente()->id}/iniciar")->assertStatus(422)->assertJsonPath('codigo', 'otra_ruta_en_curso');
        $this->assertSame('aprobada', $this->rutaCliente->fresh()->estado);
    }

    // ------------------------------------------------------------------ 4. cerrar una parada

    public function test_confirma_la_entrega_con_foto_y_deja_el_pedido_del_cliente_completado(): void
    {
        $parada = $this->paradaDeCliente();
        $this->comoRepartidor()->postJson("/api/v1/repartidor/paradas/{$this->paradaDeEmpresa()->id}/iniciar")->assertOk();
        $this->entregar($this->paradaDeEmpresa())->assertOk();
        $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/iniciar")->assertOk();

        $r = $this->entregar($parada)->assertOk()->json();

        $this->assertSame([$parada->id, 'entregado'], [$r['parada']['id'], $r['parada']['estado']]);
        $this->assertNotNull($r['parada']['cerrada_en']);
        $this->assertStringContainsString('/storage/evidencias/'.$parada->id.'-', $r['parada']['evidencia_foto_url']);
        $archivo = 'evidencias/'.basename($r['parada']['evidencia_foto_url']);
        Storage::disk('public')->assertExists($archivo);

        $dia = PedidoDia::sole();
        $this->assertSame('entregada', $dia->estado);
        $this->assertNotNull($dia->entregada_en);
        $this->assertSame($r['parada']['evidencia_foto_url'], $dia->evidencia_foto_url, 'la evidencia también queda en la entrega del pedido');
        $this->assertSame('completado', $this->pedido->fresh()->estado);
        $this->assertSame(1, RegistroAuditoria::where('accion', 'parada.entregada')->where('entidad_id', (string) $parada->id)->count());
    }

    public function test_confirmar_la_entrega_a_una_empresa_la_deja_entregada(): void
    {
        $parada = $this->paradaDeEmpresa();
        $this->comoRepartidor()->postJson("/api/v1/repartidor/paradas/{$parada->id}/iniciar")->assertOk();

        $this->entregar($parada)->assertOk()->assertJsonPath('parada.estado', 'entregado');

        $this->assertSame('entregada', EntregaEmpresa::sole()->estado);
    }

    public function test_la_foto_es_obligatoria_y_tiene_que_ser_una_imagen(): void
    {
        $parada = $this->paradaDeEmpresa();
        $this->comoRepartidor()->postJson("/api/v1/repartidor/paradas/{$parada->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/entregar", [])->assertStatus(422)->assertJsonStructure(['errores' => ['foto']]);
        $this->entregar($parada, UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf'))->assertStatus(422)->assertJsonStructure(['errores' => ['foto']]);
        $this->entregar($parada, UploadedFile::fake()->createWithContent('dibujo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertStatus(422);
        $this->entregar($parada, UploadedFile::fake()->create('enorme.png', 6000, 'image/png'))->assertStatus(422);

        $this->assertSame('en_camino', $parada->fresh()->estado, 'ningún intento rechazado la cerró');
        $this->assertSame([], Storage::disk('public')->allFiles('evidencias'));
    }

    public function test_no_se_entrega_una_parada_que_no_se_inicio_ni_una_ya_atendida(): void
    {
        $parada = $this->paradaDeEmpresa();
        $this->comoRepartidor();

        $this->entregar($parada)->assertStatus(422)->assertJsonPath('codigo', 'parada_no_iniciada');
        $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/no-entregada", ['motivo' => 'Cerrado'])->assertStatus(422)->assertJsonPath('codigo', 'parada_no_iniciada');

        $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/iniciar")->assertOk();
        $this->entregar($parada)->assertOk();
        $this->entregar($parada)->assertStatus(422)->assertJsonPath('codigo', 'parada_ya_atendida');
        $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/no-entregada", ['motivo' => 'Cerrado'])->assertStatus(422)->assertJsonPath('codigo', 'parada_ya_atendida');
        $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/iniciar")->assertStatus(422)->assertJsonPath('codigo', 'ruta_completada');
        $this->assertCount(1, Storage::disk('public')->allFiles('evidencias'), 'solo la foto de la entrega buena');
    }

    public function test_marca_una_parada_como_no_entregada_con_su_motivo_y_abre_una_incidencia(): void
    {
        $parada = $this->paradaDeCliente();
        $this->comoRepartidor();
        $this->postJson("/api/v1/repartidor/paradas/{$this->paradaDeEmpresa()->id}/iniciar")->assertOk();
        $this->entregar($this->paradaDeEmpresa())->assertOk();
        $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/iniciar")->assertOk();

        $r = $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/no-entregada", ['motivo' => '  Nadie abrió la puerta  '])->assertOk()->json();

        $this->assertSame([$parada->id, 'no_localizado', 'Nadie abrió la puerta'], [$r['parada']['id'], $r['parada']['estado'], $r['parada']['motivo_no_entrega']]);
        $this->assertNotNull($r['parada']['cerrada_en']);
        $this->assertNull($r['parada']['evidencia_foto_url']);
        $this->assertSame('no_localizado', PedidoDia::sole()->estado);
        $this->assertSame('recibido', $this->pedido->fresh()->estado, 'el pedido no se completa: lo resuelve el administrador');

        $incidencia = Incidencia::where('tipo', 'entrega_no_localizada')->sole();
        $this->assertSame(['inmediata', 'abierta', $this->rutaCliente->id, PedidoDia::sole()->id, null, self::SABADO], [$incidencia->prioridad, $incidencia->estado, $incidencia->ruta_id, $incidencia->pedido_dia_id, $incidencia->entrega_empresa_id, $incidencia->fecha->toDateString()]);
        $this->assertStringContainsString('Nadie abrió la puerta', $incidencia->descripcion);
        $this->assertStringContainsString($this->pedido->folio, $incidencia->descripcion);
        $this->assertSame(1, RegistroAuditoria::where('accion', 'parada.no_entregada')->count());
    }

    public function test_no_entregada_a_una_empresa_la_deja_no_localizada_con_su_incidencia(): void
    {
        $parada = $this->paradaDeEmpresa();
        $this->comoRepartidor()->postJson("/api/v1/repartidor/paradas/{$parada->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/no-entregada", ['motivo' => 'La empresa estaba cerrada'])->assertOk();

        $this->assertSame('no_localizado', EntregaEmpresa::sole()->estado);
        $this->assertSame(EntregaEmpresa::sole()->id, Incidencia::where('tipo', 'entrega_no_localizada')->sole()->entrega_empresa_id);
    }

    public function test_el_motivo_es_obligatorio(): void
    {
        $parada = $this->paradaDeEmpresa();
        $this->comoRepartidor()->postJson("/api/v1/repartidor/paradas/{$parada->id}/iniciar")->assertOk();

        $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/no-entregada", [])->assertStatus(422)->assertJsonStructure(['errores' => ['motivo']]);
        $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/no-entregada", ['motivo' => 'ab'])->assertStatus(422);
        $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/no-entregada", ['motivo' => str_repeat('x', 256)])->assertStatus(422);
        $this->assertSame('en_camino', $parada->fresh()->estado);
        $this->assertSame(0, Incidencia::where('tipo', 'entrega_no_localizada')->count());
    }

    public function test_al_cerrar_la_ultima_parada_la_ruta_se_completa_y_el_repartidor_queda_libre(): void
    {
        $this->comoRepartidor();
        $this->postJson("/api/v1/repartidor/paradas/{$this->paradaDeEmpresa()->id}/iniciar")->assertOk();
        $r = $this->postJson("/api/v1/repartidor/paradas/{$this->paradaDeEmpresa()->id}/no-entregada", ['motivo' => 'Cerrado'])->assertOk()->json();

        $this->assertSame(['completada', null, 1, 1], [$r['ruta']['estado'], $r['ruta']['siguiente_parada_id'], $r['ruta']['paradas_totales'], $r['ruta']['paradas_cerradas']]);
        $this->assertNotNull($r['ruta']['completada_en']);
        $this->assertSame('libre', $this->repartidor->fresh()->estado);
        $this->patchJson('/api/v1/repartidor/disponibilidad', ['en_linea' => false])->assertOk()->assertJsonPath('en_linea', false); // ya no hay ruta en curso

        $this->patchJson('/api/v1/repartidor/disponibilidad', ['en_linea' => true])->assertOk();
        $this->postJson("/api/v1/repartidor/paradas/{$this->paradaDeCliente()->id}/iniciar")->assertOk()->assertJsonPath('ruta.estado', 'en_curso'); // puede empezar la siguiente ruta
        $this->getJson('/api/v1/repartidor/rutas')->assertOk()->assertJsonPath('0.estado', 'completada')->assertJsonPath('1.estado', 'en_curso');
    }

    public function test_el_administrador_ve_el_avance_en_sus_endpoints_y_el_repartidor_ocupado_no_cambia_de_disponibilidad(): void
    {
        $this->comoRepartidor()->postJson("/api/v1/repartidor/paradas/{$this->paradaDeEmpresa()->id}/iniciar")->assertOk();

        $this->comoRol('admin');
        $this->patchJson("/api/v1/admin/repartidores/{$this->repartidor->id}/disponibilidad")->assertStatus(422)->assertJsonPath('codigo', 'repartidor_ocupado');
        $ruta = collect($this->getJson('/api/v1/admin/rutas?fecha='.self::SABADO)->assertOk()->json())->firstWhere('id', $this->rutaEmpresa->id);
        $this->assertSame('en_curso', $ruta['estado']);
    }

    // ------------------------------------------------------------------ apoyo

    private function paradaDeClienteOrden1(): Parada
    {
        return $this->rutaCliente->paradas()->where('orden', 1)->sole();
    }

    /** Una parada más en una ruta (para probar el orden), de una empresa con una entrega de relleno. */
    private function parada(Ruta $ruta, int $orden, string $nombre): Parada
    {
        $entrega = EntregaEmpresa::create([
            'empresa_id' => $this->empresaConContrato('Centro')->id, 'fecha' => self::SABADO, 'platillos' => 1, 'ventana_inicio' => '13:00', 'ventana_fin' => '13:20', 'estado' => 'programada', 'ruta_id' => $ruta->id,
        ]);

        return Parada::create(['ruta_id' => $ruta->id, 'orden' => $orden, 'entrega_empresa_id' => $entrega->id, 'nombre_destinatario' => $nombre, 'direccion' => 'Calle '.$orden, 'hora_estimada' => '13:00']);
    }
}
