<?php

namespace App\Http\Controllers\Api\V1\Cliente;

use App\Http\Controllers\Controller;
use App\Services\Pagos\PasarelaDePagos;
use App\Services\PagosDePedidos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PagoController extends Controller
{
    /**
     * Inicia el pago con tarjeta de un pedido propio (por pagar y sin vencer): crea un PaymentIntent por el total en MXN y devuelve
     * su `client_secret` para que la app cobre con el SDK de Stripe. No cambia el pedido: lo pasa a «recibido» el webhook cuando
     * Stripe confirma el cobro. Repetir la llamada devuelve el mismo intento.
     */
    public function store(Request $request, int $id, PagosDePedidos $pagos, PasarelaDePagos $pasarela): JsonResponse
    {
        if (! $pasarela->configurada()) {
            return response()->json(['mensaje' => 'Los pagos con tarjeta todavía no están configurados.', 'codigo' => 'pagos_no_configurados'], 503);
        }

        return response()->json($pagos->iniciar($request->user(), $id));
    }
}
