<?php

namespace App\Http\Controllers\Api\V1\Empleado;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\EleccionEmpleado;
use App\Services\Dinero;
use App\Services\EleccionDePlatillos;
use App\Support\Api\ContextoDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EleccionController extends Controller
{
    /** Historial propio, del más reciente al más antiguo (`limite`, 8 por defecto). */
    public function index(Request $request): JsonResponse
    {
        $limite = (int) ($request->validate(['limite' => 'nullable|integer|min:1|max:100'])['limite'] ?? 8);
        $empleado = ContextoDelUsuario::empleado($request);

        return response()->json(
            EleccionEmpleado::query()->with('menu')->where('empleado_id', $empleado->id)->orderByDesc('fecha')->orderByDesc('id')->limit($limite)->get()
                ->map(function (EleccionEmpleado $e) {
                    $precio = Dinero::centavos($e->precio_congelado);
                    $cubre = (int) round($precio * (float) $e->porcentaje_subsidio_congelado / 100);

                    return [
                        'id' => $e->id, 'fecha' => $e->fecha->toDateString(), 'tiempo_comida' => $e->tiempo_comida, 'platillo_nombre' => $e->menu->name,
                        'precio' => Vistas::dinero($e->precio_congelado), 'empresa_cubre' => Vistas::dinero(Dinero::formato($cubre)),
                        'empleado_paga' => Vistas::dinero(Dinero::formato($precio - $cubre)), 'origen' => $e->origen, 'cerrada' => $e->cerrada_en !== null,
                    ];
                })->values()
        );
    }

    /** Elige o cambia el platillo de un día y tiempo de comida (solo antes del corte). Congela precio y subsidio. */
    public function elegir(Request $request, EleccionDePlatillos $elecciones): JsonResponse
    {
        $datos = $request->validate(['fecha' => 'required|date_format:Y-m-d', 'tiempo_comida' => 'required|in:desayuno,comida,cena', 'platillo_id' => 'required|integer']);

        $eleccion = $elecciones->elegir(ContextoDelUsuario::empleado($request), $datos['fecha'], $datos['tiempo_comida'], $datos['platillo_id']);

        return response()->json(Vistas::eleccion($eleccion));
    }

    public function quitar(Request $request, EleccionDePlatillos $elecciones): JsonResponse
    {
        $datos = $request->validate(['fecha' => 'required|date_format:Y-m-d', 'tiempo_comida' => 'required|in:desayuno,comida,cena']);

        $elecciones->quitar(ContextoDelUsuario::empleado($request), $datos['fecha'], $datos['tiempo_comida']);

        return response()->json(['mensaje' => 'Listo: quitamos tu elección.']);
    }
}
