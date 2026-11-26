<?php

namespace Tests\Feature\Api\V1;

use App\Models\Address;
use App\Models\Paquete;
use App\Models\Parada;
use App\Models\Pedido;
use App\Models\Recepcion;
use App\Models\RegistroAuditoria;
use App\Models\Repartidor;
use App\Models\Ruta;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ContratacionDePaquetes;
use App\Services\CorteDeIndividuales;
use App\Services\EjecutorDelCorte;
use App\Services\EleccionDePlatillos;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * El botón «Recibido»: el cliente (POST /cliente/pedidos/{id}/recibido) y el empleado (POST /empleado/recibido) confirman que les llegó
 * lo que el repartidor ya marcó como entregado. No cambia estados ni montos y no es requisito para calificar.
 */
class RecibidoTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private const SABADO = '2026-11-14';

    private User $repartidor;

    private User $empleado;

    private User $empleadoSinPedido;

    private Pedido $pedido;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ahoraEs('2026-11-11 10:00');
        $this->zonaDePruebas('Centro');
        $menu = $this->platillo(null, 'comida', ['name' => 'Pollo en mole']);
        foreach ([self::SABADO, '2026-11-16', '2026-11-17'] as $dia) {
            $this->disponible($menu, $dia);
        }
        $empresa = $this->empresaConContrato('Centro');
        $persona = $this->empleadoDe($empresa);
        app(EleccionDePlatillos::class)->elegir($persona, self::SABADO, 'comida', $menu);
        $this->empleado = User::findOrFail($persona->usuario_id);
        $this->darRol($this->empleado, 'employee');
        $otro = $this->empleadoDe($empresa);
        $this->empleadoSinPedido = User::findOrFail($otro->usuario_id);
        $this->darRol($this->empleadoSinPedido, 'employee');
        // El segundo empleado no elige y su empresa no tiene regla que le asigne platillo.
        $empresa->update(['sin_eleccion' => 'no_entregar']);

        $cliente = $this->usuarioDeRol('customer', ['name' => 'Clara Cliente']);
        UserProfile::create(['user_id' => $cliente->id, 'first_name' => 'Clara', 'last_name' => 'Cliente', 'phone' => '33 1111 2222']);
        $direccion = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. Vallarta 100', 'latitude' => 20.67, 'longitude' => -103.35]);
        $servicio = app(ContratacionDePaquetes::class);
        $this->pedido = $servicio->pagar($servicio->pedir($cliente, Paquete::create(['nombre' => 'Dos días', 'dias' => 2, 'descuento_porcentaje' => 0]), $direccion, [$menu, $menu]), 'tarjeta', '4242');

        $this->repartidor = $this->usuarioDeRol('driver', ['name' => 'Rodrigo Repartidor']);
        Repartidor::create(['usuario_id' => $this->repartidor->id, 'numero_empleado' => 'R-1', 'placas' => 'JAL-123-A', 'zona_base' => 'Centro']);

        $this->ahoraEs('2026-11-13 18:30');
        app(EjecutorDelCorte::class)->ejecutar(self::SABADO);
        app(CorteDeIndividuales::class)->ejecutar(self::SABADO);
        Ruta::query()->update(['estado' => 'aprobada']);
        $this->ahoraEs('2026-11-14 12:00');
    }

    private function darRol(User $usuario, string $rol): void
    {
        $usuario->forceFill(['role_id' => $this->usuarioDeRol($rol)->role_id, 'status' => 'active'])->save();
    }

    /** El repartidor entrega todas las paradas, cada una con su foto. */
    private function entregarTodo(): void
    {
        Sanctum::actingAs($this->repartidor);
        $foto = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
        foreach (Ruta::query()->orderBy('ventana_inicio')->get() as $ruta) {
            foreach ($ruta->paradas()->orderBy('orden')->get() as $parada) {
                $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/iniciar")->assertOk();
                $this->post("/api/v1/repartidor/paradas/{$parada->id}/entregar", ['foto' => UploadedFile::fake()->createWithContent('e.png', $foto)], ['Accept' => 'application/json'])->assertOk();
            }
        }
    }

    private function confirmarCliente(): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($this->pedido->cliente);

        return $this->postJson("/api/v1/cliente/pedidos/{$this->pedido->id}/recibido");
    }

    public function test_el_cliente_no_puede_confirmar_antes_de_que_el_repartidor_entregue(): void
    {
        $this->confirmarCliente()->assertStatus(422)->assertJsonPath('codigo', 'sin_entrega_que_confirmar');
        $this->assertSame(0, Recepcion::count());
    }

    public function test_el_cliente_confirma_una_vez_y_lo_ve_en_su_pedido_sin_que_cambie_nada_mas(): void
    {
        $this->entregarTodo();
        Sanctum::actingAs($this->pedido->cliente);
        $antes = $this->getJson("/api/v1/cliente/pedidos/{$this->pedido->id}")->assertOk()->json();
        $this->assertNull($antes['recibido_en']);

        $this->ahoraEs('2026-11-14 13:00');
        $primera = $this->confirmarCliente()->assertOk()->json();
        $this->assertNotEmpty($primera['recibido_en']);
        $this->ahoraEs('2026-11-14 15:00');
        $segunda = $this->confirmarCliente()->assertOk()->json();

        $this->assertSame($primera['recibido_en'], $segunda['recibido_en'], 'repetir devuelve la primera confirmación');
        $this->assertSame(1, Recepcion::count());
        $this->assertSame(1, RegistroAuditoria::where('accion', 'entrega.recibida')->count());
        $despues = $this->getJson("/api/v1/cliente/pedidos/{$this->pedido->id}")->assertOk()->json();
        $this->assertSame($primera['recibido_en'], $despues['recibido_en']);
        $this->assertSame([$antes['estado'], $antes['total'], $antes['dias'][0]['estado']], [$despues['estado'], $despues['total'], $despues['dias'][0]['estado']]);
        $this->assertSame($primera['recibido_en'], collect($this->getJson('/api/v1/cliente/pedidos')->assertOk()->json())->firstWhere('id', $this->pedido->id)['recibido_en']);

        $this->comoRol('admin');
        $this->assertSame($primera['recibido_en'], collect($this->getJson('/api/v1/admin/pedidos')->assertOk()->json())->firstWhere('id', $this->pedido->id)['recibido_en'], 'la administración también lo ve');
    }

    public function test_confirmar_no_es_requisito_para_calificar_y_se_puede_calificar_despues(): void
    {
        $this->entregarTodo();
        $this->confirmarCliente()->assertOk();
        $this->postJson("/api/v1/cliente/pedidos/{$this->pedido->id}/calificaciones/entrega", ['fecha' => self::SABADO, 'puntualidad' => 5, 'trato_chofer' => 5, 'empaque' => 4])->assertCreated();
    }

    public function test_un_cliente_no_confirma_el_pedido_de_otro(): void
    {
        $this->entregarTodo();
        $this->comoRol('customer');
        $this->postJson("/api/v1/cliente/pedidos/{$this->pedido->id}/recibido")->assertNotFound();
        $this->assertSame(0, Recepcion::count());
    }

    public function test_el_empleado_confirma_su_comida_y_aparece_en_sus_pendientes_de_calificar(): void
    {
        Sanctum::actingAs($this->empleado);
        $this->postJson('/api/v1/empleado/recibido', ['fecha' => self::SABADO])->assertStatus(422)->assertJsonPath('codigo', 'sin_entrega_que_confirmar');

        $this->entregarTodo();
        Sanctum::actingAs($this->empleado);
        $this->getJson('/api/v1/empleado/calificaciones/pendientes')->assertOk()->assertJsonPath('0.fecha', self::SABADO)->assertJsonPath('0.recibido_en', null);

        $primera = $this->postJson('/api/v1/empleado/recibido', ['fecha' => self::SABADO])->assertOk()->json('recibido_en');
        $this->assertSame($primera, $this->postJson('/api/v1/empleado/recibido', ['fecha' => self::SABADO])->assertOk()->json('recibido_en'));
        $this->assertSame(1, Recepcion::count());
        $this->getJson('/api/v1/empleado/calificaciones/pendientes')->assertOk()->assertJsonPath('0.recibido_en', $primera);
    }

    public function test_un_empleado_que_no_pidio_ese_dia_no_confirma(): void
    {
        $this->entregarTodo();
        Sanctum::actingAs($this->empleadoSinPedido);
        $this->postJson('/api/v1/empleado/recibido', ['fecha' => self::SABADO])->assertStatus(422)->assertJsonPath('codigo', 'sin_entrega_que_confirmar');
        $this->postJson('/api/v1/empleado/recibido', ['fecha' => 'ayer'])->assertStatus(422);
        $this->postJson('/api/v1/empleado/recibido', [])->assertStatus(422);
    }

    public function test_cada_boton_es_solo_de_su_rol(): void
    {
        $this->entregarTodo();
        foreach (['driver', 'admin', 'company'] as $rol) {
            $this->comoRol($rol);
            $this->postJson("/api/v1/cliente/pedidos/{$this->pedido->id}/recibido")->assertForbidden();
            $this->postJson('/api/v1/empleado/recibido', ['fecha' => self::SABADO])->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/empleado/recibido', ['fecha' => self::SABADO])->assertUnauthorized();
    }

    public function test_la_base_no_acepta_una_recepcion_de_algo_sin_entregar(): void
    {
        $parada = Parada::whereNotNull('pedido_dia_id')->firstOrFail();

        $this->expectException(QueryException::class);
        Recepcion::create(['usuario_id' => $this->pedido->cliente_id, 'pedido_dia_id' => $parada->pedido_dia_id, 'confirmada_en' => now()]);
    }
}
