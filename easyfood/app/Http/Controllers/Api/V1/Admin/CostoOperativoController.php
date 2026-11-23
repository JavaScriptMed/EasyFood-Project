<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ReglaDeNegocioException;
use App\Http\Controllers\Controller;
use App\Models\CostoOperativo;
use App\Services\Bitacora;
use App\Services\CalendarioDeServicio;
use App\Services\Dinero;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Otros gastos de la operación (reparto, empaque, nómina…) que entran al balance de utilidad del dashboard. Se anulan, no se borran. */
class CostoOperativoController extends Controller
{
    private const CATEGORIAS = 'reparto,empaque,nomina,tecnologia,otro';

    /** Del más reciente al más antiguo. Filtros: desde y hasta (por defecto, las últimas 6 semanas). */
    public function index(Request $request, CalendarioDeServicio $calendario): JsonResponse
    {
        $f = $request->validate(['desde' => 'nullable|date_format:Y-m-d', 'hasta' => 'nullable|date_format:Y-m-d|after_or_equal:desde']);
        $hoy = $calendario->ahora();

        return response()->json(
            CostoOperativo::query()->with('registradoPor')->whereDate('fecha', '>=', $f['desde'] ?? $hoy->startOfWeek()->subWeeks(5)->toDateString())->whereDate('fecha', '<=', $f['hasta'] ?? $hoy->toDateString())
                ->orderByDesc('fecha')->orderByDesc('id')->get()->map(fn ($c) => self::vista($c))->values()
        );
    }

    public function store(Request $request, CalendarioDeServicio $calendario): JsonResponse
    {
        $datos = $request->validate([
            'fecha' => 'required|date_format:Y-m-d|before_or_equal:'.$calendario->ahora()->toDateString(), 'categoria' => 'required|in:'.self::CATEGORIAS,
            'descripcion' => 'required|string|min:3|max:255', 'monto' => 'required|numeric|min:0.01|max:99999999',
        ]);

        $costo = CostoOperativo::create($datos + ['monto' => Dinero::formato(Dinero::centavos($datos['monto'])), 'registrado_por' => $request->user()->id])->refresh();
        Bitacora::registrar($request->user(), 'costo_operativo.registrado', 'costos_operativos', $costo->id, "Registró un costo de {$costo->monto} MXN ({$costo->categoria}): {$costo->descripcion}.");

        return response()->json(self::vista($costo->load('registradoPor')), 201);
    }

    /** Anula un costo (queda en el historial, pero ya no cuenta en el balance). */
    public function anular(Request $request, int $id): JsonResponse
    {
        $costo = CostoOperativo::query()->findOrFail($id);
        if (! $costo->activo) {
            throw new ReglaDeNegocioException('costo_ya_anulado', 'Ese costo ya está anulado.');
        }
        $costo->desactivar();
        Bitacora::registrar($request->user(), 'costo_operativo.anulado', 'costos_operativos', $costo->id, "Anuló el costo de {$costo->monto} MXN ({$costo->categoria}): {$costo->descripcion}.");

        return response()->json(self::vista($costo->refresh()->load('registradoPor')));
    }

    private static function vista(CostoOperativo $c): array
    {
        return [
            'id' => $c->id, 'fecha' => $c->fecha->toDateString(), 'categoria' => $c->categoria, 'descripcion' => $c->descripcion, 'monto' => round((float) $c->monto, 2),
            'registrado_por' => $c->registradoPor?->name, 'activo' => $c->activo,
        ];
    }
}
