<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade');
            // Descripción para identificar el domicilio en la UI (Casa, Trabajo)
            $table->string('label', 50)->default('Casa');
            $table->text('street_address');
            $table->string('internal_number', 20)->nullable();
            $table->string('postal_code', 10)->nullable();
            // Coordenadas geográficas OBLIGATORIAS (PENDIENTE REVISAR FORMATO DE ALMACENAMIENTO)
            // latitude: (20.676667)
            // longitude: (-103.347222)
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->text('delivery_instructions')->nullable();
            // Marca si esta dirección es la predeterminada para órdenes rápidas (PENDIENTE REVISAR SI SE IMPLEMENTARA)
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};