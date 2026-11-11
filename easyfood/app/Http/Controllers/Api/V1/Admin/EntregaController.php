<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\EntregaEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntregaController extends Controller
{
    /** Entregas a empresas de una fecha (por defecto, hoy). */
    public function index(Request $request): JsonResponse
    {
        $fecha = $request->validate(['fecha' => 'nullable|date_format:Y-m-d'])['fecha'] ?? now()->toDateString();

        return response()->json(
            EntregaEmpresa::query()->with('empresa')->whereDate('fecha', $fecha)->orderBy('ventana_inicio')->orderBy('id')->get()->map(fn ($e) => Vistas::entrega($e))->values()
        );
    }
}
