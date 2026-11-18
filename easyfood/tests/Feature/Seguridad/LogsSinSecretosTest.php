<?php

namespace Tests\Feature\Seguridad;

use App\Logging\OcultarSecretos;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** Los logs no escriben tokens, contraseñas, enlaces con token ni claves de Stripe. */
class LogsSinSecretosTest extends TestCase
{
    private string $archivo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archivo = storage_path('logs/prueba-sin-secretos.log');
        @unlink($this->archivo);
        config(['logging.channels.prueba' => [
            'driver' => 'single', 'path' => $this->archivo, 'level' => 'debug', 'replace_placeholders' => true, 'tap' => [OcultarSecretos::class],
        ]]);
    }

    protected function tearDown(): void
    {
        @unlink($this->archivo);
        parent::tearDown();
    }

    private function registrado(): string
    {
        return (string) file_get_contents($this->archivo);
    }

    public function test_tacha_las_claves_de_stripe_los_tokens_y_las_contrasenas_del_mensaje_y_del_contexto(): void
    {
        $secretos = ['sk_live_51AbCdEfGhIjKlMnOp', 'rk_test_abcdefgh12345678', 'whsec_FalsoFalsoFalsoFalso', '12|abcdefghijklmnopqrstuvwxyz0123456789ABCD', 'Hunter2Secreta', 'TokenDeInvitacion1234567890abcdefghijkl'];

        Log::channel('prueba')->error("Falló con sk_live_51AbCdEfGhIjKlMnOp y whsec_FalsoFalsoFalsoFalso; Authorization: Bearer 12|abcdefghijklmnopqrstuvwxyz0123456789ABCD", [
            'clave' => 'rk_test_abcdefgh12345678', 'password' => 'Hunter2Secreta', 'url' => 'https://front.test/empresas/aceptar-invitacion?token=TokenDeInvitacion1234567890abcdefghijkl',
        ]);

        $log = $this->registrado();
        $this->assertStringContainsString('Falló con', $log, 'el mensaje sigue siendo útil');
        foreach ($secretos as $secreto) {
            $this->assertStringNotContainsString($secreto, $log, "el log no debe contener «{$secreto}»");
        }
    }

    public function test_tacha_los_argumentos_de_una_traza_y_el_token_de_la_ruta(): void
    {
        $this->app['config']->set('app.debug', true);
        try {
            (function (string $password, string $token) {
                throw new \RuntimeException("password={$password} en /api/v1/cuentas/invitaciones/AbCdEfGhIjKlMnOpQrStUvWx12 con token: {$token}");
            })('ClaveMuySecreta99', 'abc123def456ghi789');
        } catch (\Throwable $e) {
            Log::channel('prueba')->error($e->getMessage(), ['exception' => $e]);
        }

        $log = $this->registrado();
        foreach (['ClaveMuySecreta99', 'AbCdEfGhIjKlMnOpQrStUvWx12', 'abc123def456ghi789'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $log);
        }
        $this->assertStringContainsString('[oculto]', $log);
    }

    public function test_no_toca_el_texto_normal(): void
    {
        $this->assertSame('El corte del 2026-11-12 generó 3 rutas para Nexo (token de la vista: ninguno).', OcultarSecretos::limpiar('El corte del 2026-11-12 generó 3 rutas para Nexo (token de la vista: ninguno).'));
        $this->assertSame('Pedido T-100 por $130.00', OcultarSecretos::limpiar('Pedido T-100 por $130.00'));
    }

    public function test_todos_los_canales_de_produccion_tienen_el_filtro_y_solo_enlaces_queda_fuera(): void
    {
        $canales = config('logging.channels');
        foreach (['single', 'daily', 'monthly', 'stderr', 'slack', 'errorlog', 'syslog', 'papertrail'] as $nombre) {
            $this->assertContains(OcultarSecretos::class, $canales[$nombre]['tap'] ?? [], "el canal {$nombre} debe ocultar secretos");
        }
        $this->assertArrayNotHasKey('tap', $canales['enlaces'], 'enlaces existe para ver los enlaces en desarrollo');
    }
}
