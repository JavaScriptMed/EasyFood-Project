<?php

use App\Support\Database\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial de precios de venta de cada platillo. `menus.price` sigue siendo el precio de hoy (todo lo demás lo lee de ahí);
     * esta tabla guarda cuánto valió y cuánto valdrá: una fila por tramo, con el costo (lo que se paga al comedor) de ese momento.
     * Un platillo tiene a lo más un tramo abierto (sin `vigente_hasta`): el último, que puede ser un precio programado a futuro.
     * Un comando diario copia a `menus.price` el precio que entra en vigor.
     */
    public function up(): void
    {
        Schema::create('precios_de_platillos', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('menu_id')->constrained('menus')->restrictOnDelete();
            $tabla->decimal('precio_venta', 10, 2);
            $tabla->decimal('costo', 10, 2)->default(0);
            $tabla->date('vigente_desde');
            $tabla->date('vigente_hasta')->nullable();
            Esquema::marcasDeTiempo($tabla);

            $tabla->unique(['menu_id', 'vigente_desde']);
        });

        Esquema::check('precios_de_platillos', 'precio_positivo', 'precio_venta > 0');
        Esquema::check('precios_de_platillos', 'costo_no_negativo', 'costo >= 0');
        Esquema::check('precios_de_platillos', 'tramo_valido', 'vigente_hasta IS NULL OR vigente_hasta >= vigente_desde');
        DB::unprepared('CREATE UNIQUE INDEX precios_de_platillos_un_tramo_abierto ON precios_de_platillos (menu_id) WHERE vigente_hasta IS NULL');
        Esquema::sinBorrado('precios_de_platillos');

        // Los platillos que ya existen arrancan con su precio de hoy, desde el día en que se crearon.
        DB::unprepared(<<<'SQL'
            INSERT INTO precios_de_platillos (menu_id, precio_venta, costo, vigente_desde)
            SELECT m.id, m.price,
                   COALESCE((SELECT c.precio_por_platillo FROM contratos_comedor c WHERE c.comedor_id = m.provider_id AND c.activo ORDER BY c.vigencia_inicio DESC LIMIT 1), 0),
                   LEAST(COALESCE(m.created_at::date, CURRENT_DATE), CURRENT_DATE)
            FROM menus m
            WHERE m.price > 0
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('precios_de_platillos');
    }
};
