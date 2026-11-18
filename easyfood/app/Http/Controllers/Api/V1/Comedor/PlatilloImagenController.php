<?php

namespace App\Http\Controllers\Api\V1\Comedor;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Menu;
use App\Services\Bitacora;
use App\Services\ImagenesDePlatillos;
use App\Support\Api\ContextoDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatilloImagenController extends Controller
{
    /**
     * El comedor cambia la foto de uno de sus platillos. Igual que al editarlo, un platillo publicado o rechazado vuelve a revisión
     * para que el administrador vea la foto nueva antes de que llegue a los empleados.
     */
    public function store(Request $request, int $id, ImagenesDePlatillos $imagenes): JsonResponse
    {
        $request->validate(['imagen' => ImagenesDePlatillos::REGLAS]);
        $platillo = Menu::query()->where('provider_id', ContextoDelUsuario::comedor($request)->id)->findOrFail($id);

        $platillo = $imagenes->guardar($platillo, $request->file('imagen'));
        if (in_array($platillo->estado_revision, ['publicado', 'rechazado'], true)) {
            $platillo->update(['estado_revision' => 'en_revision', 'motivo_rechazo' => null]);
        }
        Bitacora::registrar($request->user(), 'platillo.imagen', 'platillos', $platillo->id, "Cambió la foto de «{$platillo->name}».");

        return response()->json(Vistas::platillo($platillo->refresh()->load('provider')));
    }
}
