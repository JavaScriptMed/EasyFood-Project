<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Corte;
use App\Services\Bitacora;
use App\Services\CalendarioDeServicio;
use App\Services\EjecutorDelCorte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CorteController extends Controller
{
    /** Historial de cortes, del más reciente al más antiguo. */
    public function index(Request $request): JsonResponse
    {
        $limite = (int) ($request->validate(['limite' => 'nullable|integer|min:1|max:200'])['limite'] ?? 60);

        return response()->json(Corte::query()->orderByDesc('ejecutado_en')->orderByDesc('id')->limit($limite)->get()->map(fn ($c) => Vistas::corte($c))->values());
    }

    /**
     * «Ejecutar corte ahora». Sin `fecha_entrega` corta la primera fecha de servicio que todavía no se ha cortado.
     * Es idempotente: repetirlo no cambia nada y queda como «sin cambios».
     */
    public function ejecutar(Request $request, EjecutorDelCorte $ejecutor, CalendarioDeServicio $calendario): JsonResponse
    {
        $fecha = $request->validate(['fecha_entrega' => 'nullable|date_format:Y-m-d'])['fecha_entrega']
            ?? $this->primeraFechaSinCortar($calendario)->toDateString();

        $corte = $ejecutor->ejecutar($fecha, $request->user()->id);
        Bitacora::registrar($request->user(), 'corte.ejecutado', 'cortes', $corte->id,
            $corte->sin_cambios ? "Ejecutó el corte del {$fecha}: sin cambios (ya estaba cerrado)." : "Ejecutó el corte del {$fecha}: {$corte->estado}.");

        return response()->json([
            'corte' => Vistas::corte($corte), 'fecha_entrega' => $fecha, 'sin_cambios' => $corte->sin_cambios,
            'elecciones_cerradas' => $corte->elecciones_cerradas, 'empleados_por_defecto' => $corte->empleados_por_defecto,
            'ordenes_generadas' => $corte->ordenes_generadas, 'entregas_consolidadas' => $corte->entregas_consolidadas, 'rutas_creadas' => $corte->rutas_creadas,
            'advertencias' => $corte->mensaje ? [$corte->mensaje] : [],
        ]);
    }

    private function primeraFechaSinCortar(CalendarioDeServicio $calendario)
    {
        $fecha = $calendario->ahora()->startOfDay()->addDay();
        for ($i = 0; $i < 60; $i++, $fecha = $fecha->addDay()) {
            if ($calendario->esDiaDeServicio($fecha) && ! $calendario->corteYaEjecutado($fecha)) {
                return $fecha;
            }
        }

        return $calendario->ahora()->startOfDay()->addDay();
    }
}
