<?php

use App\Support\Database\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coordenadas opcionales del punto de entrega de cada empresa.
 *
 * - Son opcionales: las empresas existentes conservan su zona sin coordenadas.
 * - Cuando se guardan coordenadas (alta o edición), el backend calcula la zona con PostGIS (ST_Covers).
 * - No hay trigger sobre `zonas`: editar un polígono nunca mueve empresas por su cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $tabla) {
            $tabla->decimal('latitud', 9, 6)->nullable();
            $tabla->decimal('longitud', 9, 6)->nullable();
        });

        Esquema::check('empresas', 'coordenadas_completas', '(latitud IS NULL) = (longitud IS NULL)');
        Esquema::check('empresas', 'latitud_valida', 'latitud IS NULL OR (latitud >= -90 AND latitud <= 90)');
        Esquema::check('empresas', 'longitud_valida', 'longitud IS NULL OR (longitud >= -180 AND longitud <= 180)');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE empresas DROP CONSTRAINT IF EXISTS empresas_longitud_valida');
        DB::unprepared('ALTER TABLE empresas DROP CONSTRAINT IF EXISTS empresas_latitud_valida');
        DB::unprepared('ALTER TABLE empresas DROP CONSTRAINT IF EXISTS empresas_coordenadas_completas');

        Schema::table('empresas', function (Blueprint $tabla) {
            $tabla->dropColumn(['latitud', 'longitud']);
        });
    }
};
