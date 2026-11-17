<?php

namespace App\Http\Controllers\Api\V1\Cliente;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Services\Calificaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalificacionController extends Controller
{
    /** El cliente califica la entrega de un día de su pedido (puntualidad, trato del chofer y empaque, de 1 a 5). */
    public function entrega(Request $request, int $id, Calificaciones $calificaciones): JsonResponse
    {
        $d = $request->validate([
            'fecha' => 'required|date_format:Y-m-d', 'puntualidad' => 'required|integer|between:1,5', 'trato_chofer' => 'required|integer|between:1,5',
            'empaque' => 'required|integer|between:1,5', 'comentario' => 'nullable|string|max:500',
        ]);
        $pedido = Pedido::query()->where('cliente_id', $request->user()->id)->findOrFail($id);
        $c = $calificaciones->calificarEntregaComoCliente($request->user(), $pedido, $d['fecha'], $d, $d['comentario'] ?? null);

        return response()->json(['id' => $c->id, 'mensaje' => 'Gracias por calificar la entrega.'], 201);
    }

    /** El cliente califica el sabor y la calidad de un platillo de un día entregado de su pedido. */
    public function platillo(Request $request, int $id, Calificaciones $calificaciones): JsonResponse
    {
        $d = $request->validate(['fecha' => 'required|date_format:Y-m-d', 'platillo_id' => 'required|integer', 'estrellas' => 'required|integer|between:1,5', 'comentario' => 'nullable|string|max:500']);
        $pedido = Pedido::query()->where('cliente_id', $request->user()->id)->findOrFail($id);
        $c = $calificaciones->calificarPlatilloComoCliente($request->user(), $pedido, $d['fecha'], (int) $d['platillo_id'], (int) $d['estrellas'], $d['comentario'] ?? null);

        return response()->json(['id' => $c->id, 'mensaje' => 'Gracias por calificar el platillo.'], 201);
    }
}
