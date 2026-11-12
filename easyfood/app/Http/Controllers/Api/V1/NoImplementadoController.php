<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Endpoints que ya tienen su ruta, su rol y su nombre pero todavía no se construyen (los más complejos, el dashboard
 * y las imágenes). Responden 501 con un mensaje claro para que el front los distinga de un error real.
 * Cuando se construya uno, se cambia su ruta a su controlador y esta clase deja de usarse para él.
 */
class NoImplementadoController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'mensaje' => 'Este endpoint ya está configurado pero todavía no está construido.',
            'codigo' => 'no_implementado',
        ], 501);
    }
}
