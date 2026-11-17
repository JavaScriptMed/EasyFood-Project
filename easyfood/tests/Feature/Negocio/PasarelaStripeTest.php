<?php

namespace Tests\Feature\Negocio;

use App\Services\Pagos\FirmaDeWebhookInvalida;
use App\Services\Pagos\PasarelaDePagos;
use App\Services\Pagos\PasarelaStripe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El adaptador real de Stripe, sin llamar a la red: aquí solo se prueba lo que no la necesita, que es lo más delicado
 * (verificar la firma del webhook y leer el evento). La firma se calcula igual que Stripe: HMAC-SHA256 de «tiempo.cuerpo».
 */
class PasarelaStripeTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'whsec_prueba_no_es_una_clave_real';

    private function firmar(string $cuerpo, ?int $momento = null, string $secreto = self::SECRETO): string
    {
        $momento ??= time();

        return "t={$momento},v1=".hash_hmac('sha256', "{$momento}.{$cuerpo}", $secreto);
    }

    private function cuerpo(string $evento = 'evt_real_1', string $intento = 'pi_real_1'): string
    {
        return json_encode([
            'id' => $evento, 'object' => 'event', 'api_version' => '2024-06-20', 'created' => 1_700_000_000, 'type' => 'payment_intent.succeeded',
            'data' => ['object' => ['id' => $intento, 'object' => 'payment_intent', 'amount' => 12345, 'currency' => 'mxn', 'status' => 'succeeded', 'latest_charge' => 'ch_real_1', 'metadata' => ['pedido_id' => '7']]],
        ], JSON_THROW_ON_ERROR);
    }

    private function pasarela(): PasarelaStripe
    {
        return new PasarelaStripe('sk_test_no_es_una_clave_real', self::SECRETO);
    }

    public function test_con_la_firma_correcta_lee_el_evento_de_cobro(): void
    {
        $cuerpo = $this->cuerpo();

        $evento = $this->pasarela()->evento($cuerpo, $this->firmar($cuerpo));

        $this->assertSame(['evt_real_1', 'payment_intent.succeeded', 1_700_000_000], [$evento['id'], $evento['tipo'], $evento['creado']]);
        $this->assertSame(['id' => 'pi_real_1', 'monto' => 12345, 'moneda' => 'mxn', 'estado' => 'succeeded', 'metadatos' => ['pedido_id' => '7'], 'cargo_id' => 'ch_real_1'], $evento['intento']);
    }

    public function test_rechaza_una_firma_que_no_corresponde_un_cuerpo_alterado_una_firma_vieja_o_la_ausencia_de_firma(): void
    {
        $cuerpo = $this->cuerpo();
        $casos = [
            'secreto distinto' => [$cuerpo, $this->firmar($cuerpo, null, 'whsec_otro')],
            'cuerpo alterado' => [str_replace('12345', '1', $cuerpo), $this->firmar($cuerpo)],
            'firma de hace una hora (repetición)' => [$cuerpo, $this->firmar($cuerpo, time() - 3600)],
            'sin firma' => [$cuerpo, null],
            'firma que no es de Stripe' => [$cuerpo, 'cualquier-cosa'],
        ];

        foreach ($casos as $nombre => [$cuerpoEnviado, $firma]) {
            try {
                $this->pasarela()->evento($cuerpoEnviado, $firma);
                $this->fail("Debía rechazar: {$nombre}.");
            } catch (FirmaDeWebhookInvalida) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_un_evento_que_no_es_de_un_pago_trae_el_intento_vacio(): void
    {
        $cuerpo = json_encode(['id' => 'evt_2', 'object' => 'event', 'created' => 1_700_000_000, 'type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_1', 'object' => 'customer']]]);

        $this->assertNull($this->pasarela()->evento($cuerpo, $this->firmar($cuerpo))['intento']);
    }

    public function test_sin_claves_no_esta_configurada(): void
    {
        $this->assertFalse((new PasarelaStripe(null, null))->configurada());
        $this->assertFalse((new PasarelaStripe('sk_test_x', ''))->configurada());
        $this->assertTrue($this->pasarela()->configurada());
    }

    public function test_el_webhook_de_punta_a_punta_con_el_adaptador_real_verifica_la_firma(): void
    {
        config(['services.stripe.secret' => 'sk_test_no_es_una_clave_real', 'services.stripe.webhook_secret' => self::SECRETO]);
        $this->app->forgetInstance(PasarelaDePagos::class);
        $cuerpo = $this->cuerpo('evt_real_9', 'pi_de_nadie');
        $servidor = fn (string $firma) => ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $firma];

        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], $servidor('t=1,v1=falsa'), $cuerpo)->assertStatus(400)->assertJsonPath('codigo', 'firma_invalida');
        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], $servidor($this->firmar($cuerpo)), $cuerpo)->assertOk()->assertJsonPath('resultado', 'ignorado');
        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], $servidor($this->firmar($cuerpo)), $cuerpo)->assertOk()->assertJsonPath('resultado', 'repetido');
    }
}
