<?php

namespace App\Http\Controllers\Api\V1\Cliente;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Address;
use App\Models\Paquete;
use App\Models\Pedido;
use App\Services\CalendarioDeServicio;
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
     * Cuándo llega el próximo pedido de un cliente individual: el sábado de entrega (los pedidos de lunes a viernes entran al sábado
     * de esa semana, antes del viernes a las 18:00; después pasan al siguiente), cuándo cierra y los días de comida (hasta 6) de la semana siguiente
     * para los que hay que elegir platillos: son los de `GET /menu-semanal?fecha_inicio=` a partir del primero.
     */
    public function proximaEntrega(CalendarioDeServicio $calendario): JsonResponse
    {
        $entrega = $calendario->fechaDeEntregaDeIndividuales();

        return response()->json([
            'fecha_entrega' => $entrega->toDateString(),
            'cierra_en' => Vistas::iso($calendario->momentoDeCierreDeIndividuales($entrega)),
            'dias_de_comida' => array_map(fn ($f) => $f->toDateString(), $calendario->diasDeComidaDeUnPedido(6, $entrega)),
        ]);
    }

    /**
     * Pide un paquete de 1, 2 o 6 días con 1, 2 o 3 comidas por día. `platillos` trae un elemento por día, en orden, y cada uno es
     * un objeto `{desayuno: id, comida: id, cena: id}` con los tiempos de comida que el cliente eligió (todos los días, los mismos),
     * más `extra: id` si pide un platillo adicional por día (de cualquier tiempo de comida; también en todos los días).
     * TODO el pedido llega en una sola entrega, el sábado (`fecha_entrega`); los días de comida son los siguientes días de servicio
     * después de ese sábado (ver `proximaEntrega`). El pedido nace «pendiente de pago» y trae `vence_pago_en` (la hora de corte de ese
     * sábado) y `segundos_para_pagar`.
     */
    public function store(Request $request, ContratacionDePaquetes $paquetes): JsonResponse
    {
<<<<<<< HEAD
        // En app/Http/Controllers/Api/V1/Cliente/PedidoController.php
$datos = $request->validate([
    'paquete_id' => 'required|integer', 
    'direccion_id' => 'required|integer', 
    'platillos' => 'required|array|min:1|max:18', // Permitimos hasta 18 platillos (6 días * 3 tiempos)
    'platillos.*' => 'required|integer'
]);
=======
        $datos = $request->validate([
            'paquete_id' => 'required|integer', 'direccion_id' => 'required|integer',
            'platillos' => 'required|array|min:1|max:6', 'platillos.*' => 'required|array|min:1|max:4',
            'platillos.*.desayuno' => 'nullable|integer', 'platillos.*.comida' => 'nullable|integer', 'platillos.*.cena' => 'nullable|integer', 'platillos.*.extra' => 'nullable|integer',
        ]);
        // Solo cuentan los tiempos de comida que mandó el cliente (sin los vacíos).
        $datos['platillos'] = array_map(fn ($dia) => array_filter(array_intersect_key($dia, array_flip(['desayuno', 'comida', 'cena', 'extra'])), fn ($id) => $id !== null), $datos['platillos']);

>>>>>>> 9fad24d1953e7129b66efd69df6734b66f59f2e5
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
