<?php

namespace App\Http\Controllers\Api\V1\Repartidor;

use App\Http\Controllers\Controller;
use App\Services\EntregasDelRepartidor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisponibilidadController extends Controller
{
    /** Si el repartidor está en línea y si tiene una ruta en curso. */
    public function show(Request $request, EntregasDelRepartidor $entregas): JsonResponse
    {
        return response()->json($entregas->disponibilidad($request->user()));
    }

    /** Se pone en línea (`en_linea: true`) o fuera de línea (`false`). Fuera de línea no se puede con una ruta en curso (422 `ruta_en_curso`). */
    public function update(Request $request, EntregasDelRepartidor $entregas): JsonResponse
    {
        $d = $request->validate(['en_linea' => 'required|boolean']);

        return response()->json($entregas->cambiarDisponibilidad($request->user(), (bool) $d['en_linea']));
    }
}
