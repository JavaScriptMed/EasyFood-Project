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
        // Bitácora: quién hizo qué y cuándo. Solo se agrega; nunca se edita ni se borra.
        Schema::create('registros_auditoria', function (Blueprint $tabla) {
            $tabla->id();
            // Nulo = lo hizo el sistema.
            $tabla->foreignId('usuario_id')->nullable()->constrained('users')->nullOnDelete();
            $tabla->string('accion', 60);
            $tabla->string('entidad', 60);
            $tabla->string('entidad_id', 60);
            // Frase legible: "Aprobó el platillo Pollo teriyaki".
            $tabla->text('detalle');
            $tabla->timestampTz('creado_en')->useCurrent();

            $tabla->index('creado_en');
            $tabla->index(['usuario_id', 'creado_en']);
            $tabla->index(['entidad', 'entidad_id']);
        });

        // Solo se permite UNA modificación: que el autor pase a nulo cuando se elimina su usuario (ON DELETE SET NULL).
        // Cualquier otro cambio, o borrar el registro, falla.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION proteger_bitacora() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE'
                   AND OLD.usuario_id IS NOT NULL AND NEW.usuario_id IS NULL
                   AND ROW(NEW.accion, NEW.entidad, NEW.entidad_id, NEW.detalle, NEW.creado_en)
                       IS NOT DISTINCT FROM ROW(OLD.accion, OLD.entidad, OLD.entidad_id, OLD.detalle, OLD.creado_en) THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'La bitácora solo admite registros nuevos: no se puede % un registro de %.', lower(TG_OP), TG_TABLE_NAME
                    USING ERRCODE = 'restrict_violation';
            END;
            $$;
        SQL);
        DB::unprepared('CREATE TRIGGER registros_auditoria_solo_agregar BEFORE UPDATE OR DELETE ON registros_auditoria FOR EACH ROW EXECUTE FUNCTION proteger_bitacora()');
    }

    public function down(): void
    {
        Schema::dropIfExists('registros_auditoria');
        DB::unprepared('DROP FUNCTION IF EXISTS proteger_bitacora()');
    }
};
