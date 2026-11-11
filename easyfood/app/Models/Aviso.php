<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Aviso extends ModeloEasyLunch
{
    protected $table = 'avisos';

    protected $fillable = ['usuario_id', 'tipo', 'contrato_empresa_id', 'vigencia_fin', 'mensaje', 'leido_en'];

    protected function casts(): array
    {
        return [
            'vigencia_fin' => 'date:Y-m-d',
            'leido_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function contrato(): BelongsTo
    {
        return $this->belongsTo(ContratoEmpresa::class, 'contrato_empresa_id');
    }
}
