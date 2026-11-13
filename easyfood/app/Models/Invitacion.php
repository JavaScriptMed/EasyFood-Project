<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invitacion extends ModeloEasyLunch
{
    protected $table = 'invitaciones';

    protected $fillable = [
        'usuario_id',
        'correo',
        'rol_id',
        'organizacion_tipo',
        'comedor_id',
        'empresa_id',
        'token_hash',
        'enviada_en',
        'vence_en',
        'aceptada_en',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'enviada_en' => 'datetime',
            'vence_en' => 'datetime',
            'aceptada_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'rol_id');
    }

    public function comedor(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'comedor_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}
