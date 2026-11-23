<?php

use App\Support\Database\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * API del repartidor: el avance de una ruta y de cada parada.
     *
     * - `rutas.estado` suma «en_curso» (empezó su primera parada) y «completada» (todas las paradas quedaron entregadas o no entregadas).
     * - `paradas.estado` suma «en_camino» (el repartidor la inició). Una parada cerrada queda «entregado» (con foto de evidencia) o
     *   «no_localizado» (con el motivo). Se guarda cuándo se inició y cuándo se cerró.
     */
    public function up(): void
    {
        Schema::table('rutas', function (Blueprint $tabla) {
            $tabla->timestampTz('iniciada_en')->nullable();
            $tabla->timestampTz('completada_en')->nullable();
        });
        DB::unprepared('ALTER TABLE rutas DROP CONSTRAINT rutas_estado_valido');
        Esquema::enumerado('rutas', 'estado', ['borrador', 'aprobada', 'en_curso', 'completada']);
        Esquema::check('rutas', 'avance_coherente', "((estado IN ('en_curso', 'completada')) = (iniciada_en IS NOT NULL)) AND ((estado = 'completada') = (completada_en IS NOT NULL))");

        Schema::table('paradas', function (Blueprint $tabla) {
            $tabla->timestampTz('iniciada_en')->nullable();
            $tabla->timestampTz('cerrada_en')->nullable();
            $tabla->string('evidencia_foto_url', 255)->nullable();
            $tabla->string('motivo_no_entrega', 255)->nullable();
        });
        DB::unprepared('ALTER TABLE paradas DROP CONSTRAINT paradas_estado_valido');
        Esquema::enumerado('paradas', 'estado', ['pendiente', 'en_camino', 'entregado', 'no_localizado']);
        Esquema::check('paradas', 'inicio_coherente', "(estado = 'pendiente') = (iniciada_en IS NULL)");
        Esquema::check('paradas', 'cierre_coherente', "(estado IN ('entregado', 'no_localizado')) = (cerrada_en IS NOT NULL)");
        Esquema::check('paradas', 'entregada_con_evidencia', "estado <> 'entregado' OR evidencia_foto_url IS NOT NULL");
        Esquema::check('paradas', 'no_entregada_con_motivo', "estado <> 'no_localizado' OR (motivo_no_entrega IS NOT NULL AND length(btrim(motivo_no_entrega)) > 0)");
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE paradas DROP CONSTRAINT paradas_no_entregada_con_motivo, DROP CONSTRAINT paradas_entregada_con_evidencia, DROP CONSTRAINT paradas_cierre_coherente, DROP CONSTRAINT paradas_inicio_coherente');
        DB::unprepared('ALTER TABLE paradas DROP CONSTRAINT paradas_estado_valido');
        DB::unprepared("UPDATE paradas SET estado = 'pendiente' WHERE estado = 'en_camino'");
        Esquema::enumerado('paradas', 'estado', ['pendiente', 'entregado', 'no_localizado']);
        Schema::table('paradas', fn (Blueprint $tabla) => $tabla->dropColumn(['iniciada_en', 'cerrada_en', 'evidencia_foto_url', 'motivo_no_entrega']));

        DB::unprepared('ALTER TABLE rutas DROP CONSTRAINT rutas_avance_coherente, DROP CONSTRAINT rutas_estado_valido');
        DB::unprepared("UPDATE rutas SET estado = 'aprobada' WHERE estado IN ('en_curso', 'completada')");
        Esquema::enumerado('rutas', 'estado', ['borrador', 'aprobada']);
        Schema::table('rutas', fn (Blueprint $tabla) => $tabla->dropColumn(['iniciada_en', 'completada_en']));
    }
};
