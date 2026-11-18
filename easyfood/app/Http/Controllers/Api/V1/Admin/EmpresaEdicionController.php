<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\EdicionDeRegistros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmpresaEdicionController extends Controller
{
    /** Edita la empresa, su contacto y su contrato vigente (subsidio, mínimo mensual y ventana de entrega). */
    public function update(Request $request, int $id, EdicionDeRegistros $ediciones): JsonResponse
    {
        $d = $request->validate([
            'nombre' => 'required|string|min:2|max:150', 'direccion_entrega' => 'required|string|min:3|max:255',
            'contacto_nombre' => 'nullable|string|max:120', 'contacto_correo' => 'nullable|email|max:190', 'contacto_telefono' => 'nullable|string|max:20',
            'porcentaje_subsidio' => 'required|numeric|min:0|max:100', 'compra_minima_mensual' => 'required|integer|min:0',
            'ventana_entrega_inicio' => 'required|date_format:H:i', 'ventana_entrega_fin' => 'required|date_format:H:i',
        ]);
        $ediciones->editarEmpresa($request->user(), Empresa::query()->with('usuario')->findOrFail($id), $d);

        return response()->json(EmpresaController::detallePorId($id));
    }

    /** Desactiva o reactiva una empresa. Nada se borra. */
    public function estado(Request $request, int $id, EdicionDeRegistros $ediciones): JsonResponse
    {
        $activo = $request->validate(['activo' => 'required|boolean'])['activo'];
        $ediciones->cambiarEstadoDeEmpresa($request->user(), Empresa::query()->findOrFail($id), $activo);

        return response()->json(EmpresaController::detallePorId($id));
    }
}
