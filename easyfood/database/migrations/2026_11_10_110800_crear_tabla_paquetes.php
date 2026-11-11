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
        // Paquetes para clientes individuales: 1, 2 o 6 días.
        Schema::create('paquetes', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('nombre', 60)->unique();
            $tabla->smallInteger('dias');
            $tabla->decimal('descuento_porcentaje', 5, 2)->default(0);
            $tabla->string('detalle', 255)->nullable();
            $tabla->boolean('destacado')->default(false);
            Esquema::columnasDeDesactivacion($tabla);
            Esquema::marcasDeTiempo($tabla);
        });

        Esquema::check('paquetes', 'dias_validos', 'dias IN (1, 2, 6)');
        Esquema::check('paquetes', 'descuento_entre_0_y_100', 'descuento_porcentaje BETWEEN 0 AND 100');
        // Un solo paquete activo por cantidad de días.
        DB::unprepared('CREATE UNIQUE INDEX paquetes_un_activo_por_dias ON paquetes (dias) WHERE activo');
        Esquema::desactivacionLogica('paquetes');
    }

    public function down(): void
    {
        Schema::dropIfExists('paquetes');
    }
};
