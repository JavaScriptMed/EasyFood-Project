<?php

namespace Tests\Feature\Api\V1;

use App\Models\Address;
use App\Models\Paquete;
use App\Models\Pedido;
use App\Models\RegistroAuditoria;
use App\Models\User;
use App\Services\ContratacionDePaquetes;
use App\Services\Pagos\PasarelaDePagos;
use App\Services\PagosDePedidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\Support\PasarelaFalsa;
use Tests\TestCase;

/** POST /api/v1/admin/pedidos/{id}/reembolso: reembolso total de un pedido pagado con Stripe. Solo administración y soporte. */
class ReembolsoDePedidosApiTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private PasarelaFalsa $pasarela;

    private Pedido $pedido;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(PasarelaDePagos::class, $this->pasarela = new PasarelaFalsa);
        $this->ahoraEs('2026-11-09 10:00');
        $this->zonaDePruebas();
        $this->pedido = $this->pedidoPagado();
    }

    private function pedidoPagado(): Pedido
    {
        $cliente = User::findOrFail($this->usuario());
        $direccion = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. Vallarta 100', 'latitude' => 20.67, 'longitude' => -103.35]);
        $menu = $this->platillo(null, 'comida', ['price' => 100]);
        $this->disponible($menu, '2026-11-10');
        $paquete = Paquete::firstOrCreate(['nombre' => 'Un día'], ['dias' => 1, 'descuento_porcentaje' => 0]);
        $pedido = app(ContratacionDePaquetes::class)->pedir($cliente, $paquete, $direccion, [$menu]);
        $intento = app(PagosDePedidos::class)->iniciar($cliente, $pedido->id)['payment_intent_id'];
        $this->pasarela->cobrar($intento, '2026-11-09T11:00:00-06:00');
        [$cuerpo] = $this->pasarela->webhook('evt_1', 'payment_intent.succeeded', $intento, 10000, '2026-11-09T11:00:00-06:00');
        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => PasarelaFalsa::FIRMA], $cuerpo)->assertOk();

        return $pedido->fresh();
    }

    public function test_el_administrador_reembolsa_un_pedido_pagado_y_queda_cancelado_con_los_datos_del_reembolso(): void
    {
        $admin = $this->comoRol('admin');

        $r = $this->postJson("/api/v1/admin/pedidos/{$this->pedido->id}/reembolso")->assertOk()
            ->assertJsonPath('estado', 'cancelado')->assertJsonPath('stripe_refund_id', 're_prueba_1')->json();

        $this->assertEquals(100.0, $r['monto_reembolsado']);
        $this->assertNotNull($r['reembolsado_en']);
        $this->assertSame('cancelado', $this->pedido->fresh()->estado);
        $this->assertSame($admin->id, RegistroAuditoria::where('accion', 'pedido.reembolsado')->sole()->usuario_id, 'la bitácora dice quién lo hizo');
    }

    public function test_soporte_tambien_puede_reembolsar(): void
    {
        $soporte = $this->comoRol('support');

        $this->postJson("/api/v1/admin/pedidos/{$this->pedido->id}/reembolso")->assertOk()->assertJsonPath('estado', 'cancelado');

        $this->assertSame($soporte->id, RegistroAuditoria::where('accion', 'pedido.reembolsado')->sole()->usuario_id);
    }

    public function test_los_demas_roles_y_quien_no_inicio_sesion_no_pueden(): void
    {
        $this->postJson("/api/v1/admin/pedidos/{$this->pedido->id}/reembolso")->assertStatus(401)->assertJsonPath('codigo', 'no_autenticado');

        foreach (['customer', 'company', 'provider', 'employee', 'driver'] as $rol) {
            $this->comoRol($rol);
            $this->postJson("/api/v1/admin/pedidos/{$this->pedido->id}/reembolso")->assertStatus(403);
        }

        $this->assertSame('recibido', $this->pedido->fresh()->estado);
        $this->assertCount(0, $this->pasarela->reembolsos);
    }

    public function test_no_se_reembolsa_dos_veces(): void
    {
        $this->comoRol('admin');
        $this->postJson("/api/v1/admin/pedidos/{$this->pedido->id}/reembolso")->assertOk();

        $this->postJson("/api/v1/admin/pedidos/{$this->pedido->id}/reembolso")->assertStatus(422)->assertJsonPath('codigo', 'pedido_ya_reembolsado');

        $this->assertCount(1, $this->pasarela->reembolsos);
    }

    public function test_responde_los_errores_de_negocio_con_su_codigo(): void
    {
        $this->comoRol('admin');
        $this->postJson('/api/v1/admin/pedidos/999999/reembolso')->assertStatus(404);

        $this->pedido->update(['estado' => 'completado']);
        $this->postJson("/api/v1/admin/pedidos/{$this->pedido->id}/reembolso")->assertStatus(422)->assertJsonPath('codigo', 'pedido_no_reembolsable');
    }

    public function test_sin_las_claves_de_stripe_responde_503_y_si_stripe_falla_responde_502_sin_cambiar_el_pedido(): void
    {
        $this->comoRol('admin');

        $this->pasarela->fallaAlReembolsar = 'Stripe no responde';
        $this->postJson("/api/v1/admin/pedidos/{$this->pedido->id}/reembolso")->assertStatus(502)->assertJsonPath('codigo', 'pasarela_con_error');
        $this->assertSame('recibido', $this->pedido->fresh()->estado);
        $this->assertSame(0, RegistroAuditoria::where('accion', 'pedido.reembolsado')->count());

        $this->pasarela->configurada = false;
        $this->postJson("/api/v1/admin/pedidos/{$this->pedido->id}/reembolso")->assertStatus(503)->assertJsonPath('codigo', 'pagos_no_configurados');
    }

    public function test_despues_de_un_fallo_de_stripe_el_reintento_reembolsa_bien(): void
    {
        $this->comoRol('admin');
        $this->pasarela->fallaAlReembolsar = 'Stripe no responde';
        $this->postJson("/api/v1/admin/pedidos/{$this->pedido->id}/reembolso")->assertStatus(502);

        $this->pasarela->fallaAlReembolsar = null;
        $this->postJson("/api/v1/admin/pedidos/{$this->pedido->id}/reembolso")->assertOk()->assertJsonPath('estado', 'cancelado');
    }
}
