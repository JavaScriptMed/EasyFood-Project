<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\FormattableHandlerInterface;

/**
 * Los logs nunca deben guardar claves ni credenciales: ni de Stripe, ni tokens de acceso, ni contraseñas, ni los enlaces de invitación
 * o de restablecimiento (el token del enlace equivale a la contraseña). Se aplica a TODOS los canales de logging salvo `enlaces`,
 * que existe a propósito para ver esos enlaces en desarrollo y nunca escribe en producción (ver EnlacesDeCuenta).
 *
 * Actúa sobre la línea ya formateada, así que también limpia mensajes de excepciones y trazas con argumentos.
 * Uso en config/logging.php:  'tap' => [App\Logging\OcultarSecretos::class]
 */
class OcultarSecretos
{
    public const OCULTO = '[oculto]';

    /** @var array<string, string> expresión regular => reemplazo */
    private const PATRONES = [
        // Claves de Stripe: secretas, restringidas, publicables y de webhook.
        '/\b(?:sk|rk|pk)_(?:test|live)_[A-Za-z0-9]{6,}/' => 'clave_stripe_oculta',
        '/\bwhsec_[A-Za-z0-9]{6,}/' => 'secreto_webhook_oculto',
        // Tokens de acceso de Sanctum («12|abcdef…») y cualquier «Bearer xxx».
        '/\bBearer\s+[A-Za-z0-9._~+\/=|-]{8,}/i' => 'Bearer [oculto]',
        '/\b\d{1,10}\|[A-Za-z0-9]{30,}/' => 'token_oculto',
        // Tokens que viajan en la ruta o en el enlace (invitaciones y restablecer contraseña).
        '#(/cuentas/(?:invitaciones|restablecer)/)[A-Za-z0-9]{16,}#' => '$1[oculto]',
        '/([?&]token=)[^\s&"\'<>]+/i' => '$1[oculto]',
        // «password=…», "contrasena": "…", secret: …, api_key=…, authorization: …
        '/((?:password|passwd|contrase(?:ñ|n)a|secret|api[_-]?key|authorization|token)["\']?\s*[:=]\s*["\']?)[^\s"\',&}\]]+/iu' => '$1[oculto]',
    ];

    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getLogger()->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new FormateadorSinSecretos($handler->getFormatter()));
            }
        }
    }

    public static function limpiar(string $texto): string
    {
        foreach (self::PATRONES as $patron => $reemplazo) {
            $texto = preg_replace($patron, $reemplazo, $texto) ?? $texto;
        }

        return $texto;
    }
}
