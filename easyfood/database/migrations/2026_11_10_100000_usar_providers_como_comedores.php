<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decisión del equipo: los comedores son la tabla `providers` (ya tiene contacto, dirección,
 * coordenadas y capacidad diaria). La tabla `comedores` se elimina y `contratos_comedor`
 * apunta a `providers`. La columna conserva el nombre `comedor_id` porque así lo pide el negocio.
 *
 * Va en una migración nueva (y no editando las anteriores) porque `providers` se crea después de
 * `contratos_comedor`, y para que funcione igual en bases donde las migraciones ya corrieron.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Los ids de `comedores` no existen en `providers`: si ya hubiera datos, moverlos requiere una decisión.
        if (DB::table('contratos_comedor')->exists() || DB::table('comedores')->exists()) {
            throw new RuntimeException('Hay comedores o contratos de comedor registrados: migra sus datos a providers a mano antes de correr esta migración.');
        }

        Schema::table('contratos_comedor', function (Blueprint $table) {
            $table->dropForeign(['comedor_id']);
            $table->foreign('comedor_id')->references('id')->on('providers')->restrictOnDelete();
        });

        Schema::drop('comedores');
    }

    public function down(): void
    {
        Schema::create('comedores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('nombre', 150)->unique();
            $table->string('zona', 100);
            $table->boolean('activo')->default(true);
            $table->timestampTz('desactivado_en')->nullable();
            $table->timestampTz('creado_en')->useCurrent();
            $table->timestampTz('actualizado_en')->useCurrent();
        });
        DB::unprepared('ALTER TABLE comedores ADD CONSTRAINT comedores_desactivacion_coherente CHECK ((activo AND desactivado_en IS NULL) OR (NOT activo AND desactivado_en IS NOT NULL))');
        DB::unprepared('CREATE TRIGGER comedores_sin_borrado BEFORE DELETE ON comedores FOR EACH ROW EXECUTE FUNCTION impedir_borrado()');

        Schema::table('contratos_comedor', function (Blueprint $table) {
            $table->dropForeign(['comedor_id']);
            $table->foreign('comedor_id')->references('id')->on('comedores')->restrictOnDelete();
        });
    }
};
