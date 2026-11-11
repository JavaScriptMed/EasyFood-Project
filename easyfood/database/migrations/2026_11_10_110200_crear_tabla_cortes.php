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
        Schema::create('cortes', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->date('fecha_entrega');
            $tabla->timestampTz('ejecutado_en')->useCurrent();
            // Nulo = lo ejecutó el sistema a la hora de corte; con valor = lo lanzó esa persona a mano.
            $tabla->foreignId('ejecutado_por')->nullable()->constrained('users')->nullOnDelete();
            $tabla->string('estado', 20);
            // Una segunda ejecución del mismo día no cambia nada y se registra como "sin cambios".
            $tabla->boolean('sin_cambios')->default(false);
            $tabla->integer('elecciones_cerradas')->default(0);
            $tabla->integer('empleados_por_defecto')->default(0);
            $tabla->integer('ordenes_generadas')->default(0);
            $tabla->integer('entregas_consolidadas')->default(0);
            $tabla->integer('rutas_creadas')->default(0);
            $tabla->text('mensaje')->nullable();

            $tabla->index('fecha_entrega');
        });

        Esquema::enumerado('cortes', 'estado', ['correcto', 'con_advertencias', 'fallido']);
        Esquema::check('cortes', 'conteos_no_negativos', 'elecciones_cerradas >= 0 AND empleados_por_defecto >= 0 AND ordenes_generadas >= 0 AND entregas_consolidadas >= 0 AND rutas_creadas >= 0');
        Esquema::check('cortes', 'fallido_con_mensaje', "estado <> 'fallido' OR mensaje IS NOT NULL");
        // Idempotencia: para cada día solo puede existir UN corte que realmente cerró (ni "sin cambios" ni fallido).
        DB::unprepared("CREATE UNIQUE INDEX cortes_un_cierre_por_dia ON cortes (fecha_entrega) WHERE NOT sin_cambios AND estado <> 'fallido'");
        Esquema::sinBorrado('cortes');
    }

    public function down(): void
    {
        Schema::dropIfExists('cortes');
    }
};
