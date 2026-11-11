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
        Schema::create('incidencias', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->date('fecha');
            $tabla->string('tipo', 25);
            $tabla->text('descripcion');
            $tabla->string('prioridad', 10)->default('normal');
            $tabla->string('estado', 12)->default('abierta');
            $tabla->time('hora')->nullable();
            // Foto de evidencia (URL del archivo) y nota de quien la atiende.
            $tabla->string('foto_url', 255)->nullable();
            $tabla->text('nota')->nullable();
            $tabla->foreignId('ruta_id')->nullable()->constrained('rutas')->restrictOnDelete();
            $tabla->foreignId('entrega_empresa_id')->nullable()->constrained('entregas_empresa')->restrictOnDelete();
            // A quién se reasignó la ruta cuando el estado es "reasignada".
            $tabla->foreignId('repartidor_reasignado_id')->nullable()->constrained('repartidores')->restrictOnDelete();
            $tabla->timestampTz('resuelta_en')->nullable();
            Esquema::marcasDeTiempo($tabla);

            $tabla->index(['fecha', 'estado']);
        });

        Esquema::enumerado('incidencias', 'tipo', ['empresa_cerrada', 'empleado_ausente', 'repartidor_no_llego', 'platillo_danado', 'falta_repartidores', 'pago_pendiente', 'ruta_excede_bloque', 'entrega_no_localizada', 'otro']);
        Esquema::enumerado('incidencias', 'prioridad', ['normal', 'inmediata']);
        Esquema::enumerado('incidencias', 'estado', ['abierta', 'reasignada', 'resuelta']);
        Esquema::check('incidencias', 'resolucion_coherente', "(estado = 'resuelta') = (resuelta_en IS NOT NULL)");
        Esquema::check('incidencias', 'reasignacion_coherente', "estado <> 'reasignada' OR (repartidor_reasignado_id IS NOT NULL AND ruta_id IS NOT NULL)");
        Esquema::sinBorrado('incidencias');
    }

    public function down(): void
    {
        Schema::dropIfExists('incidencias');
    }
};
