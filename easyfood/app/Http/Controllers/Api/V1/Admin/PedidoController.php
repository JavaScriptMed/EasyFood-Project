<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Pedido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PedidoController extends Controller
{
    /** Pedidos de clientes individuales, del más reciente al más antiguo. Filtros: estado y limite (100 por defecto). */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['estado' => 'nullable|in:pendiente_pago,recibido,activo,completado,cancelado', 'limite' => 'nullable|integer|min:1|max:500']);

        return response()->json(
            Pedido::query()->with(['cliente', 'paquete', 'direccion', 'dias'])->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
                ->orderByDesc('id')->limit((int) ($f['limite'] ?? 100))->get()->map(fn ($p) => Vistas::pedido($p))->values()
        );
    }
}
