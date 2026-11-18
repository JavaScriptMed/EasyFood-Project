<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un mensaje de ticket puede llevar un archivo (foto del problema, comprobante, PDF). Puede ir sin texto si trae archivo:
     * el CHECK «texto con contenido» pasa a «texto con contenido o con adjunto».
     */
    public function up(): void
    {
        Schema::table('mensajes_ticket', function (Blueprint $tabla) {
            $tabla->string('adjunto_ruta', 255)->nullable();
            $tabla->string('adjunto_nombre', 150)->nullable();
            $tabla->string('adjunto_tipo', 100)->nullable();
        });

        DB::unprepared('ALTER TABLE mensajes_ticket DROP CONSTRAINT mensajes_ticket_texto_con_contenido');
        DB::unprepared('ALTER TABLE mensajes_ticket ADD CONSTRAINT mensajes_ticket_texto_con_contenido CHECK (length(btrim(texto)) > 0 OR adjunto_ruta IS NOT NULL)');
        DB::unprepared('ALTER TABLE mensajes_ticket ADD CONSTRAINT mensajes_ticket_adjunto_completo CHECK ((adjunto_ruta IS NULL) = (adjunto_nombre IS NULL) AND (adjunto_ruta IS NULL) = (adjunto_tipo IS NULL))');
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE mensajes_ticket DROP CONSTRAINT mensajes_ticket_adjunto_completo');
        DB::unprepared("UPDATE mensajes_ticket SET texto = '(archivo adjunto)' WHERE length(btrim(texto)) = 0");
        DB::unprepared('ALTER TABLE mensajes_ticket DROP CONSTRAINT mensajes_ticket_texto_con_contenido');
        DB::unprepared('ALTER TABLE mensajes_ticket ADD CONSTRAINT mensajes_ticket_texto_con_contenido CHECK (length(btrim(texto)) > 0)');
        Schema::table('mensajes_ticket', function (Blueprint $tabla) {
            $tabla->dropColumn(['adjunto_ruta', 'adjunto_nombre', 'adjunto_tipo']);
        });
    }
};
