<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empleados', function (Blueprint $table) {
            $table->id();
            // Una persona es empleado de una sola empresa.
            $table->foreignId('usuario_id')->unique()->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            $table->string('departamento', 100)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestampTz('desactivado_en')->nullable();
            $table->timestampTz('creado_en')->useCurrent();
            $table->timestampTz('actualizado_en')->useCurrent();

            $table->index(['empresa_id', 'activo']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE empleados
                ADD CONSTRAINT empleados_desactivacion_coherente
                    CHECK ((activo AND desactivado_en IS NULL) OR (NOT activo AND desactivado_en IS NOT NULL))
        SQL);
        DB::unprepared('CREATE TRIGGER empleados_sin_borrado BEFORE DELETE ON empleados FOR EACH ROW EXECUTE FUNCTION impedir_borrado()');
    }

    public function down(): void
    {
        Schema::dropIfExists('empleados');
    }
};
