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
        Schema::create('invitaciones', function (Blueprint $tabla) {
            $tabla->id();
            // La cuenta nace al invitar (inactiva) y se activa cuando la persona acepta.
            $tabla->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $tabla->string('correo', 190);
            $tabla->foreignId('rol_id')->constrained('roles')->restrictOnDelete();
            $tabla->string('organizacion_tipo', 10);
            $tabla->foreignId('comedor_id')->nullable()->constrained('providers')->restrictOnDelete();
            $tabla->foreignId('empresa_id')->nullable()->constrained('empresas')->restrictOnDelete();
            // Se guarda el hash (SHA-256 en hexadecimal) del enlace, no el enlace: si se filtra la base, los enlaces no sirven.
            $tabla->char('token_hash', 64)->unique();
            $tabla->timestampTz('enviada_en')->useCurrent();
            $tabla->timestampTz('vence_en');
            $tabla->timestampTz('aceptada_en')->nullable();
            Esquema::marcasDeTiempo($tabla);

            $tabla->index('usuario_id');
        });

        Esquema::enumerado('invitaciones', 'organizacion_tipo', ['comedor', 'empresa', 'plataforma']);
        Esquema::check('invitaciones', 'organizacion_coherente', "(organizacion_tipo = 'comedor' AND comedor_id IS NOT NULL AND empresa_id IS NULL) OR (organizacion_tipo = 'empresa' AND empresa_id IS NOT NULL AND comedor_id IS NULL) OR (organizacion_tipo = 'plataforma' AND comedor_id IS NULL AND empresa_id IS NULL)");
        Esquema::check('invitaciones', 'vigencia_valida', 'vence_en > enviada_en');
        Esquema::check('invitaciones', 'token_hexadecimal', "token_hash ~ '^[0-9a-f]{64}$'");
        Esquema::sinBorrado('invitaciones');
    }

    public function down(): void
    {
        Schema::dropIfExists('invitaciones');
    }
};
