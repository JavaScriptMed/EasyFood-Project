<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1) Decisión del 11 nov 2026: se entrega de lunes a sábado (empresas y clientes individuales); el domingo no hay servicio.
 *    El valor inicial de configuracion.dias_entrega pasa de lunes_viernes a lunes_sabado (solo si nadie lo había cambiado).
 * 2) Regla «platillo_del_dia» de quien no eligió: el comedor marca un platillo por día y tiempo de comida.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('configuracion')->where('clave', 'dias_entrega')->where('valor', 'lunes_viernes')->update(['valor' => 'lunes_sabado']);

        Schema::table('disponibilidad_dia', function (Blueprint $tabla) {
            $tabla->boolean('es_platillo_del_dia')->default(false);
        });

        // Un solo platillo del día por comedor, fecha y tiempo de comida (el comedor sale del platillo, por eso es trigger y no índice).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION un_platillo_del_dia_por_comedor() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.es_platillo_del_dia AND EXISTS (
                    SELECT 1
                    FROM disponibilidad_dia d
                    JOIN menus otro ON otro.id = d.menu_id
                    JOIN menus este ON este.id = NEW.menu_id
                    WHERE d.es_platillo_del_dia
                      AND d.fecha = NEW.fecha
                      AND d.id IS DISTINCT FROM NEW.id
                      AND otro.provider_id = este.provider_id
                      AND otro.tiempo_comida = este.tiempo_comida
                ) THEN
                    RAISE EXCEPTION 'El comedor ya marcó otro platillo del día para el % en ese tiempo de comida.', NEW.fecha USING ERRCODE = 'unique_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::unprepared('CREATE TRIGGER disponibilidad_dia_un_platillo_del_dia BEFORE INSERT OR UPDATE ON disponibilidad_dia FOR EACH ROW EXECUTE FUNCTION un_platillo_del_dia_por_comedor()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS disponibilidad_dia_un_platillo_del_dia ON disponibilidad_dia');
        Schema::table('disponibilidad_dia', function (Blueprint $tabla) {
            $tabla->dropColumn('es_platillo_del_dia');
        });
        DB::table('configuracion')->where('clave', 'dias_entrega')->where('valor', 'lunes_sabado')->update(['valor' => 'lunes_viernes']);
    }
};
