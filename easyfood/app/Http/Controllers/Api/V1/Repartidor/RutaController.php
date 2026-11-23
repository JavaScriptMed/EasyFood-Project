<?php

namespace App\Http\Controllers\Api\V1\Repartidor;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\VistaDelRepartidor;
use App\Services\CalendarioDeServicio;
use App\Services\EntregasDelRepartidor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RutaController extends Controller
{
    /** Las rutas del repartidor de un día (por defecto hoy) con sus paradas. Solo las aprobadas por el administrador. */
    public function index(Request $request, EntregasDelRepartidor $entregas, CalendarioDeServicio $calendario): JsonResponse
    {
        $fecha = $request->validate(['fecha' => 'nullable|date_format:Y-m-d'])['fecha'] ?? $calendario->ahora()->toDateString();

        return response()->json(VistaDelRepartidor::rutas($entregas->rutasDelDia($request->user(), $fecha)));
    }
}
