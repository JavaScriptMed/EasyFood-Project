<?php

namespace App\Http\Controllers\Api\V1\Comedor;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\OrdenProduccion;
use App\Services\Bitacora;
use App\Support\Api\ContextoDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProduccionController extends Controller
{
    /** Órdenes de producción del propio comedor para una fecha (por defecto, mañana). */
    public function index(Request $request): JsonResponse
    {
        $fecha = $request->validate(['fecha' => 'nullable|date_format:Y-m-d'])['fecha'] ?? now()->addDay()->toDateString();
        $comedor = ContextoDelUsuario::comedor($request);

        return response()->json(
            OrdenProduccion::query()->with('menu')->where('comedor_id', $comedor->id)->whereDate('fecha', $fecha)->orderBy('hora_recoleccion')->orderBy('id')->get()
                ->map(fn ($o) => Vistas::ordenProduccion($o))->values()
        );
    }

    /** Marca como listas todas las órdenes pendientes del comedor para esa fecha. Devuelve cuántas marcó. */
    public function marcarListas(Request $request): JsonResponse
    {
        $fecha = $request->validate(['fecha' => 'required|date_format:Y-m-d'])['fecha'];
        $comedor = ContextoDelUsuario::comedor($request);

        $marcadas = OrdenProduccion::query()->where('comedor_id', $comedor->id)->whereDate('fecha', $fecha)->where('estado', 'pendiente')
            ->update(['estado' => 'lista', 'actualizado_en' => now()]);
        if ($marcadas > 0) {
            Bitacora::registrar($request->user(), 'produccion.lista', 'ordenes_produccion', $fecha, "Marcó {$marcadas} orden(es) del {$fecha} como listas.");
        }

        return response()->json(['marcadas' => $marcadas]);
    }
}
