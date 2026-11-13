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
        Schema::create('elecciones_empleado', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('empleado_id')->constrained('empleados')->restrictOnDelete();
            $tabla->date('fecha');
            $tabla->string('tiempo_comida', 10);
            $tabla->foreignId('menu_id')->constrained('menus')->restrictOnDelete();
            // Se congelan al elegir: cambios posteriores del contrato no tocan lo ya elegido.
            $tabla->decimal('precio_congelado', 10, 2);
            $tabla->decimal('porcentaje_subsidio_congelado', 5, 2);
            $tabla->string('origen', 12);
            // Lo llena el corte. Con valor, la elección ya no se puede cambiar ni borrar (trigger).
            $tabla->timestampTz('cerrada_en')->nullable();
            Esquema::marcasDeTiempo($tabla);

            // Un platillo por empleado, por día y por tiempo de comida.
            $tabla->unique(['empleado_id', 'fecha', 'tiempo_comida']);
            $tabla->index(['fecha', 'menu_id']);
        });

        Esquema::enumerado('elecciones_empleado', 'tiempo_comida', ['desayuno', 'comida', 'cena']);
        Esquema::enumerado('elecciones_empleado', 'origen', ['manual', 'por_defecto']);
        Esquema::check('elecciones_empleado', 'precio_positivo', 'precio_congelado > 0');
        Esquema::check('elecciones_empleado', 'subsidio_entre_0_y_100', 'porcentaje_subsidio_congelado BETWEEN 0 AND 100');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION impedir_cambio_si_cerrada() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.cerrada_en IS NOT NULL THEN
                    RAISE EXCEPTION 'La elección ya fue cerrada por el corte y no se puede cambiar.' USING ERRCODE = 'restrict_violation';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::unprepared('CREATE TRIGGER elecciones_empleado_cerradas BEFORE UPDATE OR DELETE ON elecciones_empleado FOR EACH ROW EXECUTE FUNCTION impedir_cambio_si_cerrada()');

        // Después del corte de un día tampoco se pueden registrar elecciones nuevas para ese día.
        // El corte inserta las elecciones "por defecto" ANTES de registrarse a sí mismo, dentro de la misma transacción.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION impedir_eleccion_tras_corte() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM cortes WHERE fecha_entrega = NEW.fecha AND NOT sin_cambios AND estado <> 'fallido') THEN
                    RAISE EXCEPTION 'El corte de % ya se ejecutó: no se pueden registrar más elecciones.', NEW.fecha USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::unprepared('CREATE TRIGGER elecciones_empleado_tras_corte BEFORE INSERT ON elecciones_empleado FOR EACH ROW EXECUTE FUNCTION impedir_eleccion_tras_corte()');
    }

    public function down(): void
    {
        Schema::dropIfExists('elecciones_empleado');
        DB::unprepared('DROP FUNCTION IF EXISTS impedir_eleccion_tras_corte()');
        DB::unprepared('DROP FUNCTION IF EXISTS impedir_cambio_si_cerrada()');
    }
};
