<?php

namespace App\Http\Controllers\Api\V1\Cliente;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Paquete;
use Illuminate\Http\JsonResponse;

class PaqueteController extends Controller
{
    /** Paquetes de 1, 2 y 6 días disponibles. El descuento de cada uno lo define el administrador. */
    public function index(): JsonResponse
    {
        return response()->json(Paquete::query()->activos()->orderBy('dias')->get()->map(fn ($p) => Vistas::paquete($p))->values());
    }
}
