<?php

use App\Support\Database\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un pedido individual lleva 1, 2 o 3 comidas por día (desayuno, comida, cena), a elección del cliente.
     * `pedido_dias` sigue siendo el día de entrega (una parada, un estado, una evidencia); lo que se come ese día
     * pasa a `pedido_dia_platillos`, una fila por tiempo de comida, cada una con su platillo y su precio congelado.
     * Además el cliente puede sumar un platillo extra por día (`con_extra`): es otra fila del día, marcada `es_extra`,
     * de cualquier tiempo de comida (puede repetir el tiempo de una comida base, por ejemplo una segunda comida).
     */
    public function up(): void
    {
        Schema::table('pedidos', function (Blueprint $tabla) {
            $tabla->smallInteger('comidas_por_dia')->default(1);
            $tabla->boolean('con_extra')->default(false);
        });
        Esquema::check('pedidos', 'comidas_por_dia_valido', 'comidas_por_dia BETWEEN 1 AND 3');

        Schema::create('pedido_dia_platillos', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('pedido_dia_id')->constrained('pedido_dias')->restrictOnDelete();
            $tabla->string('tiempo_comida', 10);
            $tabla->foreignId('menu_id')->constrained('menus')->restrictOnDelete();
            $tabla->decimal('precio_congelado', 10, 2);
            $tabla->boolean('es_extra')->default(false);
            Esquema::marcasDeTiempo($tabla);
            $tabla->index('menu_id');
        });
        // Una comida base por tiempo de comida y a lo más un extra por día.
        DB::unprepared('CREATE UNIQUE INDEX pedido_dia_platillos_un_platillo_por_tiempo ON pedido_dia_platillos (pedido_dia_id, tiempo_comida) WHERE NOT es_extra');
        DB::unprepared('CREATE UNIQUE INDEX pedido_dia_platillos_un_extra_por_dia ON pedido_dia_platillos (pedido_dia_id) WHERE es_extra');
        Esquema::enumerado('pedido_dia_platillos', 'tiempo_comida', ['desayuno', 'comida', 'cena']);
        Esquema::check('pedido_dia_platillos', 'precio_positivo', 'precio_congelado > 0');
        Esquema::sinBorrado('pedido_dia_platillos');

        // El platillo tiene que ser del tiempo de comida en el que se pone.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION platillo_del_tiempo_de_comida() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM menus WHERE id = NEW.menu_id AND tiempo_comida = NEW.tiempo_comida) THEN
                    RAISE EXCEPTION 'El platillo % no es de %.', NEW.menu_id, NEW.tiempo_comida USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);
        DB::unprepared('CREATE TRIGGER pedido_dia_platillos_tiempo_coherente BEFORE INSERT OR UPDATE ON pedido_dia_platillos FOR EACH ROW EXECUTE FUNCTION platillo_del_tiempo_de_comida()');

        // Los pedidos que ya existían tenían un platillo por día: pasan a ser pedidos de 1 comida por día.
        DB::unprepared(<<<'SQL'
            INSERT INTO pedido_dia_platillos (pedido_dia_id, tiempo_comida, menu_id, precio_congelado)
            SELECT d.id, m.tiempo_comida, d.menu_id, d.precio_congelado FROM pedido_dias d JOIN menus m ON m.id = d.menu_id
        SQL);

        Schema::table('pedido_dias', function (Blueprint $tabla) {
            $tabla->dropConstrainedForeignId('menu_id');
            $tabla->dropColumn('precio_congelado');
        });
    }

    public function down(): void
    {
        Schema::table('pedido_dias', function (Blueprint $tabla) {
            $tabla->foreignId('menu_id')->nullable()->constrained('menus')->restrictOnDelete();
            $tabla->decimal('precio_congelado', 10, 2)->nullable();
        });
        DB::unprepared(<<<'SQL'
            UPDATE pedido_dias d SET menu_id = p.menu_id, precio_congelado = p.precio_congelado
            FROM (SELECT DISTINCT ON (pedido_dia_id) pedido_dia_id, menu_id, precio_congelado FROM pedido_dia_platillos ORDER BY pedido_dia_id, id) p
            WHERE p.pedido_dia_id = d.id
        SQL);
        DB::unprepared('ALTER TABLE pedido_dias ALTER COLUMN menu_id SET NOT NULL, ALTER COLUMN precio_congelado SET NOT NULL');
        DB::unprepared('DROP TRIGGER IF EXISTS pedido_dia_platillos_tiempo_coherente ON pedido_dia_platillos');
        DB::unprepared('DROP FUNCTION IF EXISTS platillo_del_tiempo_de_comida()');
        Schema::dropIfExists('pedido_dia_platillos');
        DB::unprepared('ALTER TABLE pedidos DROP CONSTRAINT IF EXISTS pedidos_comidas_por_dia_valido');
        Schema::table('pedidos', function (Blueprint $tabla) {
            $tabla->dropColumn(['comidas_por_dia', 'con_extra']);
        });
    }
};
