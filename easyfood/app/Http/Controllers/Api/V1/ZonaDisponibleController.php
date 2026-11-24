<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ZonasDeEntrega;
use Illuminate\Http\JsonResponse;

class ZonaDisponibleController extends Controller
{
    /** Las zonas con servicio (activas), con su polígono simplificado. Público, sin sesión: lo usan el sitio de inicio y las apps para mostrar la cobertura. */
    public function index(ZonasDeEntrega $zonas): JsonResponse
    {
        return response()->json($zonas->disponibles());
    }
}
