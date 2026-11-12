<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\MensajeTicket;
use App\Models\Ticket;
use App\Services\Bitacora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $estado = $request->validate(['estado' => 'nullable|in:abierto,en_proceso,resuelto,cerrado'])['estado'] ?? null;

        return response()->json(
            Ticket::query()->with(['cliente', 'pedido'])->when($estado, fn ($q, $v) => $q->where('estado', $v))
                ->orderByDesc('actualizado_en')->get()->map(fn ($t) => Vistas::ticket($t))->values()
        );
    }

    public function show(int $id): JsonResponse
    {
        $ticket = Ticket::query()->with(['cliente', 'pedido.paquete', 'pedido.direccion', 'pedido.dias'])->findOrFail($id);
        $pedido = $ticket->pedido;

        return response()->json(Vistas::ticket($ticket) + [
            'mensajes' => MensajeTicket::query()->where('ticket_id', $id)->orderBy('enviado_en')->orderBy('id')->get()->map(fn ($m) => Vistas::mensajeTicket($m))->values(),
            'resumen_pedido' => $pedido ? [
                'plan' => $pedido->paquete?->nombre, 'proxima_entrega' => $pedido->dias->where('estado', 'programada')->sortBy('fecha')->first()?->fecha->toDateString(),
                'zona' => $pedido->direccion?->zona, 'total' => Vistas::dinero($pedido->total), 'direccion' => $pedido->direccion?->street_address,
                'referencias' => $pedido->direccion?->delivery_instructions,
            ] : null,
        ]);
    }

    /** Responde al cliente como agente. Si el ticket estaba abierto pasa a «en proceso» y se limpian sus mensajes sin leer. */
    public function mensaje(Request $request, int $id): JsonResponse
    {
        $texto = $request->validate(['texto' => 'required|string|max:2000'])['texto'];
        $ticket = Ticket::query()->findOrFail($id);

        $mensaje = MensajeTicket::create(['ticket_id' => $ticket->id, 'autor_id' => $request->user()->id, 'autor_tipo' => 'agente', 'texto' => $texto]);
        $ticket->update(['mensajes_sin_leer' => 0, 'estado' => $ticket->estado === 'abierto' ? 'en_proceso' : $ticket->estado]);
        Bitacora::registrar($request->user(), 'ticket.respondido', 'tickets', $ticket->id, "Respondió el ticket {$ticket->folio}.");

        return response()->json(Vistas::mensajeTicket($mensaje->refresh()), 201);
    }
}
