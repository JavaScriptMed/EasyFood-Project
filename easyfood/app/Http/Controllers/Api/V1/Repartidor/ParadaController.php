<?php

namespace App\Http\Controllers\Api\V1\Repartidor;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\VistaDelRepartidor;
use App\Services\EntregasDelRepartidor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParadaController extends Controller
{
    /** Inicia la siguiente parada. La primera de la ruta la pasa a «en_curso»; no deja saltarse el orden (422 `orden_de_paradas`). */
    public function iniciar(Request $request, int $id, EntregasDelRepartidor $entregas): JsonResponse
    {
        return response()->json(VistaDelRepartidor::resultado($entregas->iniciar($request->user(), $id)));
    }

    /** Confirma la entrega de una parada en camino con su foto de evidencia (`foto`, multipart). */
    public function entregar(Request $request, int $id, EntregasDelRepartidor $entregas): JsonResponse
    {
        $request->validate(['foto' => EntregasDelRepartidor::REGLAS_DE_FOTO]);

        return response()->json(VistaDelRepartidor::resultado($entregas->entregar($request->user(), $id, $request->file('foto'))));
    }

    /** Marca una parada en camino como no entregada, con su motivo; deja una incidencia abierta para el administrador. */
    public function noEntregada(Request $request, int $id, EntregasDelRepartidor $entregas): JsonResponse
    {
        $d = $request->validate(['motivo' => 'required|string|min:3|max:255']);

        return response()->json(VistaDelRepartidor::resultado($entregas->noEntregada($request->user(), $id, $d['motivo'])));
    }
}
