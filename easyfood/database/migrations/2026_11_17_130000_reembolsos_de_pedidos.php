<?php

use App\Support\Database\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reembolso total de un pedido pagado con Stripe. No hay estado nuevo: un pedido reembolsado queda «cancelado» y estos tres
     * campos dicen cuándo, cuánto y con qué reembolso de Stripe. Solo los llevan pedidos que alguna vez se pagaron.
     */
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $tabla) {
            $tabla->timestampTz('reembolsado_en')->nullable();
            $tabla->decimal('monto_reembolsado', 10, 2)->nullable();
            $tabla->string('stripe_refund_id', 255)->nullable();
        });
        Esquema::check('pedidos', 'reembolso_completo', '(reembolsado_en IS NULL) = (stripe_refund_id IS NULL) AND (reembolsado_en IS NULL) = (monto_reembolsado IS NULL)');
        Esquema::check('pedidos', 'reembolso_positivo', 'monto_reembolsado IS NULL OR monto_reembolsado > 0');
        // Nunca se reembolsa más de lo cobrado (el cobro es el total del pedido).
        Esquema::check('pedidos', 'reembolso_no_excede_total', 'monto_reembolsado IS NULL OR monto_reembolsado <= total');
        Esquema::check('pedidos', 'reembolso_solo_si_cancelado_y_pagado', "reembolsado_en IS NULL OR (estado = 'cancelado' AND pagado_en IS NOT NULL)");
        DB::unprepared('CREATE UNIQUE INDEX pedidos_stripe_refund_unico ON pedidos (stripe_refund_id) WHERE stripe_refund_id IS NOT NULL');

        // Lo que puede resultar de procesar un evento de Stripe, ahora también con reembolsos.
        DB::unprepared('ALTER TABLE stripe_eventos DROP CONSTRAINT stripe_eventos_resultado_valido');
        Esquema::enumerado('stripe_eventos', 'resultado', [
            'pagado', 'ignorado', 'fallo_de_pago', 'pedido_no_pagable', 'monto_distinto', 'reembolso_automatico', 'reembolso_fallido', 'reembolsado',
        ]);
    }

    public function down(): void
    {
        DB::unprepared("UPDATE stripe_eventos SET resultado = 'ignorado' WHERE resultado IN ('reembolso_automatico', 'reembolso_fallido', 'reembolsado')");
        DB::unprepared('ALTER TABLE stripe_eventos DROP CONSTRAINT stripe_eventos_resultado_valido');
        Esquema::enumerado('stripe_eventos', 'resultado', ['pagado', 'ignorado', 'fallo_de_pago', 'pedido_no_pagable', 'monto_distinto']);

        DB::unprepared('DROP INDEX IF EXISTS pedidos_stripe_refund_unico');
        DB::unprepared('ALTER TABLE pedidos DROP CONSTRAINT IF EXISTS pedidos_reembolso_completo, DROP CONSTRAINT IF EXISTS pedidos_reembolso_positivo, DROP CONSTRAINT IF EXISTS pedidos_reembolso_no_excede_total, DROP CONSTRAINT IF EXISTS pedidos_reembolso_solo_si_cancelado_y_pagado');
        Schema::table('pedidos', function (Blueprint $tabla) {
            $tabla->dropColumn(['reembolsado_en', 'monto_reembolsado', 'stripe_refund_id']);
        });
    }
};
