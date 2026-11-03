<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea la tabla de comedores industriales / proveedores.
     */
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->onDelete('set null');
            $table->string('business_name', 150); // Razón social o nombre del comedor
            $table->string('commercial_name', 150)->nullable(); // Nombre comercial/marca
            $table->string('rfc', 13)->nullable()->unique();
            $table->string('phone', 20);
            $table->string('email', 150)->nullable();
            $table->text('address');
            $table->string('postal_code', 10)->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->integer('daily_capacity')->default(100); // Límite de raciones/día
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};