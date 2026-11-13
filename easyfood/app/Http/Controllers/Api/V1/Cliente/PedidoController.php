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
     * Pide un paquete: un platillo por día. Las fechas son las siguientes fechas de servicio con corte abierto.
     * El pedido nace «pendiente de pago» y trae `vence_pago_en` y `segundos_para_pagar`.
     */
    public function store(Request $request, ContratacionDePaquetes $paquetes): JsonResponse
    {
        $datos = $request->validate(['paquete_id' => 'required|integer', 'direccion_id' => 'required|integer', 'platillos' => 'required|array|min:1|max:6', 'platillos.*' => 'required|integer']);

        $paquete = Paquete::query()->findOrFail($datos['paquete_id']);
        $direccion = Address::query()->where('user_id', $request->user()->id)->findOrFail($datos['direccion_id']);
        $pedido = $paquetes->pedir($request->user(), $paquete, $direccion, $datos['platillos']);

        return response()->json(Vistas::pedido($pedido->load(['cliente', 'paquete', 'direccion', 'dias.menu'])), 201);
    }

    /** Pago simulado: no cobra nada, solo registra método y últimos 4 dígitos. Pasado el corte el pedido se cancela. */
    public function pagar(Request $request, int $id, ContratacionDePaquetes $paquetes): JsonResponse
    {
        $datos = $request->validate(['metodo_pago' => 'required|string|max:30', 'ultimos_digitos' => ['nullable', 'regex:/^[0-9]{4}$/']]);
        $pedido = $this->propios($request)->findOrFail($id);

        $pagado = $paquetes->pagar($pedido, $datos['metodo_pago'], $datos['ultimos_digitos'] ?? null);

        return response()->json(Vistas::pedido($pagado->load(['cliente', 'paquete', 'direccion', 'dias.menu'])));
    }

    private function propios(Request $request)
    {
        return Pedido::query()->with(['cliente', 'paquete', 'direccion', 'dias.menu'])->where('cliente_id', $request->user()->id);
    }
}
