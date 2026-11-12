<?php

namespace App\Http\Controllers\Api\V1\Empresa;

use App\Http\Controllers\Api\V1\Admin\EmpresaController;
use App\Http\Controllers\Controller;
use App\Support\Api\ContextoDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PerfilController extends Controller
{
    /** La propia empresa con su contacto, su contrato, sus empleados activos y el estado del contrato. */
    public function show(Request $request): JsonResponse
    {
        return response()->json(EmpresaController::detallePorId(ContextoDelUsuario::empresa($request)->id));
    }
}
