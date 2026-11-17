<?php

namespace Tests\Feature\Negocio;

use App\Models\Address;
use App\Models\DisponibilidadDia;
use App\Models\Paquete;
use App\Models\Pedido;
use App\Models\RegistroAuditoria;
use App\Models\StripeEvento;
use App\Models\User;
use App\Services\ContratacionDePaquetes;
use App\Services\Pagos\PasarelaDePagos;
use App\Services\PagosDePedidos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\Support\PasarelaFalsa;
use Tests\TestCase;

/**
 * Pago con tarjeta (Stripe en modo de prueba) de pedidos individuales. Ninguna prueba llama a la red: la pasarela es PasarelaFalsa.
 * «Hoy» es el lunes 9 de nov de 2026 a las 10:00; el pedido es para el martes 10, así que vence el lunes 9 a las 18:00.
 */
class PagosConStripeTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private PasarelaFalsa $pasarela;

    private User $cliente;

    private Pedido $pedido;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(PasarelaDePagos::class, $this->pasarela = new PasarelaFalsa);
        $this->ahoraEs('2026-11-09 10:00');
        $this->zonaDePruebas();
        $this->cliente = User::findOrFail($this->usuario());
        $this->pedido = $this->pedidoPorPagar();
    }

    private function pedidoPorPagar(float $precio = 100): Pedido
    {
        $direccion = Address::create(['user_id' => $this->cliente->id, 'street_address' => 'Av. Vallarta 100', 'latitude' => 20.67, 'longitude' => -103.35]);
        $menu = $this->platillo(null, 'comida', ['price' => $precio]);
        $this->disponible($menu, '2026-11-10');
        $paquete = Paquete::firstOrCreate(['nombre' => 'Un día'], ['dias' => 1, 'descuento_porcentaje' => 0]);

        return app(ContratacionDePaquetes::class)->pedir($this->cliente, $paquete, $direccion, [$menu]);
    }

    /** El cliente inicia el pago y devuelve el id del PaymentIntent. */
    private function iniciar(?Pedido $pedido = null): string
    {
        return app(PagosDePedidos::class)->iniciar($this->cliente, ($pedido ?? $this->pedido)->id)['payment_intent_id'];
    }

    /** Manda al webhook un evento firmado (o con la firma que se diga). */
    private function webhook(string $evento, string $tipo, string $intento, int $monto, ?string $cargoEn = null, ?string $firma = PasarelaFalsa::FIRMA, ?string $ultimos4 = '4242', string $moneda = 'mxn'): TestResponse
    {
        [$cuerpo] = $this->pasarela->webhook($evento, $tipo, $intento, $monto, $cargoEn, $ultimos4, $moneda);
        $servidor = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'] + ($firma ? ['HTTP_STRIPE_SIGNATURE' => $firma] : []);

        return $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], $servidor, $cuerpo);
    }

    private function bitacora(string $accion)
    {
        return RegistroAuditoria::where('accion', $accion)->get();
    }

    public function test_el_pago_confirmado_pasa_el_pedido_a_recibido_con_su_fecha_de_pago_y_los_ultimos_4(): void
    {
        $intento = $this->iniciar();

        $this->webhook('evt_1', 'payment_intent.succeeded', $intento, 10000, '2026-11-09T11:30:00-06:00')
            ->assertOk()->assertJsonPath('resultado', 'pagado');

        $pedido = $this->pedido->fresh();
        $this->assertSame('recibido', $pedido->estado);
        $this->assertSame(['tarjeta', '4242'], [$pedido->metodo_pago, $pedido->ultimos_digitos]);
        $this->assertSame('2026-11-09 11:30', $pedido->pagado_en->setTimezone('America/Mexico_City')->format('Y-m-d H:i'), 'la hora del cargo');
        $this->assertSame([['evt_1', 'pagado', $pedido->id]], StripeEvento::all()->map(fn ($e) => [$e->stripe_event_id, $e->resultado, $e->pedido_id])->all());
        $this->assertCount(1, $this->bitacora('pedido.pagado'));
    }

    public function test_un_webhook_repetido_no_hace_nada_la_segunda_vez(): void
    {
        $intento = $this->iniciar();
        $this->webhook('evt_1', 'payment_intent.succeeded', $intento, 10000, '2026-11-09T11:30:00-06:00')->assertJsonPath('resultado', 'pagado');
        $pagadoEn = $this->pedido->fresh()->pagado_en;

        $this->webhook('evt_1', 'payment_intent.succeeded', $intento, 10000, '2026-11-09T15:00:00-06:00')->assertOk()->assertJsonPath('resultado', 'repetido');

        $this->assertEquals($pagadoEn, $this->pedido->fresh()->pagado_en, 'no se vuelve a escribir la fecha de pago');
        $this->assertSame(1, StripeEvento::count());
        $this->assertCount(1, $this->bitacora('pedido.pagado'), 'ni se vuelve a anotar en la bitácora');
    }

    public function test_una_firma_invalida_o_ausente_se_rechaza_y_no_toca_nada(): void
    {
        $intento = $this->iniciar();

        $this->webhook('evt_1', 'payment_intent.succeeded', $intento, 10000, null, 'firma-falsa')->assertStatus(400)->assertJsonPath('codigo', 'firma_invalida');
        $this->webhook('evt_1', 'payment_intent.succeeded', $intento, 10000, null, null)->assertStatus(400)->assertJsonPath('codigo', 'firma_invalida');

        $this->assertSame('pendiente_pago', $this->pedido->fresh()->estado);
        $this->assertSame(0, StripeEvento::count());
    }

    public function test_un_pago_fallido_no_cambia_el_pedido_y_el_cliente_puede_reintentar(): void
    {
        $intento = $this->iniciar();

        $this->webhook('evt_1', 'payment_intent.payment_failed', $intento, 10000)->assertOk()->assertJsonPath('resultado', 'fallo_de_pago');

        $pedido = $this->pedido->fresh();
        $this->assertSame(['pendiente_pago', null, null], [$pedido->estado, $pedido->pagado_en, $pedido->ultimos_digitos]);
        $this->assertSame($intento, $this->iniciar(), 'mismo intento para reintentar');
        $this->webhook('evt_2', 'payment_intent.succeeded', $intento, 10000, '2026-11-09T12:00:00-06:00')->assertJsonPath('resultado', 'pagado');
    }

    public function test_un_cargo_hecho_despues_del_plazo_se_reembolsa_completo_y_el_pedido_no_cambia(): void
    {
        $intento = $this->iniciar();

        $this->webhook('evt_1', 'payment_intent.succeeded', $intento, 10000, '2026-11-09T18:00:01-06:00')->assertOk()->assertJsonPath('resultado', 'reembolso_automatico');

        $this->assertSame('pendiente_pago', $this->pedido->fresh()->estado, 'el pedido no cambia de estado');
        $this->assertNull($this->pedido->fresh()->reembolsado_en);
        $reembolso = collect($this->pasarela->llamadas)->firstWhere(0, 'crearReembolso');
        $this->assertSame([$intento, 10000, 'easylunch-reembolso-auto-'.$intento], [$reembolso[1], $reembolso[2], $reembolso[3]], 'reembolso total, con llave de idempotencia');
        $this->assertSame('reembolso_automatico', StripeEvento::sole()->resultado);
        $aviso = $this->bitacora('pago.reembolsado_automatico')->sole();
        $this->assertStringContainsString($intento, $aviso->detalle);
        $this->assertStringContainsString('re_prueba_1', $aviso->detalle);
        $this->assertNull($aviso->usuario_id, 'lo registra el sistema');
        $this->assertCount(0, $this->bitacora('pago.reembolso_manual'));

        $this->ahoraEs('2026-11-09 18:30');
        app(ContratacionDePaquetes::class)->cancelarVencidos();
        $this->assertSame('cancelado', $this->pedido->fresh()->estado, 'el corte sigue cancelando los no pagados');
    }

    public function test_se_compara_contra_la_hora_del_cargo_no_contra_la_del_webhook(): void
    {
        $intento = $this->iniciar();

        $this->ahoraEs('2026-11-09 18:05'); // el webhook llega cinco minutos después del plazo…
        $this->webhook('evt_1', 'payment_intent.succeeded', $intento, 10000, '2026-11-09T17:59:59-06:00')->assertJsonPath('resultado', 'pagado'); // …pero el cargo fue antes

        $this->assertSame('recibido', $this->pedido->fresh()->estado);
        $this->assertCount(0, $this->bitacora('pago.reembolso_manual'));
    }

    public function test_un_cobro_de_un_pedido_que_el_corte_ya_cancelo_no_lo_revive_y_se_reembolsa(): void
    {
        $intento = $this->iniciar();
        $this->ahoraEs('2026-11-09 18:30');
        app(ContratacionDePaquetes::class)->cancelarVencidos();

        $this->webhook('evt_1', 'payment_intent.succeeded', $intento, 10000, '2026-11-09T17:00:00-06:00')->assertJsonPath('resultado', 'reembolso_automatico');

        $this->assertSame('cancelado', $this->pedido->fresh()->estado);
        $this->assertNull($this->pedido->fresh()->reembolsado_en, 'el pedido nunca se pagó: no lleva datos de reembolso');
        $this->assertSame(0, (int) DisponibilidadDia::sum('porciones_tomadas'));
        $this->assertCount(1, $this->bitacora('pago.reembolsado_automatico'));
        $this->assertCount(1, $this->pasarela->reembolsos);
    }

    public function test_el_reembolso_automatico_no_se_repite_si_el_webhook_llega_otra_vez(): void
    {
        $intento = $this->iniciar();
        $this->webhook('evt_1', 'payment_intent.succeeded', $intento, 10000, '2026-11-09T18:00:01-06:00')->assertJsonPath('resultado', 'reembolso_automatico');

        $this->webhook('evt_1', 'payment_intent.succeeded', $intento, 10000, '2026-11-09T18:00:01-06:00')->assertJsonPath('resultado', 'repetido');

        $this->assertCount(1, $this->pasarela->reembolsos);
        $this->assertCount(1, $this->bitacora('pago.reembolsado_automatico'));
    }

    public function test_si_el_reembolso_automatico_falla_queda_el_aviso_para_hacerlo_a_mano_y_el_pedido_no_cambia(): void
    {
        $intento = $this->iniciar();
        $this->pasarela->fallaAlReembolsar = 'Stripe no responde';

        $this->webhook('evt_1', 'payment_intent.succeeded', $intento, 10000, '2026-11-09T18:00:01-06:00')->assertOk()->assertJsonPath('resultado', 'reembolso_fallido');

        $this->assertSame('pendiente_pago', $this->pedido->fresh()->estado);
        $this->assertSame('reembolso_fallido', StripeEvento::sole()->resultado);
        $aviso = $this->bitacora('pago.reembolso_manual')->sole();
        $this->assertStringContainsString('falló (Stripe no responde)', $aviso->detalle);
        $this->assertStringContainsString('reembolsarlo manualmente', $aviso->detalle);
        $this->assertStringContainsString($intento, $aviso->detalle);
        $this->assertCount(0, $this->bitacora('pago.reembolsado_automatico'));
    }

    public function test_el_aviso_de_reembolso_de_un_cobro_no_aplicado_no_cancela_nada(): void
    {
        $intento = $this->iniciar();
        $this->webhook('evt_1', 'payment_intent.succeeded', $intento, 10000, '2026-11-09T18:00:01-06:00');
        $this->pasarela->intentos[$intento]['cargo'] = ['id' => 'ch_'.$intento, 'monto' => 10000, 'reembolsado' => 10000, 'creado' => time()];

        [$cuerpo] = $this->pasarela->webhookReembolso('evt_2', $intento);
        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => PasarelaFalsa::FIRMA], $cuerpo)
            ->assertOk()->assertJsonPath('resultado', 'ignorado');

        $this->assertSame('pendiente_pago', $this->pedido->fresh()->estado);
    }

    public function test_un_segundo_cobro_de_un_pedido_ya_pagado_se_avisa_para_reembolso(): void
    {
        $primero = $this->iniciar();
        $this->webhook('evt_1', 'payment_intent.succeeded', $primero, 10000, '2026-11-09T11:00:00-06:00');
        $this->pedido->update(['stripe_payment_intent_id' => 'pi_otro']); // otro intento del mismo pedido que también se cobró

        $this->webhook('evt_2', 'payment_intent.succeeded', 'pi_otro', 10000, '2026-11-09T11:05:00-06:00')->assertJsonPath('resultado', 'pedido_no_pagable');

        $this->assertSame('recibido', $this->pedido->fresh()->estado);
        $this->assertStringContainsString('ya estaba recibido', $this->bitacora('pago.reembolso_manual')->sole()->detalle);
    }

    public function test_si_el_monto_o_la_moneda_cobrados_no_son_los_del_pedido_no_se_da_por_pagado(): void
    {
        $intento = $this->iniciar();

        $this->webhook('evt_1', 'payment_intent.succeeded', $intento, 5000, '2026-11-09T11:00:00-06:00')->assertJsonPath('resultado', 'monto_distinto');
        $this->webhook('evt_2', 'payment_intent.succeeded', $intento, 10000, '2026-11-09T11:00:00-06:00', PasarelaFalsa::FIRMA, '4242', 'usd')->assertJsonPath('resultado', 'monto_distinto');

        $this->assertSame('pendiente_pago', $this->pedido->fresh()->estado);
        $this->assertCount(2, $this->bitacora('pago.reembolso_manual'));
    }

    public function test_los_eventos_de_otros_pagos_o_de_otro_tipo_se_ignoran_con_200(): void
    {
        $this->webhook('evt_1', 'payment_intent.succeeded', 'pi_de_otro_sistema', 10000)->assertOk()->assertJsonPath('resultado', 'ignorado');
        $this->webhook('evt_2', 'charge.refunded', 'pi_de_otro_sistema', 10000)->assertOk()->assertJsonPath('resultado', 'ignorado');

        $this->assertSame('pendiente_pago', $this->pedido->fresh()->estado);
        $this->assertSame(0, RegistroAuditoria::count());
    }

    public function test_solo_se_inicia_el_pago_de_un_pedido_propio_por_pagar_y_sin_vencer(): void
    {
        $otro = User::findOrFail($this->usuario());
        $this->assertSame(404, $this->codigoDe(fn () => app(PagosDePedidos::class)->iniciar($otro, $this->pedido->id)), 'ajeno');

        $this->ahoraEs('2026-11-09 18:00');
        $this->assertSame('pedido_vencido', $this->codigoDe(fn () => app(PagosDePedidos::class)->iniciar($this->cliente, $this->pedido->id)));

        $this->ahoraEs('2026-11-09 10:00');
        $pagado = $this->pedidoPorPagar();
        app(ContratacionDePaquetes::class)->pagar($pagado, 'tarjeta', '4242');
        $this->assertSame('pedido_no_pagable', $this->codigoDe(fn () => app(PagosDePedidos::class)->iniciar($this->cliente, $pagado->id)));
    }

    public function test_si_el_intento_ya_no_sirve_se_crea_uno_nuevo(): void
    {
        $primero = $this->iniciar();
        $this->pasarela->intentos[$primero]['estado'] = 'canceled';

        $segundo = $this->iniciar();

        $this->assertNotSame($primero, $segundo);
        $this->assertSame($segundo, $this->pedido->fresh()->stripe_payment_intent_id);
    }

    public function test_la_base_garantiza_un_evento_una_vez_y_un_intento_por_pedido(): void
    {
        StripeEvento::create(['stripe_event_id' => 'evt_1', 'tipo' => 'payment_intent.succeeded', 'resultado' => 'pagado']);
        $this->rechaza('stripe_eventos_stripe_event_id_unique', fn () => StripeEvento::create(['stripe_event_id' => 'evt_1', 'tipo' => 'x', 'resultado' => 'ignorado']));
        $this->rechaza('stripe_eventos_resultado_valido', fn () => StripeEvento::create(['stripe_event_id' => 'evt_2', 'tipo' => 'x', 'resultado' => 'inventado']));

        $this->pedido->update(['stripe_payment_intent_id' => 'pi_1']);
        $otro = $this->pedidoPorPagar();
        $this->rechaza('pedidos_stripe_payment_intent_unico', fn () => $otro->update(['stripe_payment_intent_id' => 'pi_1']));
    }

    private function codigoDe(callable $accion): int|string
    {
        try {
            $accion();
        } catch (\App\Exceptions\ReglaDeNegocioException $e) {
            return $e->codigo;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return 404;
        }

        return 'sin error';
    }
}
