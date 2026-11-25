<?php

namespace App\Http\Controllers\Api\V1\Cliente;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Services\ConfirmacionDeRecepcion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecepcionController extends Controller
{
    /** Botón «Recibido»: el cliente confirma que le llegó la entrega de su pedido. Repetirlo devuelve la misma confirmación. */
    public function store(Request $request, int $id, ConfirmacionDeRecepcion $recepciones): JsonResponse
    {
        $pedido = Pedido::query()->where('cliente_id', $request->user()->id)->findOrFail($id);
        $cuando = $recepciones->comoCliente($request->user(), $pedido);

        return response()->json(['recibido_en' => $cuando->toIso8601String(), 'mensaje' => '¡Gracias! Quedó registrado que recibiste tu pedido.']);
    }
}
