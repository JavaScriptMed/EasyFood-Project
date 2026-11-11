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
        // Calendario de servicio: festivos, días sin servicio y cierres de una empresa en un día. El sistema no genera pedidos esos días.
        Schema::create('dias_sin_servicio', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->date('fecha');
            $tabla->string('tipo', 15);
            $tabla->string('motivo', 255);
            $tabla->foreignId('empresa_id')->nullable()->constrained('empresas')->restrictOnDelete();
            Esquema::marcasDeTiempo($tabla);

            $tabla->index('fecha');
        });

        Esquema::enumerado('dias_sin_servicio', 'tipo', ['festivo', 'sin_servicio', 'cierre_empresa']);
        // Solo el cierre de empresa lleva empresa; festivos y días sin servicio son para todos.
        Esquema::check('dias_sin_servicio', 'empresa_segun_tipo', "(tipo = 'cierre_empresa') = (empresa_id IS NOT NULL)");
        Esquema::check('dias_sin_servicio', 'motivo_con_texto', 'length(btrim(motivo)) > 0');
        // Un día general se marca una sola vez; una empresa se cierra una sola vez por día.
        DB::unprepared('CREATE UNIQUE INDEX dias_sin_servicio_general_unico ON dias_sin_servicio (fecha) WHERE empresa_id IS NULL');
        DB::unprepared('CREATE UNIQUE INDEX dias_sin_servicio_empresa_unico ON dias_sin_servicio (fecha, empresa_id) WHERE empresa_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('dias_sin_servicio');
    }
};
