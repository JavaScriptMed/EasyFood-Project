<?php

use App\Support\Database\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `recepciones`: quien recibe la comida (un cliente individual o un empleado) confirma con «Recibido» que le llegó. Es una constancia
     * aparte: no cambia el estado de la entrega (eso lo marca el repartidor) ni nada de dinero. Una por persona y entrega, solo de lo ya
     * entregado, y no se borra. Es una tabla nueva: no modifica ninguna existente.
     */
    public function up(): void
    {
        Schema::create('recepciones', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $tabla->foreignId('entrega_empresa_id')->nullable()->constrained('entregas_empresa')->restrictOnDelete();
            $tabla->foreignId('pedido_dia_id')->nullable()->constrained('pedido_dias')->restrictOnDelete();
            $tabla->timestampTz('confirmada_en');
            Esquema::marcasDeTiempo($tabla);
        });
        Esquema::check('recepciones', 'una_sola_entrega', '(entrega_empresa_id IS NULL) <> (pedido_dia_id IS NULL)');
        DB::unprepared('CREATE UNIQUE INDEX recepciones_unica_empresa ON recepciones (usuario_id, entrega_empresa_id) WHERE entrega_empresa_id IS NOT NULL');
        DB::unprepared('CREATE UNIQUE INDEX recepciones_unica_pedido ON recepciones (usuario_id, pedido_dia_id) WHERE pedido_dia_id IS NOT NULL');
        Esquema::sinBorrado('recepciones');

        // Solo se confirma lo que el repartidor ya marcó como entregado.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION recibir_solo_lo_entregado() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.entrega_empresa_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM entregas_empresa WHERE id = NEW.entrega_empresa_id AND estado = 'entregada') THEN
                    RAISE EXCEPTION 'Solo se confirma una entrega que ya se entregó.' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.pedido_dia_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM pedido_dias WHERE id = NEW.pedido_dia_id AND estado = 'entregada') THEN
                    RAISE EXCEPTION 'Solo se confirma una entrega que ya se entregó.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::unprepared('CREATE TRIGGER recepciones_solo_entregadas BEFORE INSERT ON recepciones FOR EACH ROW EXECUTE FUNCTION recibir_solo_lo_entregado()');
    }

    public function down(): void
    {
        Schema::dropIfExists('recepciones');
        DB::unprepared('DROP FUNCTION IF EXISTS recibir_solo_lo_entregado()');
    }
};
