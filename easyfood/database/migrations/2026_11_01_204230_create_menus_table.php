<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')
                  ->constrained('providers')
                  ->onDelete('cascade');
            // Relación con categoría (ej. Desayuno, Comida corrida, Fit, Vegano)
            $table->foreignId('category_id')
                  ->nullable()
                  ->constrained('catalog_categories')
                  ->onDelete('set null');
            // Datos del platillo/menú
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->decimal('price', 8, 2); // Precio unitario o por paquete
            $table->string('image_url')->nullable();
            // Disponibilidad y control operativo
            $table->integer('daily_stock')->default(50); // Raciones máximas por día
            $table->boolean('is_available')->default(true);
            // Días en que aplica (ej. ['monday', 'tuesday', ...] o null para todos)
            $table->json('available_days')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};