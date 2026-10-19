<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Crea las tablas de cabecera y detalle para catálogos y datos estáticos configurables.
    public function up(): void
    {
        // TABLA: catalog_categories
        Schema::create('catalog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // TABLA: catalog_items
        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')
                  ->constrained('catalog_categories')
                  ->onDelete('cascade');
            // Identificador (ej. BREAKFAST, LUNCH, DINNER)
            // OJO: Valor almacenado en las peticiones y transacciones del backend
            $table->string('code', 50);
            $table->string('label', 150);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            // Asegura que no se repita el mismo 'code' dentro de una misma categoría
            $table->unique(['category_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('catalog_categories');
    }
};