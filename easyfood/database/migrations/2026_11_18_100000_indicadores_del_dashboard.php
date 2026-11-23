<?php

use App\Support\Database\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Datos que faltaban para los indicadores del dashboard:
     * - calificaciones_entrega: 1 a 5 estrellas en puntualidad, trato del chofer y estado del empaque, de lo ya entregado
     *   (a una empresa, por un empleado; o a un cliente individual, por su pedido).
     * - calificaciones_platillo: 1 a 5 estrellas al sabor y la calidad de un platillo ya entregado.
     * - costos_operativos: otros gastos (reparto, empaque, nómina…) que entran al balance de utilidad; se anulan, no se borran.
     * - incidencias: dos tipos nuevos (comida fría y entrega tardía) para el conteo semanal de quejas.
     * - configuracion: el porcentaje de sobreprecio de las apps de entrega (ahorro estimado) y el tope del pago a cocinas.
     */
    public function up(): void
    {
        // ---- calificaciones de la entrega (última milla)
        Schema::create('calificaciones_entrega', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('calificador_id')->constrained('users')->restrictOnDelete();
            $tabla->foreignId('entrega_empresa_id')->nullable()->constrained('entregas_empresa')->restrictOnDelete();
            $tabla->foreignId('pedido_dia_id')->nullable()->constrained('pedido_dias')->restrictOnDelete();
            $tabla->smallInteger('puntualidad');
            $tabla->smallInteger('trato_chofer');
            $tabla->smallInteger('empaque');
            $tabla->text('comentario')->nullable();
            Esquema::marcasDeTiempo($tabla);
        });
        Esquema::check('calificaciones_entrega', 'una_sola_entrega', '(entrega_empresa_id IS NULL) <> (pedido_dia_id IS NULL)');
        Esquema::check('calificaciones_entrega', 'estrellas_validas', 'puntualidad BETWEEN 1 AND 5 AND trato_chofer BETWEEN 1 AND 5 AND empaque BETWEEN 1 AND 5');
        // Una calificación por persona y entrega.
        DB::unprepared('CREATE UNIQUE INDEX calificaciones_entrega_unica_empresa ON calificaciones_entrega (calificador_id, entrega_empresa_id) WHERE entrega_empresa_id IS NOT NULL');
        DB::unprepared('CREATE UNIQUE INDEX calificaciones_entrega_unica_pedido ON calificaciones_entrega (calificador_id, pedido_dia_id) WHERE pedido_dia_id IS NOT NULL');
        Esquema::sinBorrado('calificaciones_entrega');

        // Solo se califica lo que ya se entregó.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION calificar_solo_lo_entregado() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.entrega_empresa_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM entregas_empresa WHERE id = NEW.entrega_empresa_id AND estado = 'entregada') THEN
                    RAISE EXCEPTION 'Solo se califica una entrega que ya se entregó.' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.pedido_dia_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM pedido_dias WHERE id = NEW.pedido_dia_id AND estado = 'entregada') THEN
                    RAISE EXCEPTION 'Solo se califica una entrega que ya se entregó.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::unprepared('CREATE TRIGGER calificaciones_entrega_solo_entregadas BEFORE INSERT ON calificaciones_entrega FOR EACH ROW EXECUTE FUNCTION calificar_solo_lo_entregado()');

        // ---- calificaciones del platillo (sabor y calidad)
        Schema::create('calificaciones_platillo', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('calificador_id')->constrained('users')->restrictOnDelete();
            $tabla->foreignId('menu_id')->constrained('menus')->restrictOnDelete();
            $tabla->date('fecha'); // el día en que se entregó
            $tabla->smallInteger('estrellas');
            $tabla->text('comentario')->nullable();
            Esquema::marcasDeTiempo($tabla);
            $tabla->unique(['calificador_id', 'menu_id', 'fecha']);
            $tabla->index('fecha');
        });
        Esquema::check('calificaciones_platillo', 'estrellas_validas', 'estrellas BETWEEN 1 AND 5');
        Esquema::sinBorrado('calificaciones_platillo');

        // ---- costos operativos (otros gastos del balance de utilidad)
        Schema::create('costos_operativos', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->date('fecha');
            $tabla->string('categoria', 20);
            $tabla->string('descripcion', 255);
            $tabla->decimal('monto', 10, 2);
            $tabla->foreignId('registrado_por')->constrained('users')->restrictOnDelete();
            Esquema::columnasDeDesactivacion($tabla); // anular = desactivar
            Esquema::marcasDeTiempo($tabla);
            $tabla->index('fecha');
        });
        Esquema::enumerado('costos_operativos', 'categoria', ['reparto', 'empaque', 'nomina', 'tecnologia', 'otro']);
        Esquema::check('costos_operativos', 'monto_positivo', 'monto > 0');
        Esquema::desactivacionLogica('costos_operativos');

        // ---- incidencias: dos tipos nuevos
        DB::unprepared('ALTER TABLE incidencias DROP CONSTRAINT incidencias_tipo_valido');
        Esquema::enumerado('incidencias', 'tipo', [
            'empresa_cerrada', 'empleado_ausente', 'repartidor_no_llego', 'platillo_danado', 'falta_repartidores', 'pago_pendiente', 'ruta_excede_bloque',
            'entrega_no_localizada', 'otro', 'comida_fria', 'entrega_tardia',
        ]);

        // ---- configuración: ahorro estimado y tope del pago a cocinas
        DB::unprepared('ALTER TABLE configuracion DROP CONSTRAINT configuracion_clave_conocida');
        DB::unprepared(<<<'SQL'
            ALTER TABLE configuracion
                ADD CONSTRAINT configuracion_clave_conocida
                    CHECK (clave IN ('hora_corte', 'regla_sin_eleccion', 'dias_entrega', 'capacidad_auto_entregas', 'capacidad_auto_platillos', 'ahorro_apps_porcentaje', 'costo_maquila_limite')),
                ADD CONSTRAINT configuracion_ahorro_apps_valido
                    CHECK (clave <> 'ahorro_apps_porcentaje' OR valor ~ '^(100|[0-9]{1,2})(\.[0-9]{1,2})?$'),
                ADD CONSTRAINT configuracion_costo_maquila_valido
                    CHECK (clave <> 'costo_maquila_limite' OR valor ~ '^[1-9][0-9]{0,5}(\.[0-9]{1,2})?$')
        SQL);
        DB::table('configuracion')->insert([
            ['clave' => 'ahorro_apps_porcentaje', 'valor' => '30', 'actualizado_en' => now()],
            ['clave' => 'costo_maquila_limite', 'valor' => '49', 'actualizado_en' => now()],
        ]);
    }

    public function down(): void
    {
        DB::table('configuracion')->whereIn('clave', ['ahorro_apps_porcentaje', 'costo_maquila_limite'])->delete();
        DB::unprepared('ALTER TABLE configuracion DROP CONSTRAINT configuracion_clave_conocida, DROP CONSTRAINT configuracion_ahorro_apps_valido, DROP CONSTRAINT configuracion_costo_maquila_valido');
        DB::unprepared("ALTER TABLE configuracion ADD CONSTRAINT configuracion_clave_conocida CHECK (clave IN ('hora_corte', 'regla_sin_eleccion', 'dias_entrega', 'capacidad_auto_entregas', 'capacidad_auto_platillos'))");

        DB::unprepared("UPDATE incidencias SET tipo = 'otro' WHERE tipo IN ('comida_fria', 'entrega_tardia')");
        DB::unprepared('ALTER TABLE incidencias DROP CONSTRAINT incidencias_tipo_valido');
        Esquema::enumerado('incidencias', 'tipo', ['empresa_cerrada', 'empleado_ausente', 'repartidor_no_llego', 'platillo_danado', 'falta_repartidores', 'pago_pendiente', 'ruta_excede_bloque', 'entrega_no_localizada', 'otro']);

        Schema::dropIfExists('costos_operativos');
        Schema::dropIfExists('calificaciones_platillo');
        DB::unprepared('DROP TRIGGER IF EXISTS calificaciones_entrega_solo_entregadas ON calificaciones_entrega');
        DB::unprepared('DROP FUNCTION IF EXISTS calificar_solo_lo_entregado()');
        Schema::dropIfExists('calificaciones_entrega');
    }
};
