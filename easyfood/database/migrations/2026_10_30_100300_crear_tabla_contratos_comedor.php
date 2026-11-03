<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contratos_comedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comedor_id')->constrained('comedores')->restrictOnDelete();
            // Valores de partida del contrato estándar (CLAUDE.md): $49 por platillo y mínimo de 200 al mes.
            $table->decimal('precio_por_platillo', 10, 2)->default(49);
            $table->integer('compra_minima_mensual')->default(200);
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->boolean('activo')->default(true);
            $table->timestampTz('desactivado_en')->nullable();
            $table->timestampTz('creado_en')->useCurrent();
            $table->timestampTz('actualizado_en')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE contratos_comedor
                ADD CONSTRAINT contratos_comedor_precio_positivo CHECK (precio_por_platillo > 0),
                ADD CONSTRAINT contratos_comedor_compra_minima_positiva CHECK (compra_minima_mensual > 0),
                ADD CONSTRAINT contratos_comedor_fechas_validas CHECK (fecha_fin > fecha_inicio),
                ADD CONSTRAINT contratos_comedor_desactivacion_coherente
                    CHECK ((activo AND desactivado_en IS NULL) OR (NOT activo AND desactivado_en IS NOT NULL))
        SQL);
        // Un comedor solo puede tener un contrato activo a la vez.
        DB::unprepared('CREATE UNIQUE INDEX contratos_comedor_un_activo_por_comedor ON contratos_comedor (comedor_id) WHERE activo');
        DB::unprepared('CREATE TRIGGER contratos_comedor_sin_borrado BEFORE DELETE ON contratos_comedor FOR EACH ROW EXECUTE FUNCTION impedir_borrado()');
    }

    public function down(): void
    {
        Schema::dropIfExists('contratos_comedor');
    }
};
