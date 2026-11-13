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
        Schema::create('disponibilidad_dia', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('menu_id')->constrained('menus')->restrictOnDelete();
            $tabla->date('fecha');
            $tabla->integer('porciones_maximas');
            // Cuántas porciones llevan elegidas. Puede quedar por encima del máximo (el comedor lo bajó después): es una alerta, no un error.
            $tabla->integer('porciones_tomadas')->default(0);
            Esquema::marcasDeTiempo($tabla);

            // Un platillo tiene una sola disponibilidad por día.
            $tabla->unique(['menu_id', 'fecha']);
            $tabla->index('fecha');
        });

        Esquema::check('disponibilidad_dia', 'porciones_maximas_no_negativas', 'porciones_maximas >= 0');
        Esquema::check('disponibilidad_dia', 'porciones_tomadas_no_negativas', 'porciones_tomadas >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('disponibilidad_dia');
    }
};
