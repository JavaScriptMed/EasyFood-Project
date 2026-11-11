<?php

use App\Support\Database\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Avisos para una persona (la empresa y los administradores). Uno por destinatario, contrato y fecha de vencimiento:
        // generarlos otra vez no los repite, y renovar el contrato (nueva fecha) sí genera uno nuevo.
        Schema::create('avisos', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $tabla->string('tipo', 30);
            $tabla->foreignId('contrato_empresa_id')->nullable()->constrained('contratos_empresa')->restrictOnDelete();
            $tabla->date('vigencia_fin')->nullable();
            $tabla->string('mensaje', 255);
            $tabla->timestampTz('leido_en')->nullable();
            Esquema::marcasDeTiempo($tabla);

            $tabla->unique(['usuario_id', 'tipo', 'contrato_empresa_id', 'vigencia_fin']);
            $tabla->index(['usuario_id', 'leido_en']);
        });
        Esquema::enumerado('avisos', 'tipo', ['contrato_por_vencer']);
        Esquema::check('avisos', 'contrato_por_vencer_completo', "tipo <> 'contrato_por_vencer' OR (contrato_empresa_id IS NOT NULL AND vigencia_fin IS NOT NULL)");
        Esquema::check('avisos', 'mensaje_con_texto', 'length(btrim(mensaje)) > 0');

        // Consecutivos de folio (uno por clave: 'factura:202610', 'pedido:20261109'). El UPSERT bloquea la fila,
        // así dos procesos a la vez nunca reciben el mismo número.
        Schema::create('contadores_folio', function (Blueprint $tabla) {
            $tabla->string('clave', 40)->primary();
            $tabla->unsignedInteger('valor')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contadores_folio');
        Schema::dropIfExists('avisos');
    }
};
