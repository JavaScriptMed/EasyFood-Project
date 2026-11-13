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
        Schema::create('liquidaciones_comedor', function (Blueprint $tabla) {
            $tabla->id();
            // El comedor es un registro de providers (decisión del equipo).
            $tabla->foreignId('comedor_id')->constrained('providers')->restrictOnDelete();
            $tabla->date('mes');
            $tabla->integer('platillos_entregados');
            $tabla->decimal('precio_por_platillo', 10, 2);
            $tabla->integer('platillos_minimos');
            $tabla->integer('platillos_faltantes')->default(0);
            $tabla->decimal('diferencia_minimo', 10, 2)->default(0);
            $tabla->decimal('monto', 10, 2);
            $tabla->string('estado', 10)->default('pendiente');
            $tabla->timestampTz('pagada_en')->nullable();
            Esquema::marcasDeTiempo($tabla);

            // Una liquidación por comedor y mes.
            $tabla->unique(['comedor_id', 'mes']);
        });

        Esquema::enumerado('liquidaciones_comedor', 'estado', ['pendiente', 'pagado']);
        Esquema::primerDiaDeMes('liquidaciones_comedor');
        Esquema::check('liquidaciones_comedor', 'valores_validos', 'platillos_entregados >= 0 AND precio_por_platillo > 0 AND platillos_minimos > 0 AND platillos_faltantes >= 0 AND diferencia_minimo >= 0 AND monto >= 0');
        // Regla del contrato: si EasyLunch compra menos del mínimo, paga la diferencia.
        Esquema::check('liquidaciones_comedor', 'faltantes_cuadran', 'platillos_faltantes = GREATEST(0, platillos_minimos - platillos_entregados)');
        Esquema::check('liquidaciones_comedor', 'diferencia_cuadra', 'diferencia_minimo = platillos_faltantes * precio_por_platillo');
        Esquema::check('liquidaciones_comedor', 'monto_cuadra', 'monto = (platillos_entregados + platillos_faltantes) * precio_por_platillo');
        Esquema::check('liquidaciones_comedor', 'pago_coherente', "(estado = 'pagado') = (pagada_en IS NOT NULL)");
        Esquema::sinBorrado('liquidaciones_comedor');
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidaciones_comedor');
    }
};
