<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion', function (Blueprint $table) {
            $table->string('clave', 60)->primary();
            $table->string('valor', 255);
            // Quién la cambió por última vez (tabla users); si ese usuario desaparece, el dato queda sin autor.
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('actualizado_en')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE configuracion
                ADD CONSTRAINT configuracion_clave_conocida
                    CHECK (clave IN ('hora_corte', 'regla_sin_eleccion', 'dias_entrega', 'capacidad_auto_entregas', 'capacidad_auto_platillos')),
                ADD CONSTRAINT configuracion_hora_corte_valida
                    CHECK (clave <> 'hora_corte' OR valor ~ '^([01][0-9]|2[0-3]):[0-5][0-9]$'),
                ADD CONSTRAINT configuracion_regla_sin_eleccion_valida
                    CHECK (clave <> 'regla_sin_eleccion' OR valor IN ('ultimo_platillo', 'platillo_del_dia', 'no_entregar')),
                ADD CONSTRAINT configuracion_dias_entrega_validos
                    CHECK (clave <> 'dias_entrega' OR valor IN ('lunes_viernes', 'lunes_sabado')),
                ADD CONSTRAINT configuracion_capacidad_positiva
                    CHECK (clave NOT IN ('capacidad_auto_entregas', 'capacidad_auto_platillos') OR valor ~ '^[1-9][0-9]{0,8}$')
        SQL);

        // Valores iniciales (confirmados): corte 6:00 p. m., sin elección se repite el último platillo,
        // entregas de lunes a viernes y un auto lleva hasta 30 entregas o 120 platillos.
        DB::table('configuracion')->insert([
            ['clave' => 'hora_corte', 'valor' => '18:00'],
            ['clave' => 'regla_sin_eleccion', 'valor' => 'ultimo_platillo'],
            ['clave' => 'dias_entrega', 'valor' => 'lunes_viernes'],
            ['clave' => 'capacidad_auto_entregas', 'valor' => '30'],
            ['clave' => 'capacidad_auto_platillos', 'valor' => '120'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion');
    }
};
