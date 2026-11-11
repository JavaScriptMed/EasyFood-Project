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
        // Mensajes del formulario de contacto de la página de inicio (público, sin cuenta).
        Schema::create('mensajes_contacto', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->string('nombre', 120);
            $tabla->string('correo', 190);
            $tabla->string('empresa', 150)->nullable();
            $tabla->text('mensaje');
            $tabla->timestampTz('enviado_en')->useCurrent();

            $tabla->index('enviado_en');
        });

        Esquema::check('mensajes_contacto', 'nombre_con_texto', 'length(btrim(nombre)) >= 2');
        Esquema::check('mensajes_contacto', 'correo_con_formato', "correo ~* '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'");
        Esquema::check('mensajes_contacto', 'mensaje_con_texto', 'length(btrim(mensaje)) >= 10');
        Esquema::sinBorrado('mensajes_contacto');
    }

    public function down(): void
    {
        Schema::dropIfExists('mensajes_contacto');
    }
};
