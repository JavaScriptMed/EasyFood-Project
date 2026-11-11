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
        // Pedidos de clientes individuales (app móvil). El pago es simulado: no hay cobro real.
        Schema::create('pedidos', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('folio', 30)->unique();
            $tabla->foreignId('cliente_id')->constrained('users')->restrictOnDelete();
            $tabla->foreignId('paquete_id')->constrained('paquetes')->restrictOnDelete();
            // Domicilio de entrega elegido (tabla addresses del equipo).
            $tabla->foreignId('direccion_id')->constrained('addresses')->restrictOnDelete();
            $tabla->date('fecha_inicio');
            $tabla->date('fecha_fin');
            $tabla->decimal('total', 10, 2);
            $tabla->string('estado', 20)->default('pendiente_pago');
            $tabla->string('metodo_pago', 30)->nullable();
            $tabla->char('ultimos_digitos', 4)->nullable();
            $tabla->timestampTz('pagado_en')->nullable();
            Esquema::marcasDeTiempo($tabla);

            $tabla->index(['cliente_id', 'estado']);
        });

        Esquema::enumerado('pedidos', 'estado', ['pendiente_pago', 'recibido', 'activo', 'completado', 'cancelado']);
        Esquema::check('pedidos', 'vigencia_valida', 'fecha_fin >= fecha_inicio');
        Esquema::check('pedidos', 'total_no_negativo', 'total >= 0');
        Esquema::check('pedidos', 'digitos_validos', "ultimos_digitos IS NULL OR ultimos_digitos ~ '^[0-9]{4}$'");
        Esquema::check('pedidos', 'pago_coherente', "(estado = 'pendiente_pago') = (pagado_en IS NULL) OR estado = 'cancelado'");
        Esquema::sinBorrado('pedidos');
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos');
    }
};
