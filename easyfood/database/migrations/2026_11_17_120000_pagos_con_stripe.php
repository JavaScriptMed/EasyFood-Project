<?php

use App\Support\Database\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pagos de pedidos individuales con Stripe (modo de prueba).
     * - pedidos.stripe_payment_intent_id: el intento de pago que se le dio al cliente (único: un intento, un pedido).
     * - stripe_eventos: cada evento del webhook se procesa una sola vez (stripe_event_id único), aunque Stripe lo reenvíe.
     * Nunca se guardan datos de tarjeta: del cargo solo quedan los últimos 4 dígitos (pedidos.ultimos_digitos).
     */
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $tabla) {
            $tabla->string('stripe_payment_intent_id', 255)->nullable();
        });
        DB::unprepared('CREATE UNIQUE INDEX pedidos_stripe_payment_intent_unico ON pedidos (stripe_payment_intent_id) WHERE stripe_payment_intent_id IS NOT NULL');

        Schema::create('stripe_eventos', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('stripe_event_id', 255)->unique();
            $tabla->string('tipo', 100);
            $tabla->foreignId('pedido_id')->nullable()->constrained('pedidos')->restrictOnDelete();
            $tabla->string('resultado', 30);
            Esquema::marcasDeTiempo($tabla);
        });
        Esquema::enumerado('stripe_eventos', 'resultado', ['pagado', 'ignorado', 'fallo_de_pago', 'pedido_no_pagable', 'monto_distinto']);
        Esquema::sinBorrado('stripe_eventos');
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_eventos');
        DB::unprepared('DROP INDEX IF EXISTS pedidos_stripe_payment_intent_unico');
        Schema::table('pedidos', function (Blueprint $tabla) {
            $tabla->dropColumn('stripe_payment_intent_id');
        });
    }
};
