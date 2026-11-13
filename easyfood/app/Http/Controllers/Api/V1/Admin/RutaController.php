<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Ruta;
use App\Services\Bitacora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RutaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $fecha = $request->validate(['fecha' => 'required|date_format:Y-m-d'])['fecha'];

        return response()->json(
            Ruta::query()->with(['repartidor.usuario', 'paradas'])->whereDate('fecha', $fecha)->orderBy('id')->get()->map(fn ($r) => Vistas::ruta($r, true))->values()
        );
    }

    /** Aprueba todas las rutas en borrador de una fecha. Devuelve cuántas aprobó. */
    public function aprobar(Request $request): JsonResponse
    {
        $fecha = $request->validate(['fecha' => 'required|date_format:Y-m-d'])['fecha'];

        $aprobadas = Ruta::query()->whereDate('fecha', $fecha)->where('estado', 'borrador')->update(['estado' => 'aprobada', 'actualizado_en' => now()]);
        if ($aprobadas > 0) {
            Bitacora::registrar($request->user(), 'rutas.aprobadas', 'rutas', $fecha, "Aprobó {$aprobadas} ruta(s) del {$fecha}.");
        }

        return response()->json(['aprobadas' => $aprobadas, 'mensaje' => $aprobadas ? "Se aprobaron {$aprobadas} ruta(s)." : 'No había rutas en borrador para esa fecha.']);
    }
}
