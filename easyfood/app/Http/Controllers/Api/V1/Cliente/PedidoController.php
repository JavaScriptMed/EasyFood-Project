<?php

namespace App\Http\Controllers\Api\V1\Cliente;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Address;
use App\Models\Paquete;
use App\Models\Pedido;
use App\Services\ContratacionDePaquetes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PedidoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->propios($request)->orderByDesc('id')->get()->map(fn ($p) => Vistas::pedido($p))->values()
        );
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(Vistas::pedido($this->propios($request)->findOrFail($id)));
    }

    /**
     * Pide un paquete de 1, 2 o 6 días con 1, 2 o 3 comidas por día. `platillos` trae un elemento por día, en orden, y cada uno es
     * un objeto `{desayuno: id, comida: id, cena: id}` con los tiempos de comida que el cliente eligió (todos los días, los mismos),
     * más `extra: id` si pide un platillo adicional por día (de cualquier tiempo de comida; también en todos los días).
     * Las fechas son las siguientes fechas de servicio con corte abierto.
     * El pedido nace «pendiente de pago» y trae `vence_pago_en` y `segundos_para_pagar`.
     */
    public function store(Request $request, ContratacionDePaquetes $paquetes): JsonResponse
    {
        $datos = $request->validate([
            'paquete_id' => 'required|integer', 'direccion_id' => 'required|integer',
            'platillos' => 'required|array|min:1|max:6', 'platillos.*' => 'required|array|min:1|max:4',
            'platillos.*.desayuno' => 'nullable|integer', 'platillos.*.comida' => 'nullable|integer', 'platillos.*.cena' => 'nullable|integer', 'platillos.*.extra' => 'nullable|integer',
        ]);
        // Solo cuentan los tiempos de comida que mandó el cliente (sin los vacíos).
        $datos['platillos'] = array_map(fn ($dia) => array_filter(array_intersect_key($dia, array_flip(['desayuno', 'comida', 'cena', 'extra'])), fn ($id) => $id !== null), $datos['platillos']);

        $paquete = Paquete::query()->findOrFail($datos['paquete_id']);
        $direccion = Address::query()->where('user_id', $request->user()->id)->findOrFail($datos['direccion_id']);
        $pedido = $paquetes->pedir($request->user(), $paquete, $direccion, $datos['platillos']);

        return response()->json(Vistas::pedido($pedido->load(['cliente', 'paquete', 'direccion', 'dias.platillos.menu'])), 201);
    }

    private function propios(Request $request)
    {
        return Pedido::query()->with(['cliente', 'paquete', 'direccion', 'dias.platillos.menu'])->where('cliente_id', $request->user()->id);
    }
}
