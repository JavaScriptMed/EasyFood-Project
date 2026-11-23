<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Services\EdicionDeRegistros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComedorEdicionController extends Controller
{
    /** Edita el comedor, su contacto y su contrato vigente (precio por platillo y mínimo mensual). */
    public function update(Request $request, int $id, EdicionDeRegistros $ediciones): JsonResponse
    {
        $d = $request->validate([
            'nombre' => 'required|string|min:2|max:150', 'zona' => 'required|string|max:100',
            'contacto_nombre' => 'nullable|string|max:120', 'contacto_correo' => 'nullable|email|max:190', 'contacto_telefono' => 'nullable|string|max:20',
            'precio_por_platillo' => 'required|numeric|gt:0|max:100000', 'compra_minima_mensual' => 'required|integer|min:1|max:1000000',
        ]);
        $ediciones->editarComedor($request->user(), Provider::query()->with('user')->findOrFail($id), $d);

        return response()->json(ComedorController::detallePorId($id));
    }

    /** Desactiva o reactiva un comedor. Nada se borra. */
    public function estado(Request $request, int $id, EdicionDeRegistros $ediciones): JsonResponse
    {
        $activo = $request->validate(['activo' => 'required|boolean'])['activo'];
        $ediciones->cambiarEstadoDeComedor($request->user(), Provider::query()->findOrFail($id), $activo);

        return response()->json(ComedorController::detallePorId($id));
    }
}
