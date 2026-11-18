<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Paquete;
use App\Services\EdicionDeRegistros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaqueteController extends Controller
{
    /** Edita un plan para clientes individuales (nombre, descuento, detalle y si va destacado). Los días no cambian. */
    public function update(Request $request, int $id, EdicionDeRegistros $ediciones): JsonResponse
    {
        $d = $request->validate([
            'nombre' => 'required|string|min:2|max:60', 'descuento_porcentaje' => 'required|numeric|min:0|max:100',
            'detalle' => 'nullable|string|max:255', 'destacado' => 'required|boolean',
        ]);
        $paquete = $ediciones->editarPaquete($request->user(), Paquete::query()->findOrFail($id), $d['nombre'], $d['descuento_porcentaje'], $d['detalle'] ?? null, $d['destacado']);

        return response()->json(Vistas::paquete($paquete));
    }
}
