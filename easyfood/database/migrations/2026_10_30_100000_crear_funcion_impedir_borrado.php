<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Función compartida por las tablas con desactivación lógica: un DELETE siempre falla.
        // Para "borrar" algo se pone activo = false (y desactivado_en).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION impedir_borrado() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'No se puede borrar un registro de %: desactívalo con activo = false.', TG_TABLE_NAME
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
        // Corre al final del rollback: las tablas con trigger ya se eliminaron.
        DB::unprepared('DROP FUNCTION IF EXISTS impedir_borrado()');
    }
};
