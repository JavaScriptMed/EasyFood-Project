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
        Schema::create('paradas', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('ruta_id')->constrained('rutas')->restrictOnDelete();
            $tabla->smallInteger('orden');
            // Una parada es la entrega a una empresa O la de un cliente individual, nunca las dos.
            $tabla->foreignId('entrega_empresa_id')->nullable()->constrained('entregas_empresa')->restrictOnDelete();
            $tabla->foreignId('pedido_dia_id')->nullable()->constrained('pedido_dias')->restrictOnDelete();
            $tabla->string('nombre_destinatario', 150);
            $tabla->string('direccion', 255);
            $tabla->time('hora_estimada');
            $tabla->string('estado', 15)->default('pendiente');
            Esquema::marcasDeTiempo($tabla);

            $tabla->unique(['ruta_id', 'orden']);
        });

        DB::unprepared('ALTER TABLE paradas ADD COLUMN ubicacion geography(Point, 4326)');
        DB::unprepared('CREATE INDEX paradas_ubicacion_gix ON paradas USING GIST (ubicacion)');
        // Una entrega no puede estar en dos paradas.
        DB::unprepared('CREATE UNIQUE INDEX paradas_entrega_empresa_unica ON paradas (entrega_empresa_id) WHERE entrega_empresa_id IS NOT NULL');
        DB::unprepared('CREATE UNIQUE INDEX paradas_pedido_dia_unico ON paradas (pedido_dia_id) WHERE pedido_dia_id IS NOT NULL');
        Esquema::enumerado('paradas', 'estado', ['pendiente', 'entregado', 'no_localizado']);
        Esquema::check('paradas', 'orden_positivo', 'orden >= 1');
        Esquema::check('paradas', 'un_solo_destino', 'num_nonnulls(entrega_empresa_id, pedido_dia_id) = 1');
        Esquema::sinBorrado('paradas');
    }

    public function down(): void
    {
        Schema::dropIfExists('paradas');
    }
};
