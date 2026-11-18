<?php

namespace Tests\Feature\Api\V1;

use App\Models\MensajeTicket;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** Responder un ticket con una foto o un PDF (el clip del chat de soporte). */
class TicketsConAdjuntosTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $cliente = $this->usuarioDeRol('customer');
        $this->ticket = Ticket::create(['folio' => 'T-9', 'cliente_id' => $cliente->id, 'asunto' => 'No llegó', 'mensajes_sin_leer' => 1]);
    }

    private function foto(string $nombre = 'evidencia.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    }

    private function pdf(string $nombre = 'comprobante.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    private function url(): string
    {
        return "/api/v1/admin/tickets/{$this->ticket->id}/mensajes";
    }

    public function test_el_agente_responde_con_una_foto_y_texto(): void
    {
        $this->comoRol('support');

        $r = $this->postJson($this->url(), ['texto' => 'Mira cómo quedó', 'adjunto' => $this->foto()])->assertCreated()->json();

        $this->assertSame(['Mira cómo quedó', 'evidencia.png', 'image/png'], [$r['texto'], $r['adjunto']['nombre'], $r['adjunto']['tipo']]);
        $this->assertStringContainsString('/storage/tickets/', $r['adjunto']['url']);
        $archivo = MensajeTicket::sole()->adjunto_ruta;
        Storage::disk('public')->assertExists($archivo);
        $this->assertSame(52, strlen($archivo), 'tickets/ + 40 caracteres aleatorios + .png: nada del nombre original');
        $this->assertSame(['en_proceso', 0], [$this->ticket->fresh()->estado, $this->ticket->fresh()->mensajes_sin_leer]);
    }

    public function test_puede_ir_solo_el_archivo_sin_texto_y_el_detalle_del_ticket_lo_muestra(): void
    {
        $this->comoRol('support');

        $this->postJson($this->url(), ['adjunto' => $this->pdf()])->assertCreated()->assertJsonPath('texto', '')->assertJsonPath('adjunto.tipo', 'application/pdf')->assertJsonPath('adjunto.nombre', 'comprobante.pdf');

        $mensajes = $this->getJson("/api/v1/admin/tickets/{$this->ticket->id}")->assertOk()->json('mensajes');
        $this->assertSame('comprobante.pdf', $mensajes[0]['adjunto']['nombre']);
    }

    public function test_un_mensaje_de_solo_texto_no_trae_adjunto(): void
    {
        $this->comoRol('support');

        $this->postJson($this->url(), ['texto' => 'Lo revisamos'])->assertCreated()->assertJsonPath('adjunto', null);
    }

    public function test_sin_texto_ni_archivo_se_rechaza(): void
    {
        $this->comoRol('support');

        $this->postJson($this->url(), [])->assertStatus(422)->assertJsonStructure(['errores' => ['texto']]);
        $this->postJson($this->url(), ['texto' => '   '])->assertStatus(422);
        $this->assertSame(0, MensajeTicket::count());
    }

    public function test_rechaza_archivos_peligrosos_o_demasiado_grandes(): void
    {
        $this->comoRol('support');

        $this->postJson($this->url(), ['adjunto' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertStatus(422)->assertJsonStructure(['errores' => ['adjunto']]);
        $this->postJson($this->url(), ['adjunto' => UploadedFile::fake()->createWithContent('x.php', '<?php echo 1;')])->assertStatus(422);
        $this->postJson($this->url(), ['adjunto' => UploadedFile::fake()->createWithContent('doble.pdf.exe', 'MZ binario')])->assertStatus(422);
        $this->postJson($this->url(), ['adjunto' => UploadedFile::fake()->create('enorme.pdf', 6000, 'application/pdf')])->assertStatus(422)->assertJsonStructure(['errores' => ['adjunto']]);

        $this->assertSame(0, MensajeTicket::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_el_nombre_que_se_muestra_se_limpia_de_rutas_y_caracteres_raros(): void
    {
        $this->comoRol('support');

        $this->postJson($this->url(), ['adjunto' => $this->foto('../../etc/<foto> del pedido?.png')])->assertCreated()->assertJsonPath('adjunto.nombre', 'foto del pedido.png');
    }

    public function test_el_ticket_no_existe_y_el_cliente_no_responde_como_agente(): void
    {
        $this->comoRol('support');
        $this->postJson('/api/v1/admin/tickets/999999/mensajes', ['adjunto' => $this->foto()])->assertStatus(404);

        $this->comoRol('customer');
        $this->postJson($this->url(), ['adjunto' => $this->foto()])->assertStatus(403);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_la_base_acepta_texto_vacio_solo_si_hay_archivo(): void
    {
        $base = ['ticket_id' => $this->ticket->id, 'autor_id' => $this->ticket->cliente_id, 'autor_tipo' => 'cliente'];

        $this->rechaza('mensajes_ticket_texto_con_contenido', fn () => MensajeTicket::create($base + ['texto' => '  ']));
        $this->rechaza('mensajes_ticket_adjunto_completo', fn () => MensajeTicket::create($base + ['texto' => 'hola', 'adjunto_ruta' => 'tickets/a.png']));
        MensajeTicket::create($base + ['texto' => '', 'adjunto_ruta' => 'tickets/a.png', 'adjunto_nombre' => 'a.png', 'adjunto_tipo' => 'image/png']);
        $this->assertSame(1, MensajeTicket::count());
    }
}
