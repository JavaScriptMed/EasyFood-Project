<?php

use App\Support\Database\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - pedidos.vence_pago_en: instante en que vence el pago de un pedido (el corte de su primer día de entrega).
 *   Si pasa sin pagarse, el pedido se cancela y hay que pedirlo de nuevo. Es obligatorio mientras está pendiente de pago.
 * - incidencias.pedido_dia_id: una entrega a cliente individual que quedó sin ruta se reporta contra su día de pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $tabla) {
            $tabla->timestampTz('vence_pago_en')->nullable();
        });
        Esquema::check('pedidos', 'vence_pago_si_pendiente', "estado <> 'pendiente_pago' OR vence_pago_en IS NOT NULL");

        Schema::table('incidencias', function (Blueprint $tabla) {
            $tabla->foreignId('pedido_dia_id')->nullable()->constrained('pedido_dias')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('incidencias', function (Blueprint $tabla) {
            $tabla->dropConstrainedForeignId('pedido_dia_id');
        });
        DB::unprepared('ALTER TABLE pedidos DROP CONSTRAINT IF EXISTS pedidos_vence_pago_si_pendiente');
        Schema::table('pedidos', function (Blueprint $tabla) {
            $tabla->dropColumn('vence_pago_en');
        });
    }
};
