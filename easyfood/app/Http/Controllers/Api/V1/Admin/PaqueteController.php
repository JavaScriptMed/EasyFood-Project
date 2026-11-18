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
    /** Crea un plan (de 1, 2 o 6 días; uno activo por duración). */
    public function store(Request $request, EdicionDeRegistros $ediciones): JsonResponse
    {
        $d = $request->validate([
            'nombre' => 'required|string|min:2|max:60', 'dias' => 'required|integer|in:1,2,6', 'descuento_porcentaje' => 'required|numeric|min:0|max:100',
            'detalle' => 'nullable|string|max:255', 'destacado' => 'nullable|boolean',
        ]);
        $paquete = $ediciones->crearPaquete($request->user(), $d['nombre'], (int) $d['dias'], $d['descuento_porcentaje'], $d['detalle'] ?? null, (bool) ($d['destacado'] ?? false));

        return response()->json(Vistas::paquete($paquete), 201);
    }

    /** Desactiva o reactiva un plan. */
    public function estado(Request $request, int $id, EdicionDeRegistros $ediciones): JsonResponse
    {
        $activo = $request->validate(['activo' => 'required|boolean'])['activo'];

        return response()->json(Vistas::paquete($ediciones->cambiarEstadoDePaquete($request->user(), Paquete::query()->findOrFail($id), $activo)));
    }

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
