<?php

use App\Support\Database\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Los clientes individuales reciben todo su pedido en UNA entrega, el sábado de cada semana.
     *
     * - `pedido_dias` pasa a ser la entrega (una por pedido, con su parada, su estado y su evidencia): su `fecha` es el sábado.
     *   Los pedidos que ya existían siguen teniendo una entrega por día y no se tocan.
     * - `pedido_dia_platillos.fecha` es el día en que se come cada platillo (el cliente sigue eligiendo por día). Hay una comida por
     *   tiempo y un extra por día de comida dentro de la entrega. Si un INSERT no la manda, la base usa la fecha de la entrega.
     * - `pedidos.fecha_entrega` es el sábado en que llega el pedido (`fecha_inicio` y `fecha_fin` son el primer y el último día de comida).
     *   Si un INSERT no la manda, la base usa `fecha_inicio`.
     * - `cortes.tipo`: el corte «general» (el de siempre: empleados, empresas y sus rutas) y el de «individuales», que cierra el
     *   viernes a la hora de corte, genera las órdenes de producción de los clientes y sus rutas. Hay uno de cada tipo por fecha.
     */
    public function up(): void
    {
        // ------------------------------------------------------------ pedidos
        Schema::table('pedidos', function (Blueprint $tabla) {
            $tabla->date('fecha_entrega')->nullable();
        });
        DB::unprepared('UPDATE pedidos SET fecha_entrega = fecha_inicio');
        DB::unprepared('ALTER TABLE pedidos ALTER COLUMN fecha_entrega SET NOT NULL');
        DB::unprepared('CREATE INDEX pedidos_fecha_entrega_idx ON pedidos (fecha_entrega)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pedido_fecha_de_entrega_por_defecto() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                NEW.fecha_entrega := COALESCE(NEW.fecha_entrega, NEW.fecha_inicio);
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::unprepared('CREATE TRIGGER pedidos_fecha_entrega_por_defecto BEFORE INSERT ON pedidos FOR EACH ROW EXECUTE FUNCTION pedido_fecha_de_entrega_por_defecto()');

        // ------------------------------------------------------------ platillos por día de comida
        Schema::table('pedido_dia_platillos', function (Blueprint $tabla) {
            $tabla->date('fecha')->nullable();
        });
        DB::unprepared('UPDATE pedido_dia_platillos c SET fecha = d.fecha FROM pedido_dias d WHERE d.id = c.pedido_dia_id');
        DB::unprepared('ALTER TABLE pedido_dia_platillos ALTER COLUMN fecha SET NOT NULL');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pedido_dia_platillo_fecha_por_defecto() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.fecha IS NULL THEN
                    SELECT fecha INTO NEW.fecha FROM pedido_dias WHERE id = NEW.pedido_dia_id;
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::unprepared('CREATE TRIGGER pedido_dia_platillos_fecha_por_defecto BEFORE INSERT ON pedido_dia_platillos FOR EACH ROW EXECUTE FUNCTION pedido_dia_platillo_fecha_por_defecto()');
        DB::unprepared('CREATE INDEX pedido_dia_platillos_fecha_menu_idx ON pedido_dia_platillos (fecha, menu_id)');

        // Una comida base por tiempo y un extra, pero ahora POR DÍA DE COMIDA dentro de la entrega.
        DB::unprepared('DROP INDEX pedido_dia_platillos_un_platillo_por_tiempo');
        DB::unprepared('DROP INDEX pedido_dia_platillos_un_extra_por_dia');
        DB::unprepared('CREATE UNIQUE INDEX pedido_dia_platillos_un_platillo_por_tiempo ON pedido_dia_platillos (pedido_dia_id, fecha, tiempo_comida) WHERE NOT es_extra');
        DB::unprepared('CREATE UNIQUE INDEX pedido_dia_platillos_un_extra_por_dia ON pedido_dia_platillos (pedido_dia_id, fecha) WHERE es_extra');

        // ------------------------------------------------------------ cortes
        DB::unprepared("ALTER TABLE cortes ADD COLUMN tipo varchar(15) NOT NULL DEFAULT 'general'");
        Esquema::enumerado('cortes', 'tipo', ['general', 'individuales']);
        DB::unprepared('DROP INDEX cortes_un_cierre_por_dia');
        DB::unprepared("CREATE UNIQUE INDEX cortes_un_cierre_por_dia ON cortes (fecha_entrega, tipo) WHERE NOT sin_cambios AND estado <> 'fallido'");

        // Solo el corte general cierra las elecciones de los empleados.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION impedir_eleccion_tras_corte() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM cortes WHERE fecha_entrega = NEW.fecha AND tipo = 'general' AND NOT sin_cambios AND estado <> 'fallido') THEN
                    RAISE EXCEPTION 'El corte de % ya se ejecutó: no se pueden registrar más elecciones.', NEW.fecha USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
    }

    public function down(): void
    {
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
        DB::unprepared('DROP INDEX cortes_un_cierre_por_dia');
        // Los cortes de individuales no existían: se sueltan sus referencias y se quitan (el borrado de cortes está bloqueado por trigger).
        DB::unprepared("UPDATE rutas SET corte_id = NULL WHERE corte_id IN (SELECT id FROM cortes WHERE tipo = 'individuales')");
        DB::unprepared("UPDATE ordenes_produccion SET corte_id = NULL WHERE corte_id IN (SELECT id FROM cortes WHERE tipo = 'individuales')");
        DB::unprepared('ALTER TABLE cortes DISABLE TRIGGER cortes_sin_borrado');
        DB::unprepared("DELETE FROM cortes WHERE tipo = 'individuales'");
        DB::unprepared('ALTER TABLE cortes ENABLE TRIGGER cortes_sin_borrado');
        DB::unprepared('ALTER TABLE cortes DROP CONSTRAINT cortes_tipo_valido');
        DB::unprepared('ALTER TABLE cortes DROP COLUMN tipo');
        DB::unprepared("CREATE UNIQUE INDEX cortes_un_cierre_por_dia ON cortes (fecha_entrega) WHERE NOT sin_cambios AND estado <> 'fallido'");

        DB::unprepared('DROP INDEX pedido_dia_platillos_un_platillo_por_tiempo');
        DB::unprepared('DROP INDEX pedido_dia_platillos_un_extra_por_dia');
        DB::unprepared('CREATE UNIQUE INDEX pedido_dia_platillos_un_platillo_por_tiempo ON pedido_dia_platillos (pedido_dia_id, tiempo_comida) WHERE NOT es_extra');
        DB::unprepared('CREATE UNIQUE INDEX pedido_dia_platillos_un_extra_por_dia ON pedido_dia_platillos (pedido_dia_id) WHERE es_extra');
        DB::unprepared('DROP INDEX pedido_dia_platillos_fecha_menu_idx');
        DB::unprepared('DROP TRIGGER pedido_dia_platillos_fecha_por_defecto ON pedido_dia_platillos');
        DB::unprepared('DROP FUNCTION pedido_dia_platillo_fecha_por_defecto()');
        Schema::table('pedido_dia_platillos', fn (Blueprint $tabla) => $tabla->dropColumn('fecha'));

        DB::unprepared('DROP TRIGGER pedidos_fecha_entrega_por_defecto ON pedidos');
        DB::unprepared('DROP FUNCTION pedido_fecha_de_entrega_por_defecto()');
        DB::unprepared('DROP INDEX pedidos_fecha_entrega_idx');
        Schema::table('pedidos', fn (Blueprint $tabla) => $tabla->dropColumn('fecha_entrega'));
    }
};
