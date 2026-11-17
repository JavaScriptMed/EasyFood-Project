<?php

namespace App\Http\Controllers\Api\V1\Empleado;

use App\Http\Controllers\Controller;
use App\Services\Calificaciones;
use App\Support\Api\ContextoDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalificacionController extends Controller
{
    /** Lo que el empleado ya recibió (últimos 14 días) y todavía no calificó. */
    public function pendientes(Request $request, Calificaciones $calificaciones): JsonResponse
    {
        return response()->json($calificaciones->pendientesDelEmpleado(ContextoDelUsuario::empleado($request)));
    }

    /** Califica la entrega (puntualidad, trato del chofer y empaque, de 1 a 5). */
    public function entrega(Request $request, Calificaciones $calificaciones): JsonResponse
    {
        $d = $request->validate([
            'fecha' => 'required|date_format:Y-m-d', 'puntualidad' => 'required|integer|between:1,5', 'trato_chofer' => 'required|integer|between:1,5',
            'empaque' => 'required|integer|between:1,5', 'comentario' => 'nullable|string|max:500',
        ]);
        $c = $calificaciones->calificarEntregaComoEmpleado($request->user(), ContextoDelUsuario::empleado($request), $d['fecha'], $d, $d['comentario'] ?? null);

        return response()->json(['id' => $c->id, 'mensaje' => 'Gracias por calificar la entrega.'], 201);
    }

    /** Califica el sabor y la calidad del platillo de ese tiempo de comida (de 1 a 5). */
    public function platillo(Request $request, Calificaciones $calificaciones): JsonResponse
    {
        $d = $request->validate(['fecha' => 'required|date_format:Y-m-d', 'tiempo_comida' => 'required|in:desayuno,comida,cena', 'estrellas' => 'required|integer|between:1,5', 'comentario' => 'nullable|string|max:500']);
        $c = $calificaciones->calificarPlatilloComoEmpleado($request->user(), ContextoDelUsuario::empleado($request), $d['fecha'], $d['tiempo_comida'], (int) $d['estrellas'], $d['comentario'] ?? null);

        return response()->json(['id' => $c->id, 'mensaje' => 'Gracias por calificar el platillo.'], 201);
    }
}
