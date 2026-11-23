<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Incidencia;
use App\Services\CalendarioDeServicio;
use App\Services\IndicadoresDelDashboard;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * El dashboard del administrador, en tiempo real: el resumen del día, las alertas abiertas y los 10 indicadores
     * (platillos entregados, calificaciones, paquete popular, ahorro, quejas, contratos nuevos, utilidad, costo de maquila y facturación).
     */
    public function show(IndicadoresDelDashboard $indicadores, CalendarioDeServicio $calendario): JsonResponse
    {
        $alertas = Incidencia::query()->with(['ruta.repartidor.usuario'])->where('estado', '<>', 'resuelta')
            ->orderByRaw("case prioridad when 'inmediata' then 0 else 1 end")->orderByDesc('fecha')->orderByDesc('id')->get();

        return response()->json([
            'resumen' => $indicadores->resumen(),
            'alertas' => $alertas->map(fn ($i) => Vistas::incidencia($i))->values(),
            'incidencias_del_dia' => Incidencia::query()->whereDate('fecha', $calendario->ahora()->toDateString())->count(),
            'requieren_atencion' => $alertas->where('prioridad', 'inmediata')->count(),
            'indicadores' => $indicadores->paraAdministrador(),
        ]);
    }
}
