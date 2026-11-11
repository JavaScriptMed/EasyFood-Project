<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ReglaDeNegocioException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\LiquidacionComedor;
use App\Models\Provider;
use App\Services\Bitacora;
use App\Services\CalendarioDeServicio;
use App\Services\GeneradorDeLiquidaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiquidacionController extends Controller
{
    public function __construct(private readonly GeneradorDeLiquidaciones $liquidaciones) {}

    public function index(Request $request): JsonResponse
    {
        $mes = $request->validate(['mes' => 'required|date_format:Y-m'])['mes'];

        return response()->json(
            LiquidacionComedor::query()->with('comedor')->whereDate('mes', "{$mes}-01")->orderBy('id')->get()
                ->map(fn ($l) => Vistas::liquidacion($l) + ['comedor' => Vistas::comedor($l->comedor)])->values()
        );
    }

    public function generar(Request $request): JsonResponse
    {
        $mes = $request->validate(['mes' => 'required|date_format:Y-m'])['mes'];
        if (! app(CalendarioDeServicio::class)->mesTerminado("{$mes}-01")) {
            throw new ReglaDeNegocioException('mes_sin_terminar', 'Solo se genera la liquidaciones de un mes que ya terminó.');
        }

        $generadas = [];
        foreach (Provider::query()->where('is_active', true)->orderBy('id')->get() as $comedor) {
            try {
                $generadas[] = $this->liquidaciones->generar($comedor, "{$mes}-01");
            } catch (ReglaDeNegocioException $e) {
                if ($e->codigo !== 'sin_contrato') {
                    throw $e;
                }
            }
        }
        Bitacora::registrar($request->user(), 'liquidaciones.generadas', 'liquidaciones_comedor', $mes, 'Generó las liquidaciones de '.$mes.': '.count($generadas).'.');

        return response()->json(collect($generadas)->map(fn ($l) => Vistas::liquidacion($l))->values());
    }

    public function pagar(Request $request, int $id): JsonResponse
    {
        $liquidacion = $this->liquidaciones->marcarPagada(LiquidacionComedor::query()->findOrFail($id));
        Bitacora::registrar($request->user(), 'liquidacion.pagada', 'liquidaciones_comedor', $liquidacion->id, "Marcó como pagada la liquidación {$liquidacion->id} ({$liquidacion->mes->format('Y-m')}).");

        return response()->json(Vistas::liquidacion($liquidacion));
    }
}
