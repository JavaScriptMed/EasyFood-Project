<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\OrdenProduccion;
use App\Services\Dinero;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReporteController extends Controller
{
    /** Lo que se le pide a cada comedor para una fecha, con su costo (porciones × el precio de su contrato). */
    public function proveedor(Request $request): JsonResponse
    {
        $fecha = $request->validate(['fecha' => 'required|date_format:Y-m-d'])['fecha'];

        return response()->json(
            OrdenProduccion::query()->with(['menu', 'comedor.contratos'])->whereDate('fecha', $fecha)->orderBy('comedor_id')->orderBy('id')->get()->map(function (OrdenProduccion $o) {
                $contrato = $o->comedor->contratos->where('activo', true)->sortByDesc('vigencia_inicio')->first() ?? $o->comedor->contratos->sortByDesc('vigencia_inicio')->first();

                return [
                    'comedor_nombre' => $o->comedor->commercial_name ?: $o->comedor->business_name, 'platillo_nombre' => $o->menu->name,
                    'tiempo_comida' => $o->menu->tiempo_comida, 'porciones' => $o->porciones_totales,
                    'costo_total' => Vistas::dinero(Dinero::formato($o->porciones_totales * ($contrato ? Dinero::centavos($contrato->precio_por_platillo) : 0))),
                ];
            })->values()
        );
    }
}
