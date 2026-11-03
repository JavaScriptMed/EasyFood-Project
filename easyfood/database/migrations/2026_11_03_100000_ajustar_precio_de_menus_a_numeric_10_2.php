<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CLAUDE.md pide montos en numeric(10,2) MXN; menus.price se creó como (8,2).
     * Es una migración nueva (y no un cambio a la original) para que también se aplique en las bases donde la original ya corrió.
     * providers no tiene columnas de dinero: latitude y longitude son coordenadas y conservan su precisión.
     */
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->decimal('price', 8, 2)->change();
        });
    }
};
