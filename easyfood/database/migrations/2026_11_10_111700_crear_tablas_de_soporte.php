<?php

use App\Support\Database\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('folio', 20)->unique();
            $tabla->foreignId('cliente_id')->constrained('users')->restrictOnDelete();
            $tabla->foreignId('pedido_id')->nullable()->constrained('pedidos')->restrictOnDelete();
            $tabla->string('asunto', 200);
            $tabla->string('estado', 12)->default('abierto');
            $tabla->integer('mensajes_sin_leer')->default(0);
            Esquema::marcasDeTiempo($tabla);

            $tabla->index(['estado', 'actualizado_en']);
        });
        Esquema::enumerado('tickets', 'estado', ['abierto', 'en_proceso', 'resuelto', 'cerrado']);
        Esquema::check('tickets', 'sin_leer_no_negativo', 'mensajes_sin_leer >= 0');
        Esquema::sinBorrado('tickets');

        Schema::create('mensajes_ticket', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            // Quién lo escribió: el cliente o un agente de soporte (siempre una cuenta de users).
            $tabla->foreignId('autor_id')->constrained('users')->restrictOnDelete();
            $tabla->string('autor_tipo', 10);
            $tabla->text('texto');
            $tabla->timestampTz('enviado_en')->useCurrent();

            $tabla->index(['ticket_id', 'enviado_en']);
        });
        Esquema::enumerado('mensajes_ticket', 'autor_tipo', ['cliente', 'agente']);
        Esquema::check('mensajes_ticket', 'texto_con_contenido', 'length(btrim(texto)) > 0');
        Esquema::sinBorrado('mensajes_ticket');
    }

    public function down(): void
    {
        Schema::dropIfExists('mensajes_ticket');
        Schema::dropIfExists('tickets');
    }
};
