<?php

namespace Tests\Feature\Api\V1;

use App\Models\RegistroAuditoria;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\TestCase;

/** Soporte y el administrador marcan un ticket como resuelto, lo cierran o lo reabren. */
class TicketsEstadoTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;

    private function ticket(): Ticket
    {
        $cliente = $this->usuarioDeRol('customer', ['name' => 'Clara Cliente']);

        return Ticket::create(['folio' => 'T-9', 'cliente_id' => $cliente->id, 'asunto' => 'No llegó', 'mensajes_sin_leer' => 1]);
    }

    public function test_el_agente_cierra_un_ticket_y_lo_reabre(): void
    {
        $agente = $this->comoRol('support');
        $ticket = $this->ticket();

        $this->patchJson("/api/v1/admin/tickets/{$ticket->id}/estado", ['estado' => 'cerrado'])->assertOk()
            ->assertJsonPath('estado', 'cerrado')->assertJsonPath('cliente_nombre', 'Clara Cliente')->assertJsonPath('folio', 'T-9');
        $this->assertSame('cerrado', $ticket->fresh()->estado);

        $this->patchJson("/api/v1/admin/tickets/{$ticket->id}/estado", ['estado' => 'en_proceso'])->assertOk()->assertJsonPath('estado', 'en_proceso');
        $this->assertSame(['ticket.estado', 'ticket.estado'], RegistroAuditoria::where('entidad', 'tickets')->orderBy('id')->pluck('accion')->all());
        $this->assertSame($agente->id, RegistroAuditoria::where('accion', 'ticket.estado')->value('usuario_id'), 'queda quién lo hizo');
    }

    public function test_repetir_el_mismo_estado_no_deja_otra_entrada_en_la_bitacora(): void
    {
        $this->comoRol('admin');
        $ticket = $this->ticket();

        $this->patchJson("/api/v1/admin/tickets/{$ticket->id}/estado", ['estado' => 'resuelto'])->assertOk();
        $this->patchJson("/api/v1/admin/tickets/{$ticket->id}/estado", ['estado' => 'resuelto'])->assertOk()->assertJsonPath('estado', 'resuelto');

        $this->assertSame(1, RegistroAuditoria::where('accion', 'ticket.estado')->count());
    }

    public function test_rechaza_un_estado_que_no_existe_y_un_ticket_que_no_existe(): void
    {
        $this->comoRol('support');
        $ticket = $this->ticket();

        $this->patchJson("/api/v1/admin/tickets/{$ticket->id}/estado", ['estado' => 'archivado'])->assertStatus(422)->assertJsonStructure(['errores' => ['estado']]);
        $this->patchJson("/api/v1/admin/tickets/{$ticket->id}/estado", [])->assertStatus(422);
        $this->patchJson('/api/v1/admin/tickets/99999/estado', ['estado' => 'cerrado'])->assertStatus(404)->assertJsonPath('codigo', 'no_encontrado');
        $this->assertSame('abierto', $ticket->fresh()->estado);
    }

    public function test_solo_administracion_y_soporte_cambian_el_estado(): void
    {
        $ticket = $this->ticket();

        foreach (['customer', 'company', 'provider', 'employee', 'driver'] as $rol) {
            $this->comoRol($rol);
            $this->patchJson("/api/v1/admin/tickets/{$ticket->id}/estado", ['estado' => 'cerrado'])->assertStatus(403);
        }
        $this->assertSame('abierto', $ticket->fresh()->estado);
    }
}
