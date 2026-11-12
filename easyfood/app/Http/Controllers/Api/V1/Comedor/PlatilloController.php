<?php

namespace App\Http\Controllers\Api\V1\Comedor;

use App\Http\Controllers\Api\V1\Admin\PlatilloController as PlatilloAdmin;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Menu;
use App\Support\Api\ContextoDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatilloController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $comedor = ContextoDelUsuario::comedor($request);

        return response()->json(Menu::query()->with('provider')->where('provider_id', $comedor->id)->orderBy('id')->get()->map(fn ($m) => Vistas::platillo($m))->values());
    }

    /** Crea un platillo del propio comedor (borrador o en revisión). Publicarlo lo decide el administrador. */
    public function store(Request $request): JsonResponse
    {
        $comedor = ContextoDelUsuario::comedor($request);
        $datos = $request->validate(PlatilloAdmin::reglas(crear: true));

        $platillo = Menu::create(PlatilloAdmin::atributos($datos) + ['provider_id' => $comedor->id]);

        return response()->json(Vistas::platillo($platillo->refresh()->load('provider')), 201);
    }

    /**
     * Edita un platillo del propio comedor. Un platillo publicado o rechazado que se edita vuelve a revisión (o a borrador
     * si se pide), para que el administrador vea los cambios antes de que lleguen a los empleados.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $comedor = ContextoDelUsuario::comedor($request);
        $platillo = Menu::query()->where('provider_id', $comedor->id)->findOrFail($id);
        $datos = $request->validate(PlatilloAdmin::reglas(crear: false));

        $cambios = PlatilloAdmin::atributos($datos);
        if (in_array($platillo->estado_revision, ['publicado', 'rechazado'], true)) {
            $cambios['estado_revision'] = $datos['estado_revision'] ?? 'en_revision';
            $cambios['motivo_rechazo'] = null;
        }
        $platillo->update($cambios);

        return response()->json(Vistas::platillo($platillo->refresh()->load('provider')));
    }
}
