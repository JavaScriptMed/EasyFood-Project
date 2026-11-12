<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Exceptions\ReglaDeNegocioException;
use App\Models\ContratoComedor;
use App\Models\ContratoEmpresa;
use Illuminate\Http\JsonResponse;
use App\Models\Provider;
use App\Services\Bitacora;
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

    /**
     * Renueva por 6 meses el contrato activo de la empresa: la vigencia se extiende 6 meses desde su fin
     * (o desde hoy si ya había vencido). Los términos (subsidio, mínimo, precio, horario) no cambian.
     */
    public function renovarEmpresa(Request $request, int $id): JsonResponse
    {
        $contrato = ContratoEmpresa::query()->activos()->where('empresa_id', $id)->orderByDesc('vigencia_inicio')->first()
            ?? throw new ReglaDeNegocioException('sin_contrato_activo', 'Esa empresa no tiene un contrato activo que renovar.');

        $nuevoFin = $this->nuevoFin($contrato->vigencia_fin);
        $contrato->update(['vigencia_fin' => $nuevoFin]);
        Bitacora::registrar($request->user(), 'contrato.renovado', 'contratos_empresa', $contrato->id, "Renovó por 6 meses el contrato de la empresa {$id}: ahora vence el {$nuevoFin}.");

        return response()->json(EmpresaController::detallePorId($id));
    }

    public function renovarComedor(Request $request, int $id): JsonResponse
    {
        Provider::query()->findOrFail($id);
        $contrato = ContratoComedor::query()->activos()->where('comedor_id', $id)->orderByDesc('vigencia_inicio')->first()
            ?? throw new ReglaDeNegocioException('sin_contrato_activo', 'Ese comedor no tiene un contrato activo que renovar.');

        $nuevoFin = $this->nuevoFin($contrato->vigencia_fin);
        $contrato->update(['vigencia_fin' => $nuevoFin]);
        Bitacora::registrar($request->user(), 'contrato.renovado', 'contratos_comedor', $contrato->id, "Renovó por 6 meses el contrato del comedor {$id}: ahora vence el {$nuevoFin}.");

        return app(ComedorController::class)->show($id);
    }

    private function nuevoFin(\Carbon\CarbonInterface $fin): string
    {
        $hoy = now()->startOfDay();

        return ($fin->lt($hoy) ? $hoy : $fin->copy()->startOfDay())->addMonthsNoOverflow(6)->toDateString();
    }
}
