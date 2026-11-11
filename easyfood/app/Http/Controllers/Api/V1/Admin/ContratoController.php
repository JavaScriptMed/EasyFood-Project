<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContratoComedor;
use App\Models\ContratoEmpresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContratoController extends Controller
{
    /** Contratos activos de empresas y comedores que vencen dentro de `dias` días (30 por defecto) o ya vencieron. */
    public function porVencer(Request $request): JsonResponse
    {
        $dias = (int) ($request->validate(['dias' => 'nullable|integer|min:1|max:365'])['dias'] ?? 30);
        $limite = now()->startOfDay()->addDays($dias)->toDateString();

        $empresas = ContratoEmpresa::query()->activos()->with('empresa')->whereDate('vigencia_fin', '<=', $limite)->get()
            ->map(fn ($c) => ['tipo' => 'empresa', 'id' => $c->empresa_id, 'nombre' => $c->empresa->nombre, 'fin' => $c->vigencia_fin]);
        $comedores = ContratoComedor::query()->activos()->with('comedor')->whereDate('vigencia_fin', '<=', $limite)->get()
            ->map(fn ($c) => ['tipo' => 'comedor', 'id' => $c->comedor_id, 'nombre' => $c->comedor->commercial_name ?: $c->comedor->business_name, 'fin' => $c->vigencia_fin]);

        return response()->json($empresas->concat($comedores)->map(fn ($f) => [
            'tipo' => $f['tipo'], 'id' => $f['id'], 'nombre' => $f['nombre'], 'vigencia_fin' => $f['fin']->toDateString(),
            'dias' => (int) now()->startOfDay()->diffInDays($f['fin']->copy()->startOfDay(), false),
        ])->sortBy('dias')->values());
    }
}
