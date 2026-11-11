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
        Schema::create('rutas', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->date('fecha');
            $tabla->foreignId('repartidor_id')->constrained('repartidores')->restrictOnDelete();
            $tabla->foreignId('corte_id')->nullable()->constrained('cortes')->restrictOnDelete();
            $tabla->string('zona', 150);
            $tabla->time('ventana_inicio');
            $tabla->time('ventana_fin');
            // Capacidad del auto al crear la ruta (copia de la configuración de ese momento).
            $tabla->integer('capacidad_entregas');
            $tabla->integer('capacidad_platillos');
            $tabla->integer('entregas_asignadas')->default(0);
            $tabla->integer('platillos_asignados')->default(0);
            $tabla->decimal('distancia_km', 8, 2)->nullable();
            $tabla->time('hora_fin_estimada')->nullable();
            // La ruta termina después de su ventana de entrega (aviso, no error).
            $tabla->boolean('excede_bloque')->default(false);
            $tabla->string('estado', 10)->default('borrador');
            Esquema::marcasDeTiempo($tabla);

            $tabla->index(['fecha', 'repartidor_id']);
        });

        Esquema::enumerado('rutas', 'estado', ['borrador', 'aprobada']);
        Esquema::check('rutas', 'ventana_valida', 'ventana_fin > ventana_inicio');
        Esquema::check('rutas', 'capacidad_positiva', 'capacidad_entregas > 0 AND capacidad_platillos > 0');
        Esquema::check('rutas', 'asignado_no_negativo', 'entregas_asignadas >= 0 AND platillos_asignados >= 0');
        // Regla de negocio: capacidad por paradas y por platillos. Una ruta nunca lleva más de lo que cabe en el auto.
        Esquema::check('rutas', 'dentro_de_la_capacidad', 'entregas_asignadas <= capacidad_entregas AND platillos_asignados <= capacidad_platillos');
        Esquema::check('rutas', 'distancia_no_negativa', 'distancia_km IS NULL OR distancia_km >= 0');
        Esquema::sinBorrado('rutas');
    }

    public function down(): void
    {
        Schema::dropIfExists('rutas');
    }
};
