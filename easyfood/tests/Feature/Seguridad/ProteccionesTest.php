<?php

namespace Tests\Feature\Seguridad;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** Protecciones para salir a producción: intentos limitados, errores sin detalles, CORS, tokens que vencen y correos que no se pierden. */
class ProteccionesTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    // ------------------------------------------------------------------ límites de intentos

    public function test_el_login_se_limita_por_cuenta_y_por_ip_con_un_mensaje_en_espanol(): void
    {
        $intento = fn (string $correo) => $this->postJson('/api/auth/login', ['email' => $correo, 'password' => 'incorrecta']);

        foreach (range(1, 5) as $n) {
            $this->assertNotSame(429, $intento('victima@ejemplo.mx')->status(), "el intento {$n} todavía cuenta");
        }
        $sexto = $intento('victima@ejemplo.mx')->assertStatus(429);

        $sexto->assertJsonPath('codigo', 'demasiados_intentos');
        $this->assertStringContainsString('Demasiados intentos', $sexto->json('message'));
        $this->assertNotNull($sexto->headers->get('Retry-After'));
        $this->assertNotSame(429, $intento('otra@ejemplo.mx')->status(), 'otra cuenta desde la misma IP no se bloquea por la primera');
    }

    public function test_el_correo_se_cuenta_sin_importar_mayusculas(): void
    {
        foreach (range(1, 5) as $n) {
            $this->postJson('/api/auth/login', ['email' => $n % 2 ? 'Victima@Ejemplo.mx' : 'victima@ejemplo.mx', 'password' => 'x']);
        }

        $this->postJson('/api/auth/login', ['email' => 'VICTIMA@ejemplo.mx', 'password' => 'x'])->assertStatus(429);
    }

    public function test_pedir_restablecer_la_contrasena_se_limita_por_correo_y_responde_igual_exista_o_no(): void
    {
        $this->usuarioDeRol('customer', ['email' => 'existe@ejemplo.mx']);
        $pedir = fn (string $correo) => $this->postJson('/api/v1/cuentas/restablecer/solicitar', ['correo' => $correo]);

        $this->assertSame($pedir('existe@ejemplo.mx')->json(), $pedir('no-existe@ejemplo.mx')->json(), 'no revela si el correo existe');
        $pedir('existe@ejemplo.mx');
        $pedir('existe@ejemplo.mx');
        $pedir('existe@ejemplo.mx')->assertStatus(429)->assertJsonPath('codigo', 'demasiados_intentos');
        $this->assertSame(200, $pedir('otro@ejemplo.mx')->status(), 'otro correo sigue pudiendo pedirlo');
    }

    public function test_crear_cuentas_se_limita_por_ip(): void
    {
        foreach (range(1, 10) as $n) {
            $this->assertNotSame(429, $this->postJson('/api/auth/register', [])->status());
        }

        $this->postJson('/api/auth/register', [])->assertStatus(429);
    }

    // ------------------------------------------------------------------ errores sin detalles

    public function test_con_debug_apagado_un_error_interno_no_enseña_trazas_ni_rutas_ni_claves(): void
    {
        config(['app.debug' => false]);
        Route::middleware('api')->get('api/v1/prueba-explota', fn () => throw new \RuntimeException('detalle interno con sk_test_abcdef123456 en /var/www/html/app'));
        Route::middleware('api')->get('api/prueba-explota', fn () => throw new \RuntimeException('detalle interno con sk_test_abcdef123456 en /var/www/html/app'));

        foreach (['/api/v1/prueba-explota', '/api/prueba-explota'] as $ruta) {
            $r = $this->getJson($ruta)->assertStatus(500);
            $cuerpo = $r->getContent();
            foreach (['detalle interno', 'sk_test_', '/var/www', 'trace', 'exception', '"file"', 'RuntimeException'] as $pista) {
                $this->assertStringNotContainsString($pista, $cuerpo, "{$ruta} no debe filtrar «{$pista}»");
            }
        }
    }

    // ------------------------------------------------------------------ CORS

    public function test_cors_acepta_el_origen_configurado_y_no_el_de_otro_sitio(): void
    {
        config(['cors.allowed_origins' => ['https://mi-front.vercel.app'], 'cors.allowed_origins_patterns' => ['#^https://mi-front-[a-z0-9-]+\.vercel\.app$#']]);
        $preflight = fn (string $origen) => $this->call('OPTIONS', '/api/v1/salud', [], [], [], ['HTTP_ORIGIN' => $origen, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET'])->headers->get('Access-Control-Allow-Origin');

        $this->assertSame('https://mi-front.vercel.app', $preflight('https://mi-front.vercel.app'));
        $this->assertSame('https://mi-front-git-rama-equipo.vercel.app', $preflight('https://mi-front-git-rama-equipo.vercel.app'), 'las vistas previas de Vercel entran por patrón');
        $this->assertNotSame('https://malo.example', $preflight('https://malo.example'));
        $this->assertNotSame('*', $preflight('https://malo.example'));
    }

    public function test_cors_sin_configuracion_nunca_abre_todo_con_asterisco(): void
    {
        $this->assertNotContains('*', config('cors.allowed_origins'));
    }

    // ------------------------------------------------------------------ tokens que vencen

    public function test_un_token_deja_de_servir_cuando_vence_segun_sanctum_expiration(): void
    {
        config(['sanctum.expiration' => 60]);
        $usuario = $this->usuarioDeRol('customer');
        $token = $usuario->createToken('prueba')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/auth/profile')->assertOk();

        $this->travel(61)->minutes();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/auth/profile')->assertStatus(401);
    }

    // ------------------------------------------------------------------ correos en cola, cifrados

    public function test_el_correo_de_invitacion_va_a_la_cola_cifrado_y_la_peticion_no_depende_del_servidor_de_correo(): void
    {
        config(['queue.default' => 'database']);
        $this->comoRol('admin');
        $empresa = $this->empresaConContrato();

        $this->postJson('/api/v1/cuentas/invitaciones', ['organizacion_tipo' => 'empresa', 'organizacion_id' => $empresa->id, 'nombre' => 'Persona Nueva', 'correo' => 'nueva.persona@ejemplo.mx', 'rol' => 'empresa'])->assertCreated();

        $this->assertSame(1, DB::table('jobs')->count(), 'el correo quedó en la cola');
        $payload = (string) DB::table('jobs')->value('payload');
        foreach (['nueva.persona@ejemplo.mx', 'token=', 'aceptar-invitacion', 'Persona Nueva'] as $pista) {
            $this->assertStringNotContainsString($pista, $payload, "la cola guarda el correo cifrado: no debe verse «{$pista}»");
        }
        $this->assertNotNull(User::where('email', 'nueva.persona@ejemplo.mx')->first());
    }
}
