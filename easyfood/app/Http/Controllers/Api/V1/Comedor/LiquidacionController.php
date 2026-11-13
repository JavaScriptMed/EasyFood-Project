<?php

namespace App\Http\Controllers\Api\V1\Comedor;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\LiquidacionComedor;
use App\Support\Api\ContextoDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiquidacionController extends Controller
{
    /** Pagos al propio comedor, del mes más reciente al más antiguo. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            LiquidacionComedor::query()->where('comedor_id', ContextoDelUsuario::comedor($request)->id)->orderByDesc('mes')->get()->map(fn ($l) => Vistas::liquidacion($l))->values()
        );
    }
}
