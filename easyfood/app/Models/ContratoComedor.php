<?php

namespace App\Models;

use App\Models\Concerns\Desactivable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContratoComedor extends ModeloEasyLunch
{
    use Desactivable;

    protected $table = 'contratos_comedor';

    protected $fillable = [
        'comedor_id',
        'precio_por_platillo',
        'compra_minima_mensual',
        'vigencia_inicio',
        'vigencia_fin',
    ];

    protected function casts(): array
    {
        return [
            'precio_por_platillo' => 'decimal:2',
            'compra_minima_mensual' => 'integer',
            'vigencia_inicio' => 'date:Y-m-d',
            'vigencia_fin' => 'date:Y-m-d',
            'activo' => 'boolean',
            'desactivado_en' => 'datetime',
        ];
    }

    public function comedor(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'comedor_id');
    }
}
