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
        Schema::create('entregas_empresa', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('corte_id')->nullable()->constrained('cortes')->restrictOnDelete();
            $tabla->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $tabla->foreignId('ruta_id')->nullable()->constrained('rutas')->restrictOnDelete();
            $tabla->date('fecha');
            $tabla->integer('platillos');
            $tabla->time('ventana_inicio');
            $tabla->time('ventana_fin');
            $tabla->string('estado', 20)->default('programada');
            Esquema::marcasDeTiempo($tabla);

            // Una sola entrega por empresa y día (un solo punto de entrega).
            $tabla->unique(['empresa_id', 'fecha']);
            $tabla->index(['fecha', 'ruta_id']);
        });

        Esquema::enumerado('entregas_empresa', 'estado', ['programada', 'en_consolidacion', 'en_ruta', 'entregada', 'no_localizado', 'reprogramada', 'cancelada']);
        Esquema::check('entregas_empresa', 'platillos_no_negativos', 'platillos >= 0');
        Esquema::check('entregas_empresa', 'ventana_valida', 'ventana_fin > ventana_inicio');
        Esquema::sinBorrado('entregas_empresa');
    }

    public function down(): void
    {
        Schema::dropIfExists('entregas_empresa');
    }
};
