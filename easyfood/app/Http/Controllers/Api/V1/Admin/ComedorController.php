<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ComedorController extends Controller
{
    public function index(): JsonResponse
    {
        $comedores = Provider::query()->with(['user.profile', 'user.role', 'contratos'])->orderBy('business_name')->get();
        [$publicados, $entregados] = $this->cuentas($comedores->pluck('id'));

        return response()->json($comedores->map(fn (Provider $p) => Vistas::comedorDetalle($p, $publicados[$p->id] ?? 0, $entregados[$p->id] ?? 0))->values());
    }

    public function show(int $id): JsonResponse
    {
        $comedor = Provider::query()->with(['user.profile', 'user.role', 'contratos'])->findOrFail($id);
        [$publicados, $entregados] = $this->cuentas(collect([$id]));

        return response()->json(Vistas::comedorDetalle($comedor, $publicados[$id] ?? 0, $entregados[$id] ?? 0));
    }

    /** @return array{0: array<int,int>, 1: array<int,int>} platillos publicados y porciones producidas en el mes, por comedor */
    private function cuentas($ids): array
    {
        $publicados = DB::table('menus')->whereIn('provider_id', $ids)->where('estado_revision', 'publicado')->whereNull('deleted_at')
            ->groupBy('provider_id')->selectRaw('provider_id, count(*) as total')->pluck('total', 'provider_id')->map(fn ($n) => (int) $n)->all();
        $producidos = DB::table('ordenes_produccion')->whereIn('comedor_id', $ids)
            ->whereBetween('fecha', [now()->startOfMonth()->toDateString(), now()->toDateString()])
            ->groupBy('comedor_id')->selectRaw('comedor_id, sum(porciones_totales) as total')->pluck('total', 'comedor_id')->map(fn ($n) => (int) $n)->all();

        return [$publicados, $producidos];
    }
}
