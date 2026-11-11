<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Pedido;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ClienteController extends Controller
{
    public function index(): JsonResponse
    {
        $clientes = $this->clientes()->get();

        return response()->json($clientes->map(fn (User $u) => self::resumen($u))->sortByDesc('gasto_total')->values());
    }

    public function show(int $id): JsonResponse
    {
        $cliente = $this->clientes()->findOrFail($id);
        $pedidos = Pedido::query()->with(['cliente', 'paquete', 'direccion', 'dias'])->where('cliente_id', $id)->orderByDesc('id')->get();
        $tickets = Ticket::query()->with(['cliente', 'pedido'])->where('cliente_id', $id)->orderByDesc('actualizado_en')->get();

        return response()->json(self::resumen($cliente) + [
            'pedidos' => $pedidos->map(fn ($p) => Vistas::pedido($p))->values(), 'tickets' => $tickets->map(fn ($t) => Vistas::ticket($t))->values(),
        ]);
    }

    private function clientes()
    {
        $pedidos = fn ($tabla, $condicion = '1=1') => DB::table('pedidos')->whereColumn('pedidos.cliente_id', 'users.id')->whereRaw($condicion);

        return User::query()->whereHas('role', fn ($r) => $r->where('name', 'customer'))
            ->select('users.*')
            ->selectSub($pedidos('p')->selectRaw('count(*)'), 'pedidos_total')
            ->selectSub($pedidos('p', "estado in ('recibido','activo')")->selectRaw('count(*)'), 'pedidos_activos')
            ->selectSub($pedidos('p', "estado <> 'cancelado'")->selectRaw('coalesce(sum(total), 0)'), 'gasto_total')
            ->selectSub($pedidos('p')->selectRaw('max(fecha_inicio)'), 'ultimo_pedido')
            ->selectSub(DB::table('tickets')->whereColumn('tickets.cliente_id', 'users.id')->whereIn('estado', ['abierto', 'en_proceso'])->selectRaw('count(*)'), 'tickets_abiertos');
    }

    private static function resumen(User $u): array
    {
        return [
            'usuario_id' => $u->id, 'nombre' => $u->name, 'correo' => $u->email, 'activo' => $u->status === 'active',
            'pedidos_total' => (int) $u->pedidos_total, 'pedidos_activos' => (int) $u->pedidos_activos, 'gasto_total' => Vistas::dinero($u->gasto_total),
            'ultimo_pedido' => $u->ultimo_pedido, 'tickets_abiertos' => (int) $u->tickets_abiertos,
        ];
    }
}
