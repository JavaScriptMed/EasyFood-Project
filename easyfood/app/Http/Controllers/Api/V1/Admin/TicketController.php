<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\MensajeTicket;
use App\Models\Ticket;
use App\Services\AdjuntosDeTickets;
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

    /**
     * Cambia el estado de un ticket: marcarlo resuelto, cerrarlo o reabrirlo (abierto / en proceso). Queda en la bitácora con quién lo hizo.
     * Repetir el mismo estado no hace nada. Un mensaje nuevo del agente sigue pasando un ticket abierto a «en proceso».
     */
    public function estado(Request $request, int $id): JsonResponse
    {
        $estado = $request->validate(['estado' => 'required|in:abierto,en_proceso,resuelto,cerrado'])['estado'];
        $ticket = Ticket::query()->findOrFail($id);

        if ($ticket->estado !== $estado) {
            $antes = $ticket->estado;
            $ticket->update(['estado' => $estado]);
            Bitacora::registrar($request->user(), 'ticket.estado', 'tickets', $ticket->id, "Cambió el ticket {$ticket->folio} de «{$antes}» a «{$estado}».");
        }

        return response()->json(Vistas::ticket($ticket->refresh()->load(['cliente', 'pedido'])));
    }

    /**
     * Responde al cliente como agente, con texto, con un archivo (foto o PDF de hasta 5 MB, campo `adjunto`) o con ambos.
     * Si el ticket estaba abierto pasa a «en proceso» y se limpian sus mensajes sin leer.
     */
    public function mensaje(Request $request, int $id, AdjuntosDeTickets $adjuntos): JsonResponse
    {
        $d = $request->validate([
            'texto' => 'required_without:adjunto|nullable|string|max:2000',
            'adjunto' => ['nullable', ...AdjuntosDeTickets::REGLAS],
        ]);
        $ticket = Ticket::query()->findOrFail($id);

        $mensaje = MensajeTicket::create([
            'ticket_id' => $ticket->id, 'autor_id' => $request->user()->id, 'autor_tipo' => 'agente', 'texto' => trim((string) ($d['texto'] ?? '')),
        ] + ($request->hasFile('adjunto') ? $adjuntos->guardar($request->file('adjunto')) : []));
        $ticket->update(['mensajes_sin_leer' => 0, 'estado' => $ticket->estado === 'abierto' ? 'en_proceso' : $ticket->estado]);
        Bitacora::registrar($request->user(), 'ticket.respondido', 'tickets', $ticket->id, "Respondió el ticket {$ticket->folio}".($mensaje->adjunto_ruta ? ' con un archivo adjunto.' : '.'));

        return response()->json(Vistas::mensajeTicket($mensaje->refresh()), 201);
    }
}
