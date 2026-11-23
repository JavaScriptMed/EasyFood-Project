<?php

namespace App\Services;

use App\Support\Api\RolesDeApi;
use Illuminate\Support\Facades\Log;

/**
 * Los enlaces de los correos de cuenta (invitación y restablecer contraseña): dónde caen en el front y cómo verlos en desarrollo.
 *
 * Con MAIL_MAILER=log el correo completo queda en laravel.log, pero codificado y difícil de leer. Por eso, en local y en pruebas,
 * cada enlace además se escribe, solo y en una línea, en storage/logs/enlaces.log:  tail -f storage/logs/enlaces.log
 * En producción NO se escribe nunca (un enlace en un log permitiría entrar a la cuenta de otra persona).
 */
class EnlacesDeCuenta
{
    private const BASE_DEL_PORTAL = ['admin' => '/admin', 'comedores' => '/comedores', 'empresas' => '/empresas', 'empleados' => '/empleados'];

    /** El enlace que aparece en el correo de invitación: la pantalla «aceptar invitación» del portal del rol. */
    public function invitacion(string $token, string $rolDelFront, string $tipoDeOrganizacion): string
    {
        $portal = RolesDeApi::portal($rolDelFront) ?? ['comedor' => 'comedores', 'empresa' => 'empresas'][$tipoDeOrganizacion] ?? 'admin';

        return $this->base().self::BASE_DEL_PORTAL[$portal].'/aceptar-invitacion?token='.$token;
    }

    /** El enlace del correo para restablecer la contraseña: la pantalla del portal de esa persona. */
    public function restablecimiento(string $token, string $portal): string
    {
        return $this->base().self::BASE_DEL_PORTAL[$portal].'/restablecer-contrasena?token='.$token;
    }

    /** Deja el enlace a la vista en desarrollo (storage/logs/enlaces.log). */
    public function mostrarEnDesarrollo(string $asunto, string $correo, string $url): void
    {
        if (app()->environment('local', 'testing')) {
            Log::channel('enlaces')->info("{$asunto} para {$correo}\n{$url}\n");
        }
    }

    private function base(): string
    {
        return rtrim((string) config('easylunch.frontend_url'), '/');
    }
}
