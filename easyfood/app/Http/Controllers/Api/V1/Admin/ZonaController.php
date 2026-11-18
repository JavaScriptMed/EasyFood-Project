<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ZonasDeEntrega;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Zonas de entrega (polígonos). Solo el administrador. */
class ZonaController extends Controller
{
    public function index(ZonasDeEntrega $zonas): JsonResponse
    {
        return response()->json($zonas->lista());
    }

    public function show(int $id, ZonasDeEntrega $zonas): JsonResponse
    {
        return response()->json($zonas->detalle($id));
    }

    /** Crea una zona nueva. Si ya existe una con ese nombre responde 422 `zona_repetida`: para cambiar su polígono usa PUT. */
    public function store(Request $request, ZonasDeEntrega $zonas): JsonResponse
    {
        $d = $request->validate(['nombre' => 'required|string|max:100', 'geojson' => 'required|array']);
        $zona = $zonas->guardar($request->user(), $d['nombre'], $d['geojson'], soloSiNueva: true);

        return response()->json($zonas->detalle($zona['id']), 201);
    }

    /** Reemplaza el polígono de una zona. El nombre no cambia: ya está escrito en empresas, comedores, repartidores y direcciones. */
    public function update(Request $request, int $id, ZonasDeEntrega $zonas): JsonResponse
    {
        $d = $request->validate(['geojson' => 'required|array']);
        $actual = $zonas->detalle($id);
        $zonas->guardar($request->user(), $actual['nombre'], $d['geojson']);

        return response()->json($zonas->detalle($id));
    }

    /** Carga varias zonas de una vez desde un FeatureCollection (cada polígono con su `properties.nombre`). Todo o nada. */
    public function importar(Request $request, ZonasDeEntrega $zonas): JsonResponse
    {
        $request->validate(['type' => 'required|in:FeatureCollection', 'features' => 'required|array|min:1|max:200']);
        $resultado = $zonas->importar($request->user(), $request->only(['type', 'features']));

        return response()->json(['creadas' => count(array_filter($resultado, fn ($z) => $z['creada'])), 'actualizadas' => count(array_filter($resultado, fn ($z) => ! $z['creada'])), 'zonas' => $resultado], 201);
    }

    public function estado(Request $request, int $id, ZonasDeEntrega $zonas): JsonResponse
    {
        $activo = $request->validate(['activo' => 'required|boolean'])['activo'];

        return response()->json($zonas->cambiarEstado($request->user(), $id, $activo));
    }
}
