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
        // Personal de reparto de EasyLunch (no confundir con `empleados`, que son los de las empresas cliente).
        Schema::create('repartidores', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('usuario_id')->unique()->constrained('users')->restrictOnDelete();
            $tabla->string('numero_empleado', 20)->unique();
            // Solo se reparte en auto: las placas son obligatorias.
            $tabla->string('placas', 15);
            $tabla->string('zona_base', 100);
            $tabla->string('estado', 15)->default('libre');
            // Última ubicación conocida (PostGIS, WGS 84): se agrega abajo porque Blueprint no trae geography.
            $tabla->timestampTz('ultima_ubicacion_en')->nullable();
            Esquema::columnasDeDesactivacion($tabla);
            Esquema::marcasDeTiempo($tabla);
        });

        DB::unprepared('ALTER TABLE repartidores ADD COLUMN ultima_ubicacion geography(Point, 4326)');
        DB::unprepared('CREATE INDEX repartidores_ultima_ubicacion_gix ON repartidores USING GIST (ultima_ubicacion)');
        Esquema::enumerado('repartidores', 'estado', ['libre', 'asignado', 'en_ruta', 'no_disponible']);
        Esquema::check('repartidores', 'placas_con_texto', "length(btrim(placas)) > 0");
        Esquema::desactivacionLogica('repartidores');
    }

    public function down(): void
    {
        Schema::dropIfExists('repartidores');
    }
};
