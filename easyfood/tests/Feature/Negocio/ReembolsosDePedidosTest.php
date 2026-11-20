<?php

namespace Tests\Feature\Negocio;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Address;
use App\Models\DisponibilidadDia;
use App\Models\Paquete;
use App\Models\Pedido;
use App\Models\RegistroAuditoria;
use App\Models\StripeEvento;
use App\Models\User;
use App\Services\ContratacionDePaquetes;
use App\Services\Pagos\ErrorDePasarela;
use App\Services\Pagos\PasarelaDePagos;
use App\Services\PagosDePedidos;
use App\Services\ReembolsosDePedidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\Support\PasarelaFalsa;
use Tests\TestCase;

/**
 * Reembolso total de pedidos pagados con Stripe: por administración o soporte, y el webhook `charge.refunded`.
 * Sin estado nuevo: el pedido reembolsado queda «cancelado» con reembolsado_en, monto_reembolsado y stripe_refund_id.
 * Pasarela falsa (sin red). «Hoy» es el lunes 9 de nov de 2026; el pedido es para el martes 10 y cuesta $100.
 */
class ReembolsosDePedidosTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private PasarelaFalsa $pasarela;

    private User $cliente;

    private User $admin;

    private Pedido $pedido;

    private string $intento;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(PasarelaDePagos::class, $this->pasarela = new PasarelaFalsa);
        $this->ahoraEs('2026-11-09 10:00');
        $this->zonaDePruebas();
        $this->cliente = User::findOrFail($this->usuario());
        $this->admin = User::findOrFail($this->usuario());
        $this->pedido = $this->pedidoPagado();
    }

    /** Un pedido de $100 pagado con Stripe: se inicia el pago, se cobra y llega el webhook. */
    private function pedidoPagado(): Pedido
    {
        $direccion = Address::create(['user_id' => $this->cliente->id, 'street_address' => 'Av. Vallarta 100', 'latitude' => 20.67, 'longitude' => -103.35]);
        $menu = $this->platillo(null, 'comida', ['price' => 100]);
        $this->disponible($menu, '2026-11-16');
        $paquete = Paquete::firstOrCreate(['nombre' => 'Un día'], ['dias' => 1, 'descuento_porcentaje' => 0]);
        $pedido = app(ContratacionDePaquetes::class)->pedir($this->cliente, $paquete, $direccion, [$menu]);

        $this->intento = app(PagosDePedidos::class)->iniciar($this->cliente, $pedido->id)['payment_intent_id'];
        $this->pasarela->cobrar($this->intento, '2026-11-14T11:00:00-06:00');
        $this->enviar($this->pasarela->webhook('evt_pago_'.$pedido->id, 'payment_intent.succeeded', $this->intento, 10000, '2026-11-14T11:00:00-06:00'))->assertJsonPath('resultado', 'pagado');

        return $pedido->fresh();
    }

    /** @param  array{0: string, 1: array<string, string>}  $evento */
    private function enviar(array $evento): TestResponse
    {
        return $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $evento[1]['Stripe-Signature']], $evento[0]);
    }

    private function reembolsar(?Pedido $pedido = null): Pedido
    {
        return app(ReembolsosDePedidos::class)->reembolsar(($pedido ?? $this->pedido)->id, $this->admin);
    }

    private function codigo(callable $accion): string
    {
        try {
            $accion();
        } catch (ReglaDeNegocioException $e) {
            return $e->codigo;
        }

        return 'sin error';
    }

    // ------------------------------------------------------------------ reembolso por administración o soporte

    public function test_el_reembolso_es_total_cancela_el_pedido_y_anota_cuando_cuanto_y_con_que_reembolso(): void
    {
        $this->assertSame(1, (int) DisponibilidadDia::sum('porciones_tomadas'));

        $pedido = $this->reembolsar();

        $this->assertSame('cancelado', $pedido->estado, 'no hay estado nuevo');
        $this->assertSame('100.00', $pedido->monto_reembolsado);
        $this->assertSame('re_prueba_1', $pedido->stripe_refund_id);
        $this->assertNotNull($pedido->reembolsado_en);
        $this->assertNotNull($pedido->pagado_en, 'se conserva cuándo se pagó');
        $this->assertSame(0, (int) DisponibilidadDia::sum('porciones_tomadas'), 'se liberan las porciones');
        $this->assertSame(['cancelada'], $pedido->dias->pluck('estado')->all());
    }

    public function test_el_reembolso_usa_una_llave_de_idempotencia_por_cobro_y_pide_exactamente_lo_cobrado(): void
    {
        $this->reembolsar();

        $llamada = collect($this->pasarela->llamadas)->firstWhere(0, 'crearReembolso');
        $this->assertSame([$this->intento, 10000, 'easylunch-reembolso-pedido-'.$this->pedido->id.'-ch_'.$this->intento], [$llamada[1], $llamada[2], $llamada[3]]);
        $this->assertSame((string) $this->admin->id, $llamada[4]['solicitado_por']);
    }

    public function test_la_bitacora_dice_quien_hizo_el_reembolso(): void
    {
        $this->reembolsar();

        $registro = RegistroAuditoria::where('accion', 'pedido.reembolsado')->sole();
        $this->assertSame($this->admin->id, $registro->usuario_id);
        $this->assertSame((string) $this->pedido->id, $registro->entidad_id);
        $this->assertStringContainsString('100.00', $registro->detalle);
        $this->assertStringContainsString('re_prueba_1', $registro->detalle);
        $this->assertStringContainsString($this->admin->name, $registro->detalle);
    }

    public function test_no_hay_doble_reembolso(): void
    {
        $this->reembolsar();

        $this->assertSame('pedido_ya_reembolsado', $this->codigo(fn () => $this->reembolsar()));

        $this->assertCount(1, $this->pasarela->reembolsos, 'Stripe recibió un solo reembolso');
        $this->assertCount(1, RegistroAuditoria::where('accion', 'pedido.reembolsado')->get());
    }

    public function test_nunca_se_reembolsa_mas_de_lo_cobrado_si_ya_se_devolvio_una_parte_en_stripe(): void
    {
        $this->pasarela->intentos[$this->intento]['cargo']['reembolsado'] = 2500; // 25 pesos ya devueltos desde Stripe

        $pedido = $this->reembolsar();

        $llamada = collect($this->pasarela->llamadas)->firstWhere(0, 'crearReembolso');
        $this->assertSame(7500, $llamada[2], 'solo lo que falta del cobro');
        $this->assertSame('100.00', $pedido->monto_reembolsado, 'anota todo lo devuelto del cobro: los 25 de antes y los 75 de ahora');
    }

    public function test_si_stripe_ya_devolvio_todo_el_cobro_no_se_vuelve_a_reembolsar(): void
    {
        $this->pasarela->intentos[$this->intento]['cargo']['reembolsado'] = 10000;

        $this->assertSame('cobro_ya_reembolsado', $this->codigo(fn () => $this->reembolsar()));

        $this->assertSame('recibido', $this->pedido->fresh()->estado);
        $this->assertCount(0, $this->pasarela->reembolsos);
    }

    public function test_solo_se_reembolsa_un_pedido_pagado_con_stripe_y_con_el_cobro_del_total(): void
    {
        $this->pedido->update(['estado' => 'activo']);
        $this->assertSame('sin error', $this->codigo(fn () => $this->reembolsar()), 'activo también es un pedido pagado');

        $porPagar = $this->pedidoPorPagarNuevo();
        $this->assertSame('pedido_no_reembolsable', $this->codigo(fn () => $this->reembolsar($porPagar)), 'por pagar');

        $simulado = $this->pedidoPorPagarNuevo();
        app(ContratacionDePaquetes::class)->pagar($simulado, 'tarjeta', '4242'); // pago simulado: no hay cobro en Stripe
        $this->assertSame('pedido_sin_cobro_en_stripe', $this->codigo(fn () => $this->reembolsar($simulado)));

        $distinto = $this->pedidoPagado2();
        $this->pasarela->intentos[$distinto->stripe_payment_intent_id]['cargo']['monto'] = 5000;
        $this->assertSame('cobro_distinto_al_pedido', $this->codigo(fn () => $this->reembolsar($distinto)));
    }

    public function test_si_stripe_falla_el_pedido_no_cambia_y_no_queda_nada_anotado(): void
    {
        $this->pasarela->fallaAlReembolsar = 'Stripe no responde';

        try {
            $this->reembolsar();
            $this->fail('Debía propagar el error de la pasarela.');
        } catch (ErrorDePasarela $e) {
            $this->assertSame('Stripe no responde', $e->getMessage());
        }

        $pedido = $this->pedido->fresh();
        $this->assertSame(['recibido', null, null], [$pedido->estado, $pedido->reembolsado_en, $pedido->stripe_refund_id]);
        $this->assertSame(0, RegistroAuditoria::where('accion', 'pedido.reembolsado')->count());
        $this->assertSame(1, (int) DisponibilidadDia::sum('porciones_tomadas'));
    }

    // ------------------------------------------------------------------ webhook charge.refunded

    public function test_un_reembolso_hecho_desde_stripe_cancela_el_pedido_pagado_y_anota_el_reembolso(): void
    {
        $this->pasarela->crearReembolso($this->intento, 10000, 'llave-del-dashboard', []); // lo hace una persona en el Dashboard de Stripe

        $this->enviar($this->pasarela->webhookReembolso('evt_ref_1', $this->intento))->assertOk()->assertJsonPath('resultado', 'reembolsado');

        $pedido = $this->pedido->fresh();
        $this->assertSame(['cancelado', '100.00', 're_prueba_1'], [$pedido->estado, $pedido->monto_reembolsado, $pedido->stripe_refund_id]);
        $this->assertNotNull($pedido->reembolsado_en);
        $this->assertSame(0, (int) DisponibilidadDia::sum('porciones_tomadas'));
        $registro = RegistroAuditoria::where('accion', 'pedido.reembolsado')->sole();
        $this->assertNull($registro->usuario_id, 'lo hizo Stripe, no una persona de EasyLunch');
        $this->assertSame('reembolsado', StripeEvento::where('stripe_event_id', 'evt_ref_1')->value('resultado'));
    }

    public function test_el_webhook_de_reembolso_repetido_no_hace_nada_la_segunda_vez(): void
    {
        $this->pasarela->crearReembolso($this->intento, 10000, 'llave-del-dashboard', []);
        $this->enviar($this->pasarela->webhookReembolso('evt_ref_1', $this->intento));
        $reembolsadoEn = $this->pedido->fresh()->reembolsado_en;

        $this->enviar($this->pasarela->webhookReembolso('evt_ref_1', $this->intento))->assertOk()->assertJsonPath('resultado', 'repetido');

        $this->assertEquals($reembolsadoEn, $this->pedido->fresh()->reembolsado_en);
        $this->assertSame(1, RegistroAuditoria::where('accion', 'pedido.reembolsado')->count());
    }

    public function test_el_webhook_despues_de_un_reembolso_por_el_endpoint_converge_sin_repetir_nada(): void
    {
        $this->reembolsar(); // cancela el pedido al momento, sin esperar al webhook
        $this->assertSame('cancelado', $this->pedido->fresh()->estado);

        $this->enviar($this->pasarela->webhookReembolso('evt_ref_1', $this->intento))->assertOk()->assertJsonPath('resultado', 'reembolsado');

        $this->assertSame(1, RegistroAuditoria::where('accion', 'pedido.reembolsado')->count(), 'no se anota dos veces');
        $this->assertSame('re_prueba_1', $this->pedido->fresh()->stripe_refund_id);
    }

    public function test_un_reembolso_parcial_no_cancela_el_pedido_pero_deja_constancia(): void
    {
        $this->pasarela->crearReembolso($this->intento, 3000, 'parcial', []);

        $this->enviar($this->pasarela->webhookReembolso('evt_ref_1', $this->intento))->assertOk()->assertJsonPath('resultado', 'ignorado');

        $this->assertSame('recibido', $this->pedido->fresh()->estado);
        $this->assertNull($this->pedido->fresh()->reembolsado_en);
        $this->assertStringContainsString('30.00 de 100.00', RegistroAuditoria::where('accion', 'pago.reembolso_parcial')->sole()->detalle);
    }

    public function test_un_reembolso_de_un_cobro_que_no_es_de_ningun_pedido_se_ignora(): void
    {
        $this->pasarela->intentos['pi_ajeno'] = ['id' => 'pi_ajeno', 'client_secret' => 'x', 'estado' => 'succeeded', 'monto' => 5000, 'moneda' => 'mxn', 'metadatos' => [], 'cargo' => ['id' => 'ch_pi_ajeno', 'monto' => 5000, 'reembolsado' => 5000, 'creado' => time()]];

        $this->enviar($this->pasarela->webhookReembolso('evt_ref_1', 'pi_ajeno'))->assertOk()->assertJsonPath('resultado', 'ignorado');

        $this->assertSame('recibido', $this->pedido->fresh()->estado);
    }

    // ------------------------------------------------------------------ la base

    public function test_la_base_exige_que_el_reembolso_este_completo_sea_de_un_pedido_pagado_y_cancelado_y_no_pase_del_total(): void
    {
        $cambiar = fn (Pedido $pedido, array $campos) => fn () => DB::table('pedidos')->where('id', $pedido->id)->update($campos);
        $momento = '2026-11-09 12:00:00-06';

        $this->rechaza('pedidos_reembolso_completo', $cambiar($this->pedido, ['estado' => 'cancelado', 'reembolsado_en' => $momento])); // sin monto ni id
        $this->rechaza('pedidos_reembolso_solo_si_cancelado_y_pagado', $cambiar($this->pedido, ['reembolsado_en' => $momento, 'monto_reembolsado' => '100.00', 'stripe_refund_id' => 're_1'])); // sigue recibido
        $this->rechaza('pedidos_reembolso_no_excede_total', $cambiar($this->pedido, ['estado' => 'cancelado', 'reembolsado_en' => $momento, 'monto_reembolsado' => '100.01', 'stripe_refund_id' => 're_1']));
        $this->rechaza('pedidos_reembolso_positivo', $cambiar($this->pedido, ['estado' => 'cancelado', 'reembolsado_en' => $momento, 'monto_reembolsado' => '0.00', 'stripe_refund_id' => 're_1']));

        $porPagar = $this->pedidoPorPagarNuevo();
        $this->rechaza('pedidos_reembolso_solo_si_cancelado_y_pagado', $cambiar($porPagar, ['estado' => 'cancelado', 'reembolsado_en' => $momento, 'monto_reembolsado' => '100.00', 'stripe_refund_id' => 're_2'])); // nunca se pagó

        $this->assertSame(1, DB::table('pedidos')->where('id', $this->pedido->id)->update(['estado' => 'cancelado', 'reembolsado_en' => $momento, 'monto_reembolsado' => '100.00', 'stripe_refund_id' => 're_bien']), 'lo correcto sí pasa');
        $otro = $this->pedidoPagado2();
        $this->rechaza('pedidos_stripe_refund_unico', $cambiar($otro, ['estado' => 'cancelado', 'reembolsado_en' => $momento, 'monto_reembolsado' => '100.00', 'stripe_refund_id' => 're_bien']));
    }

    public function test_un_reembolso_no_cambia_el_evento_ni_los_resultados_de_stripe_fuera_de_lo_permitido(): void
    {
        $this->rechaza('stripe_eventos_resultado_valido', fn () => StripeEvento::create(['stripe_event_id' => 'evt_x', 'tipo' => 'x', 'resultado' => 'reembolsado_a_medias']));
        $this->assertGreaterThan(0, StripeEvento::create(['stripe_event_id' => 'evt_y', 'tipo' => 'charge.refunded', 'resultado' => 'reembolsado'])->id);
    }

    // ------------------------------------------------------------------ apoyo

    private function pedidoPorPagarNuevo(): Pedido
    {
        $direccion = Address::where('user_id', $this->cliente->id)->first();
        $menu = $this->platillo(null, 'comida', ['price' => 100]);
        $this->disponible($menu, '2026-11-16');

        return app(ContratacionDePaquetes::class)->pedir($this->cliente, Paquete::first(), $direccion, [$menu]);
    }

    /** Otro pedido de $100 pagado con Stripe (con su propio intento). */
    private function pedidoPagado2(): Pedido
    {
        $pedido = $this->pedidoPorPagarNuevo();
        $intento = app(PagosDePedidos::class)->iniciar($this->cliente, $pedido->id)['payment_intent_id'];
        $this->pasarela->cobrar($intento, '2026-11-14T11:00:00-06:00');
        $this->enviar($this->pasarela->webhook('evt_pago_'.$pedido->id, 'payment_intent.succeeded', $intento, 10000, '2026-11-14T11:00:00-06:00'))->assertJsonPath('resultado', 'pagado');

        return $pedido->fresh();
    }
}
