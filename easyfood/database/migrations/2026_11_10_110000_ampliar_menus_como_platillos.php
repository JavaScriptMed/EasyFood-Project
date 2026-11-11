<?php

use App\Support\Database\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decisión: los platillos de CLAUDE.md son la tabla `menus` del equipo. Se le agregan los datos que faltaban:
 * estado de revisión del comedor, tiempo de comida, calorías, alérgenos y etiquetas.
 * Se reutiliza lo que ya tenía: provider_id (comedor), name, description, price (precio de venta), image_url (foto),
 * is_available (activo) y softDeletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->string('estado_revision', 20)->default('borrador');
            $table->text('motivo_rechazo')->nullable();
            $table->string('tiempo_comida', 10)->default('comida');
            $table->unsignedInteger('calorias')->nullable();
        });
        DB::unprepared("ALTER TABLE menus ADD COLUMN alergenos text[] NOT NULL DEFAULT '{}', ADD COLUMN etiquetas text[] NOT NULL DEFAULT '{}'");

        Esquema::enumerado('menus', 'estado_revision', ['borrador', 'en_revision', 'publicado', 'rechazado']);
        Esquema::enumerado('menus', 'tiempo_comida', ['desayuno', 'comida', 'cena']);
        Esquema::check('menus', 'rechazo_con_motivo', "estado_revision <> 'rechazado' OR (motivo_rechazo IS NOT NULL AND length(btrim(motivo_rechazo)) > 0)");
        Esquema::check('menus', 'precio_positivo', 'price > 0');

        // Los platillos que ya existían estaban a la venta: se conservan como publicados.
        DB::table('menus')->update(['estado_revision' => 'publicado']);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE menus DROP CONSTRAINT IF EXISTS menus_precio_positivo, DROP CONSTRAINT IF EXISTS menus_rechazo_con_motivo, DROP CONSTRAINT IF EXISTS menus_tiempo_comida_valido, DROP CONSTRAINT IF EXISTS menus_estado_revision_valido');
        DB::unprepared('ALTER TABLE menus DROP COLUMN etiquetas, DROP COLUMN alergenos');
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn(['estado_revision', 'motivo_rechazo', 'tiempo_comida', 'calorias']);
        });
    }
};
