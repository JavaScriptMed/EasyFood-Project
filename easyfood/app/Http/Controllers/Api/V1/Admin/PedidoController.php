<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Pedido;
use App\Services\Pagos\ErrorDePasarela;
use App\Services\Pagos\PasarelaDePagos;
use App\Services\ReembolsosDePedidos;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PedidoController extends Controller
{
    /** Pedidos de clientes individuales, del más reciente al más antiguo. Filtros: estado y limite (100 por defecto). */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['estado' => 'nullable|in:pendiente_pago,recibido,activo,completado,cancelado', 'limite' => 'nullable|integer|min:1|max:500']);

        return response()->json(
            Pedido::query()->with(['cliente', 'paquete', 'direccion', 'dias.recepciones'])->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v))
                ->orderByDesc('id')->limit((int) ($f['limite'] ?? 100))->get()->map(fn ($p) => Vistas::pedido($p, true))->values()
        );
    }

    /**
     * Reembolso total de un pedido pagado con Stripe (administración y soporte). Reembolsa el cobro, cancela el pedido (queda
     * «cancelado» con reembolsado_en, monto_reembolsado y stripe_refund_id) y deja en la bitácora quién lo hizo.
     * No se reembolsa dos veces ni más de lo cobrado. Si Stripe falla responde 502 y el pedido no cambia.
     */
    public function reembolsar(Request $request, int $id, ReembolsosDePedidos $reembolsos, PasarelaDePagos $pasarela): JsonResponse
    {
        if (! $pasarela->configurada()) {
            return response()->json(['mensaje' => 'Los pagos con tarjeta todavía no están configurados.', 'codigo' => 'pagos_no_configurados'], 503);
        }

        try {
            $pedido = $reembolsos->reembolsar($id, $request->user());
        } catch (ErrorDePasarela $e) {
            return response()->json(['mensaje' => 'Stripe no pudo hacer el reembolso: '.$e->getMessage(), 'codigo' => 'pasarela_con_error'], 502);
        }

        return response()->json(Vistas::pedido($pedido->load(['cliente', 'paquete', 'direccion', 'dias.recepciones']), true));
    }
}
