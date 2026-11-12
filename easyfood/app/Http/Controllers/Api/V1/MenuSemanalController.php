<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DisponibilidadDia;
use App\Services\CalendarioDeServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuSemanalController extends Controller
{
    /**
     * El menú de 6 días desde `fecha_inicio`: por día y tiempo de comida, un platillo publicado con porciones.
     * Lo usan la app móvil (clientes) y el panel de administración.
     */
    public function __invoke(Request $request, CalendarioDeServicio $calendario): JsonResponse
    {
        $inicio = $calendario->dia($request->validate(['fecha_inicio' => 'required|date_format:Y-m-d'])['fecha_inicio']);

        $dias = [];
        for ($i = 0; $i < 6; $i++) {
            $fecha = $inicio->addDays($i);
            $celdas = [];
            $disponibles = DisponibilidadDia::query()->with('menu')->whereDate('fecha', $fecha->toDateString())
                ->whereHas('menu', fn ($q) => $q->where('estado_revision', 'publicado')->where('is_available', true))->orderBy('menu_id')->get();
            foreach ($disponibles as $d) {
                $celdas[$d->menu->tiempo_comida] ??= [
                    'platillo_id' => $d->menu_id, 'platillo_nombre' => $d->menu->name, 'precio_venta' => round((float) $d->menu->price, 2), 'porciones_maximas' => $d->porciones_maximas,
                ];
            }
            $dias[] = ['fecha' => $fecha->toDateString(), 'celdas' => (object) $celdas];
        }

        return response()->json($dias);
    }
}
