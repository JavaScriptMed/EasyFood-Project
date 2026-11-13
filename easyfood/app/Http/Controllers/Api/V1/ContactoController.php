<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MensajeContacto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactoController extends Controller
{
    /** Formulario de contacto del sitio público (sin sesión; limitado a 10 envíos por minuto). */
    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => 'required|string|min:2|max:150', 'correo' => 'required|email|max:150', 'empresa' => 'nullable|string|max:150', 'mensaje' => 'required|string|min:10|max:3000',
        ]);

        $mensaje = MensajeContacto::create($datos);

        return response()->json(['id' => $mensaje->id, 'mensaje' => 'Gracias por escribirnos: te respondemos lo antes posible.'], 201);
    }
}
