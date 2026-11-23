<?php

namespace Tests\Feature\Comandos;

use App\Models\Pedido;
use App\Models\User;
use App\Services\Pagos\PasarelaDePagos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\Support\PasarelaFalsa;
use Tests\TestCase;

/** php artisan pagos:preparar-prueba: solo local; deja un cliente con un pedido por pagar y arma el curl y el comando de Stripe CLI. */
class PrepararPagoDePruebaTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private PasarelaFalsa $pasarela;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(PasarelaDePagos::class, $this->pasarela = new PasarelaFalsa);
        $this->ahoraEs('2026-11-09 10:00');
    }

    private function conLoNecesario(): void
    {
        DB::table('roles')->insertOrIgnore(['id' => 1, 'name' => 'customer', 'display_name' => 'Cliente']);
        $this->zonaDePruebas();
        $menu = $this->platillo(null, 'comida', ['price' => 100]);
        $this->disponible($menu, '2026-11-16');
        \App\Models\Paquete::create(['nombre' => 'Un día', 'dias' => 1, 'descuento_porcentaje' => 0]);
    }

    public function test_crea_un_cliente_con_un_pedido_por_pagar_y_arma_el_curl_con_su_id_y_su_token(): void
    {
        $this->conLoNecesario();

        $this->artisan('pagos:preparar-prueba')->assertSuccessful();

        $pedido = Pedido::sole();
        $cliente = User::findOrFail($pedido->cliente_id);
        $this->assertSame('pendiente_pago', $pedido->estado);
        $this->assertSame('100.00', $pedido->total);
        $this->assertStringEndsWith('@easylunch.test', $cliente->email);
        $this->assertSame(1, PersonalAccessToken::where('tokenable_id', $cliente->id)->count(), 'un token del cliente');
    }

    public function test_imprime_el_id_el_token_el_curl_y_el_comando_de_stripe_listos_para_pegar(): void
    {
        $this->conLoNecesario();

        $salida = $this->salidaDe(['--url' => 'http://localhost:8000']);

        $pedido = Pedido::sole();
        $this->assertStringContainsString("Pedido: {$pedido->id}", $salida);
        preg_match('/Token: (\S+)/', $salida, $m);
        $this->assertNotEmpty($m[1] ?? null, 'imprime el token');
        $this->assertStringContainsString("curl -s -X POST http://localhost:8000/api/v1/cliente/pedidos/{$pedido->id}/pago -H \"Authorization: Bearer {$m[1]}\" -H \"Accept: application/json\"", $salida);
        $this->assertStringContainsString('stripe payment_intents confirm', $salida);
        $this->assertStringContainsString('--payment-method pm_card_visa', $salida);
        $this->assertStringContainsString('jq -r .payment_intent_id', $salida, 'trae también los dos pasos en uno');
    }

    public function test_el_token_impreso_sirve_para_pedir_el_intento_de_pago(): void
    {
        $this->conLoNecesario();
        $salida = $this->salidaDe();
        preg_match('/Token: (\S+)/', $salida, $m);
        $pedido = Pedido::sole();

        $this->withToken($m[1])->postJson("/api/v1/cliente/pedidos/{$pedido->id}/pago")->assertOk()->assertJsonPath('monto', '100.00');
    }

    public function test_con_intento_crea_el_payment_intent_e_imprime_el_comando_de_confirmacion_con_su_id(): void
    {
        $this->conLoNecesario();

        $salida = $this->salidaDe(['--con-intento' => true]);

        $this->assertStringContainsString('stripe payment_intents confirm pi_prueba_1 --payment-method pm_card_visa --return-url https://example.com', $salida);
        $this->assertSame('pi_prueba_1', Pedido::sole()->stripe_payment_intent_id);
    }

    public function test_con_intento_sin_claves_avisa_y_sigue_con_el_curl(): void
    {
        $this->conLoNecesario();
        $this->pasarela->configurada = false;

        $salida = $this->salidaDe(['--con-intento' => true]);

        $this->assertStringContainsString('No se creó el intento en Stripe', $salida);
        $this->assertStringContainsString('curl -s -X POST', $salida);
        $this->assertNull(Pedido::sole()->stripe_payment_intent_id);
    }

    public function test_no_se_ejecuta_fuera_del_entorno_local(): void
    {
        $this->conLoNecesario();
        $this->app['env'] = 'production';

        $this->artisan('pagos:preparar-prueba')->expectsOutputToContain('solo para desarrollo local')->assertFailed();

        $this->assertSame(0, Pedido::count());
        $this->assertSame(0, User::count());
    }

    public function test_si_falta_algo_dice_que_cargar_y_no_deja_datos_a_medias(): void
    {
        DB::table('roles')->insertOrIgnore(['id' => 1, 'name' => 'customer', 'display_name' => 'Cliente']);

        $this->artisan('pagos:preparar-prueba')->expectsOutputToContain('No hay un paquete de 1 día')->assertFailed();

        $this->assertSame(0, User::count());
    }

    /** @param  array<string, mixed>  $opciones */
    private function salidaDe(array $opciones = []): string
    {
        $codigo = \Illuminate\Support\Facades\Artisan::call('pagos:preparar-prueba', $opciones);
        $this->assertSame(0, $codigo);

        return \Illuminate\Support\Facades\Artisan::output();
    }
}
