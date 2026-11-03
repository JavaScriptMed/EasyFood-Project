<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contratos_empresa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->decimal('porcentaje_subsidio', 5, 2); // 0 a 100
            $table->integer('compra_minima_mensual');
            $table->decimal('precio_por_platillo', 10, 2);
            $table->date('vigencia_inicio');
            $table->date('vigencia_fin');
            $table->time('hora_entrega');             // hora estimada de llegada
            $table->time('ventana_entrega_inicio');   // ventana en la que la empresa recibe
            $table->time('ventana_entrega_fin');
            $table->boolean('activo')->default(true);
            $table->timestampTz('desactivado_en')->nullable();
            $table->timestampTz('creado_en')->useCurrent();
            $table->timestampTz('actualizado_en')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE contratos_empresa
                ADD CONSTRAINT contratos_empresa_subsidio_entre_0_y_100 CHECK (porcentaje_subsidio BETWEEN 0 AND 100),
                ADD CONSTRAINT contratos_empresa_compra_minima_positiva CHECK (compra_minima_mensual > 0),
                ADD CONSTRAINT contratos_empresa_precio_positivo CHECK (precio_por_platillo > 0),
                ADD CONSTRAINT contratos_empresa_vigencia_valida CHECK (vigencia_fin > vigencia_inicio),
                ADD CONSTRAINT contratos_empresa_ventana_valida CHECK (ventana_entrega_fin > ventana_entrega_inicio),
                ADD CONSTRAINT contratos_empresa_hora_dentro_de_ventana CHECK (hora_entrega BETWEEN ventana_entrega_inicio AND ventana_entrega_fin),
                ADD CONSTRAINT contratos_empresa_desactivacion_coherente
                    CHECK ((activo AND desactivado_en IS NULL) OR (NOT activo AND desactivado_en IS NOT NULL))
        SQL);
        // Una empresa solo puede tener un contrato activo a la vez.
        DB::unprepared('CREATE UNIQUE INDEX contratos_empresa_un_activo_por_empresa ON contratos_empresa (empresa_id) WHERE activo');
        DB::unprepared('CREATE TRIGGER contratos_empresa_sin_borrado BEFORE DELETE ON contratos_empresa FOR EACH ROW EXECUTE FUNCTION impedir_borrado()');
    }

    public function down(): void
    {
        Schema::dropIfExists('contratos_empresa');
    }
};
