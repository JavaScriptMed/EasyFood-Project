<?php

use App\Support\Database\Esquema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enlaces para restablecer la contraseña. Igual que las invitaciones, solo se guarda el hash del enlace.
     * Un enlace sirve una vez (`usado_en`) y vence a la hora; pedir otro deja sin efecto los anteriores.
     */
    public function up(): void
    {
        Schema::create('restablecimientos', function (Blueprint $tabla) {
            $tabla->id();
            $tabla->foreignId('usuario_id')->constrained('users')->restrictOnDelete();
            $tabla->char('token_hash', 64)->unique();
            $tabla->timestampTz('vence_en');
            $tabla->timestampTz('usado_en')->nullable();
            Esquema::marcasDeTiempo($tabla);
            $tabla->index('usuario_id');
        });
        Esquema::check('restablecimientos', 'token_hexadecimal', "token_hash ~ '^[0-9a-f]{64}$'");
        Esquema::sinBorrado('restablecimientos');
    }

    public function down(): void
    {
        Schema::dropIfExists('restablecimientos');
    }
};
