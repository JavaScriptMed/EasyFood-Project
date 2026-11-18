<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Menu;
use App\Services\Bitacora;
use App\Services\HistorialDePrecios;
use App\Services\ImagenesDePlatillos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** La foto y el historial de precios de un platillo (administración). */
class PlatilloExtrasController extends Controller
{
    /** Sube o cambia la foto del platillo (campo `imagen`: JPG, PNG o WebP de hasta 2 MB). */
    public function imagen(Request $request, int $id, ImagenesDePlatillos $imagenes): JsonResponse
    {
        $request->validate(['imagen' => ImagenesDePlatillos::REGLAS]);
        $platillo = $imagenes->guardar(Menu::query()->findOrFail($id), $request->file('imagen'));
        Bitacora::registrar($request->user(), 'platillo.imagen', 'platillos', $platillo->id, "Cambió la foto de «{$platillo->name}».");

        return response()->json(Vistas::platillo($platillo->load('provider')));
    }

    public function historial(int $id, HistorialDePrecios $historial): JsonResponse
    {
        return response()->json($historial->de(Menu::query()->findOrFail($id))->map(fn ($p) => Vistas::precioDePlatillo($p))->values());
    }

    /** Programa un precio de venta nuevo a partir de mañana o de una fecha futura. */
    public function programarPrecio(Request $request, int $id, HistorialDePrecios $historial): JsonResponse
    {
        $d = $request->validate(['precio_venta' => 'required|numeric|gt:0|max:99999999', 'vigente_desde' => 'required|date_format:Y-m-d']);

        return response()->json(Vistas::precioDePlatillo($historial->programar($request->user(), Menu::query()->findOrFail($id), (float) $d['precio_venta'], $d['vigente_desde'])), 201);
    }
}
