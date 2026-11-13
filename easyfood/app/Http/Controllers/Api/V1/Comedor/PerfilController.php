<?php

namespace App\Http\Controllers\Api\V1\Comedor;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Menu;
use App\Support\Api\ContextoDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PerfilController extends Controller
{
    /** El propio comedor con su contrato, sus platillos publicados y su avance del mes. */
    public function show(Request $request): JsonResponse
    {
        $comedor = ContextoDelUsuario::comedor($request)->load(['user.profile', 'contratos']);
        $publicados = Menu::query()->where('provider_id', $comedor->id)->where('estado_revision', 'publicado')->count();
        $producidos = (int) DB::table('ordenes_produccion')->where('comedor_id', $comedor->id)
            ->whereBetween('fecha', [now()->startOfMonth()->toDateString(), now()->toDateString()])->sum('porciones_totales');

        return response()->json(Vistas::comedorDetalle($comedor, $publicados, $producidos));
    }
}
