<?php

namespace App\Http\Controllers\Api\V1\Empresa;

use App\Http\Controllers\Controller;
use App\Services\IndicadoresDelDashboard;
use App\Support\Api\ContextoDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IndicadoresController extends Controller
{
    /** Los indicadores de RR. HH. de la propia empresa: platillos entregados, calificaciones, ahorro por empleado, quejas y estatus de facturación. */
    public function show(Request $request, IndicadoresDelDashboard $indicadores): JsonResponse
    {
        return response()->json($indicadores->paraEmpresa(ContextoDelUsuario::empresa($request)));
    }
}
