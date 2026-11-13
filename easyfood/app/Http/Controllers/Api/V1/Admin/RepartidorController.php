<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ReglaDeNegocioException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Repartidor;
use App\Services\Bitacora;
use App\Services\CalendarioDeServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RepartidorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $estado = $request->validate(['estado' => 'nullable|in:libre,asignado,en_ruta,no_disponible'])['estado'] ?? null;

        return response()->json(
            Repartidor::query()->activos()->with('usuario')->when($estado, fn ($q) => $q->where('estado', $estado))->orderBy('id')->get()->map(fn ($r) => Vistas::repartidor($r))->values()
        );
    }

    /** Solo hay autos: cuántas entregas y cuántos platillos caben en uno (configuración). */
    public function capacidades(CalendarioDeServicio $calendario): JsonResponse
    {
        $capacidad = $calendario->capacidadDelAuto();

        return response()->json(['automovil' => $capacidad['entregas'], 'entregas' => $capacidad['entregas'], 'platillos' => $capacidad['platillos']]);
    }

    /** Alterna entre «libre» y «no disponible». Con una ruta asignada o en curso no se puede apagar. */
    public function disponibilidad(Request $request, int $id): JsonResponse
    {
        $repartidor = Repartidor::query()->activos()->with('usuario')->findOrFail($id);

        if (in_array($repartidor->estado, ['asignado', 'en_ruta'], true)) {
            throw new ReglaDeNegocioException('repartidor_ocupado', 'Ese repartidor tiene una ruta asignada o en curso: no se puede cambiar su disponibilidad.');
        }

        $nuevo = $repartidor->estado === 'libre' ? 'no_disponible' : 'libre';
        $repartidor->update(['estado' => $nuevo]);
        Bitacora::registrar($request->user(), 'repartidor.disponibilidad', 'repartidores', $repartidor->id, "{$repartidor->usuario?->name} pasó a «{$nuevo}».");

        return response()->json(Vistas::repartidor($repartidor->refresh()->load('usuario')));
    }
}
