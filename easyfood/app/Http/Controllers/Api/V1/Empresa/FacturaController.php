<?php

namespace App\Http\Controllers\Api\V1\Empresa;

use App\Http\Controllers\Api\V1\Admin\FacturaController as FacturaAdmin;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\FacturaMensual;
use App\Services\CalendarioDeServicio;
use App\Services\GeneradorDeFacturas;
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

    /**
     * El consumo y la factura de un mes de la propia empresa, también del mes en curso («hasta hoy»), sin guardar nada.
     * Si ya existe la factura emitida o pagada de ese mes, devuelve esa (congelada). No acepta meses futuros.
     */
    public function vistaPrevia(Request $request, CalendarioDeServicio $calendario, GeneradorDeFacturas $facturas): JsonResponse
    {
        $mes = $request->validate(['mes' => 'required|date_format:Y-m'])['mes'];
        $inicio = $calendario->dia("{$mes}-01");
        if ($inicio->gt($calendario->ahora()->startOfMonth())) {
            throw new \App\Exceptions\ReglaDeNegocioException('mes_futuro', 'Todavía no hay consumo de un mes que no ha empezado.');
        }
        $empresa = ContextoDelUsuario::empresa($request);

        $real = FacturaMensual::query()->where('empresa_id', $empresa->id)->whereDate('mes', $inicio->toDateString())->where('estado', '<>', 'borrador')->first();
        if ($real) {
            return response()->json(FacturaAdmin::detalle($real));
        }

        $c = $facturas->calcular($empresa->id, $inicio);

        return response()->json([
            'id' => null, 'folio' => 'EL-'.$inicio->format('Ym').'-previa', 'empresa_id' => $empresa->id, 'mes' => $mes, 'platillos' => $c['platillos'],
            'parte_empresa' => Vistas::dinero($c['parte_empresa']), 'parte_empleados' => Vistas::dinero($c['parte_empleados']), 'platillos_faltantes' => $c['platillos_faltantes'],
            'diferencia_minimo' => Vistas::dinero($c['diferencia_minimo']), 'total' => Vistas::dinero($c['total']), 'estado' => 'borrador', 'emitida_en' => null, 'pagada_en' => null,
            'importe_consumo' => Vistas::dinero((float) $c['parte_empresa'] + (float) $c['parte_empleados']),
            'lineas' => FacturaAdmin::lineas($empresa->id, $inicio->toDateString()), 'es_vista_previa' => true,
        ]);
    }
}
