<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\IndicadoresDelDashboard;
use Illuminate\Http\JsonResponse;

class CalificacionMenuController extends Controller
{
    /** La calificación semanal del menú (promedio de estrellas al sabor y la calidad): la ve cualquiera con sesión, en tiempo real. */
    public function show(IndicadoresDelDashboard $indicadores): JsonResponse
    {
        return response()->json($indicadores->calificacionDelMenu());
    }
}
