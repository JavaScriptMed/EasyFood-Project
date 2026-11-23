<?php

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Pagos\FirmaDeWebhookInvalida;
use App\Services\Pagos\PasarelaDePagos;
use App\Services\PagosDePedidos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeController extends Controller
{
    /**
     * Webhook de Stripe (público: la autenticidad la da la firma `Stripe-Signature`, verificada con el cuerpo crudo).
     * Responde 200 siempre que el evento sea auténtico, aunque no cambie nada (repetido, ignorado, pago tardío), para que
     * Stripe no lo reintente sin necesidad. Si algo falla de verdad responde 500 y Stripe lo reintenta.
     */
    public function recibir(Request $request, PasarelaDePagos $pasarela, PagosDePedidos $pagos): JsonResponse
    {
        if (! $pasarela->configurada()) {
            return response()->json(['mensaje' => 'Los pagos con tarjeta todavía no están configurados.', 'codigo' => 'pagos_no_configurados'], 503);
        }

        try {
            $evento = $pasarela->evento($request->getContent(), $request->header('Stripe-Signature'));
        } catch (FirmaDeWebhookInvalida) {
            return response()->json(['mensaje' => 'La firma del webhook no es válida.', 'codigo' => 'firma_invalida'], 400);
        }

        return response()->json(['recibido' => true, 'resultado' => $pagos->procesarEvento($evento)]);
    }
}
