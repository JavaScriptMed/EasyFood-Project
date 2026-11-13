<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiaSinServicio extends ModeloEasyLunch
{
    protected $table = 'dias_sin_servicio';

    protected $fillable = [
        'fecha',
        'tipo',
        'motivo',
        'empresa_id',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}
