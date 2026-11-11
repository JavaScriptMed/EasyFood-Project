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
        Schema::create('ordenes_produccion', function (Blueprint $tabla) {
            $tabla->id();
            // Las genera el corte; pueden crearse a mano para un día sin corte.
            $tabla->foreignId('corte_id')->nullable()->constrained('cortes')->restrictOnDelete();
            // El comedor es un registro de providers (decisión del equipo).
            $tabla->foreignId('comedor_id')->constrained('providers')->restrictOnDelete();
            $tabla->foreignId('menu_id')->constrained('menus')->restrictOnDelete();
            $tabla->date('fecha');
            $tabla->integer('porciones_totales');
            $tabla->integer('porciones_empresas')->default(0);
            $tabla->integer('porciones_individuales')->default(0);
            $tabla->time('hora_recoleccion');
            $tabla->string('estado', 10)->default('pendiente');
            Esquema::marcasDeTiempo($tabla);

            // Una sola orden por comedor, platillo y día: volver a correr el corte no la duplica.
            $tabla->unique(['comedor_id', 'menu_id', 'fecha']);
            $tabla->index(['comedor_id', 'fecha']);
        });

        Esquema::enumerado('ordenes_produccion', 'estado', ['pendiente', 'lista']);
        Esquema::check('ordenes_produccion', 'porciones_no_negativas', 'porciones_totales >= 0 AND porciones_empresas >= 0 AND porciones_individuales >= 0');
        Esquema::check('ordenes_produccion', 'total_cuadra', 'porciones_totales = porciones_empresas + porciones_individuales');
        Esquema::sinBorrado('ordenes_produccion');
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_produccion');
    }
};
