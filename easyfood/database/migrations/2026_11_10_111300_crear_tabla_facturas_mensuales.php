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
        Schema::create('facturas_mensuales', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('folio', 30)->unique();
            $tabla->foreignId('empresa_id')->constrained('empresas')->restrictOnDelete();
            // Primer día del mes que se factura (2026-10-01 = octubre de 2026).
            $tabla->date('mes');
            $tabla->integer('platillos');
            $tabla->decimal('parte_empresa', 10, 2);
            $tabla->decimal('parte_empleados', 10, 2);
            $tabla->integer('platillos_faltantes')->default(0);
            $tabla->decimal('diferencia_minimo', 10, 2)->default(0);
            $tabla->decimal('total', 10, 2);
            $tabla->string('estado', 10)->default('borrador');
            $tabla->timestampTz('emitida_en')->nullable();
            $tabla->timestampTz('pagada_en')->nullable();
            Esquema::marcasDeTiempo($tabla);

            // Una factura por empresa y mes.
            $tabla->unique(['empresa_id', 'mes']);
        });

        Esquema::enumerado('facturas_mensuales', 'estado', ['borrador', 'emitida', 'pagada']);
        Esquema::primerDiaDeMes('facturas_mensuales');
        Esquema::check('facturas_mensuales', 'montos_no_negativos', 'platillos >= 0 AND parte_empresa >= 0 AND parte_empleados >= 0 AND platillos_faltantes >= 0 AND diferencia_minimo >= 0 AND total >= 0');
        // Total = lo que consumieron (empresa + empleados) + la diferencia por no llegar al mínimo.
        Esquema::check('facturas_mensuales', 'total_cuadra', 'total = parte_empresa + parte_empleados + diferencia_minimo');
        Esquema::check('facturas_mensuales', 'sin_faltantes_no_hay_diferencia', 'platillos_faltantes > 0 OR diferencia_minimo = 0');
        Esquema::check('facturas_mensuales', 'fechas_segun_estado', "(estado = 'borrador' AND emitida_en IS NULL AND pagada_en IS NULL) OR (estado = 'emitida' AND emitida_en IS NOT NULL AND pagada_en IS NULL) OR (estado = 'pagada' AND emitida_en IS NOT NULL AND pagada_en IS NOT NULL)");

        // Una factura emitida queda congelada: solo puede pasar de emitida a pagada, y nunca volver atrás.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION congelar_factura_emitida() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.estado <> 'borrador' THEN
                    IF ROW(NEW.empresa_id, NEW.mes, NEW.platillos, NEW.parte_empresa, NEW.parte_empleados, NEW.platillos_faltantes, NEW.diferencia_minimo, NEW.total, NEW.folio)
                       IS DISTINCT FROM ROW(OLD.empresa_id, OLD.mes, OLD.platillos, OLD.parte_empresa, OLD.parte_empleados, OLD.platillos_faltantes, OLD.diferencia_minimo, OLD.total, OLD.folio) THEN
                        RAISE EXCEPTION 'La factura % ya fue emitida: sus montos no se pueden cambiar.', OLD.folio USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF NEW.estado = 'borrador' OR (OLD.estado = 'pagada' AND NEW.estado <> 'pagada') THEN
                        RAISE EXCEPTION 'La factura % no puede regresar a un estado anterior.', OLD.folio USING ERRCODE = 'restrict_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::unprepared('CREATE TRIGGER facturas_mensuales_congelada BEFORE UPDATE ON facturas_mensuales FOR EACH ROW EXECUTE FUNCTION congelar_factura_emitida()');
        Esquema::sinBorrado('facturas_mensuales');
    }

    public function down(): void
    {
        Schema::dropIfExists('facturas_mensuales');
        DB::unprepared('DROP FUNCTION IF EXISTS congelar_factura_emitida()');
    }
};
