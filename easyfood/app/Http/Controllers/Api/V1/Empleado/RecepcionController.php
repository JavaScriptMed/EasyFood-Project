<?php

namespace App\Http\Controllers\Api\V1\Empleado;

use App\Http\Controllers\Controller;
use App\Services\ConfirmacionDeRecepcion;
use App\Support\Api\ContextoDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecepcionController extends Controller
{
    /** Botón «Recibido»: el empleado confirma que le llegó su comida de esa fecha. Repetirlo devuelve la misma confirmación. */
    public function store(Request $request, ConfirmacionDeRecepcion $recepciones): JsonResponse
    {
        $d = $request->validate(['fecha' => 'required|date_format:Y-m-d']);
        $cuando = $recepciones->comoEmpleado($request->user(), ContextoDelUsuario::empleado($request), $d['fecha']);

        return response()->json(['recibido_en' => $cuando->toIso8601String(), 'mensaje' => '¡Gracias! Quedó registrado que recibiste tu comida.']);
    }
}
