<?php

namespace App\Http\Controllers\Api\V1\Empresa;

use App\Http\Controllers\Api\V1\Admin\FacturaController as FacturaAdmin;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\FacturaMensual;
use App\Support\Api\ContextoDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacturaController extends Controller
{
    /** Facturas de la propia empresa. Los borradores son internos de EasyLunch: la empresa solo ve las emitidas y pagadas. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            FacturaMensual::query()->where('empresa_id', ContextoDelUsuario::empresa($request)->id)->where('estado', '<>', 'borrador')
                ->orderByDesc('mes')->get()->map(fn ($f) => Vistas::factura($f))->values()
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $factura = FacturaMensual::query()->where('empresa_id', ContextoDelUsuario::empresa($request)->id)->where('estado', '<>', 'borrador')->findOrFail($id);

        return response()->json(FacturaAdmin::detalle($factura));
    }
}
