<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Función compartida por todas las tablas con desactivación lógica: un DELETE siempre falla.
        // Para "borrar" algo se pone activo = false (y desactivado_en).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION impedir_borrado() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'No se puede borrar un registro de %: desactívalo con activo = false.', TG_TABLE_NAME
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;
        SQL);

        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);
            $table->string('correo', 190);
            $table->string('telefono', 20)->nullable();
            // Nula mientras la persona no acepte su invitación y cree su contraseña.
            $table->string('contrasena', 255)->nullable();
            $table->string('rol', 20);
            $table->boolean('activo')->default(true);
            $table->timestampTz('desactivado_en')->nullable();
            $table->timestampTz('creado_en')->useCurrent();
            $table->timestampTz('actualizado_en')->useCurrent();
        });

        DB::unprepared('CREATE UNIQUE INDEX usuarios_correo_unico ON usuarios (lower(correo))');
        DB::unprepared(<<<'SQL'
            ALTER TABLE usuarios
                ADD CONSTRAINT usuarios_rol_valido
                    CHECK (rol IN ('administrador', 'soporte', 'comedor', 'empresa', 'empleado', 'cliente', 'repartidor')),
                ADD CONSTRAINT usuarios_correo_formato
                    CHECK (correo ~* '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'),
                ADD CONSTRAINT usuarios_desactivacion_coherente
                    CHECK ((activo AND desactivado_en IS NULL) OR (NOT activo AND desactivado_en IS NOT NULL))
        SQL);
        DB::unprepared('CREATE TRIGGER usuarios_sin_borrado BEFORE DELETE ON usuarios FOR EACH ROW EXECUTE FUNCTION impedir_borrado()');
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
        DB::unprepared('DROP FUNCTION IF EXISTS impedir_borrado()');
    }
};
