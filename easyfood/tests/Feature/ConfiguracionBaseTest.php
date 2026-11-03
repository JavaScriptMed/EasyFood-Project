<?php

namespace Tests\Feature;

use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Lo que se configuró al montar el proyecto: zona horaria, idioma, CORS y Sanctum. */
class ConfiguracionBaseTest extends TestCase
{
    public function test_la_app_usa_zona_horaria_de_guadalajara_e_idioma_espanol(): void
    {
        $this->assertSame('America/Mexico_City', config('app.timezone'));
        $this->assertSame('es', config('app.locale'));
        $this->assertSame('es', config('app.fallback_locale'));
        $this->assertSame('America/Mexico_City', date_default_timezone_get());
    }

    public function test_los_mensajes_de_validacion_estan_traducidos(): void
    {
        $this->assertSame('El campo correo electrónico es obligatorio.', __('validation.required', ['attribute' => 'correo electrónico']));
        $this->assertSame('Estas credenciales no coinciden con nuestros registros.', __('auth.failed'));
    }

    public function test_una_validacion_fallida_de_la_api_responde_en_espanol(): void
    {
        $respuesta = $this->postJson('/api/auth/login', []);

        $respuesta->assertStatus(422);
        foreach ($respuesta->json('errors') as $mensajes) {
            foreach ($mensajes as $mensaje) {
                $this->assertStringNotContainsString('validation.', $mensaje);
                $this->assertStringNotContainsString('field is required', $mensaje);
            }
        }
    }

    public function test_cors_acepta_solo_el_front_de_desarrollo_y_sin_credenciales(): void
    {
        $this->assertSame(['http://localhost:3000', 'http://127.0.0.1:3000'], config('cors.allowed_origins'));
        $this->assertFalse(config('cors.supports_credentials'));
    }

    public function test_sanctum_esta_instalado_y_el_modelo_de_usuario_puede_emitir_tokens(): void
    {
        $this->assertTrue(class_exists(Sanctum::class));
        $this->assertContains(\Laravel\Sanctum\HasApiTokens::class, class_uses_recursive(\App\Models\User::class));
        $this->assertSame('sanctum', config('auth.guards.api.driver'));
    }
}
