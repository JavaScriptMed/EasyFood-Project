<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un enlace para restablecer la contraseña (solo se guarda su hash). */
class Restablecimiento extends ModeloEasyLunch
{
    protected $table = 'restablecimientos';

    protected $fillable = ['usuario_id', 'token_hash', 'vence_en', 'usado_en'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['vence_en' => 'datetime', 'usado_en' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
