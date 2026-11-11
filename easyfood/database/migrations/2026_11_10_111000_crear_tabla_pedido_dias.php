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
        // Cada día de entrega de un pedido individual, con su platillo y su evidencia.
        Schema::create('pedido_dias', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('pedido_id')->constrained('pedidos')->restrictOnDelete();
            $tabla->date('fecha');
            $tabla->foreignId('menu_id')->constrained('menus')->restrictOnDelete();
            $tabla->decimal('precio_congelado', 10, 2);
            $tabla->string('estado', 20)->default('programada');
            $tabla->string('evidencia_foto_url', 255)->nullable();
            $tabla->timestampTz('entregada_en')->nullable();
            Esquema::marcasDeTiempo($tabla);

            $tabla->unique(['pedido_id', 'fecha']);
            $tabla->index('fecha');
        });

        Esquema::enumerado('pedido_dias', 'estado', ['programada', 'en_ruta', 'entregada', 'no_localizado', 'cancelada']);
        Esquema::check('pedido_dias', 'precio_positivo', 'precio_congelado > 0');
        Esquema::check('pedido_dias', 'entrega_coherente', "(estado = 'entregada') = (entregada_en IS NOT NULL)");
        Esquema::sinBorrado('pedido_dias');
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_dias');
    }
};
