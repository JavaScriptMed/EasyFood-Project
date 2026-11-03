<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comedores', function (Blueprint $table) {
            $table->id();
            // Contacto titular con acceso al portal del comedor (tabla users, la del login existente).
            $table->foreignId('usuario_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('nombre', 150)->unique();
            $table->string('zona', 100);
            $table->boolean('activo')->default(true);
            $table->timestampTz('desactivado_en')->nullable();
            $table->timestampTz('creado_en')->useCurrent();
            $table->timestampTz('actualizado_en')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE comedores
                ADD CONSTRAINT comedores_desactivacion_coherente
                    CHECK ((activo AND desactivado_en IS NULL) OR (NOT activo AND desactivado_en IS NOT NULL))
        SQL);
        DB::unprepared('CREATE TRIGGER comedores_sin_borrado BEFORE DELETE ON comedores FOR EACH ROW EXECUTE FUNCTION impedir_borrado()');
    }

    public function down(): void
    {
        Schema::dropIfExists('comedores');
    }
};
