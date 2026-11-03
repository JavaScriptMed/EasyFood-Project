<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('empresas', function (Blueprint $table) {
            $table->id();
            // Contacto de RR. HH. con acceso al portal de empresas (tabla users, la del login existente).
            $table->foreignId('usuario_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('nombre', 150)->unique();
            // Dominio del correo de sus empleados (nexo.mx).
            $table->string('dominio', 150);
            $table->string('zona', 100);
            // Un solo punto de entrega por día.
            $table->string('direccion_entrega', 255);
            $table->boolean('activo')->default(true);
            $table->timestampTz('desactivado_en')->nullable();
            $table->timestampTz('creado_en')->useCurrent();
            $table->timestampTz('actualizado_en')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE empresas
                ADD CONSTRAINT empresas_desactivacion_coherente
                    CHECK ((activo AND desactivado_en IS NULL) OR (NOT activo AND desactivado_en IS NOT NULL))
        SQL);
        DB::unprepared('CREATE TRIGGER empresas_sin_borrado BEFORE DELETE ON empresas FOR EACH ROW EXECUTE FUNCTION impedir_borrado()');
    }

    public function down(): void
    {
        Schema::dropIfExists('empresas');
    }
};
