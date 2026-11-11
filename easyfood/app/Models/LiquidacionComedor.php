<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiquidacionComedor extends ModeloEasyLunch
{
    protected $table = 'liquidaciones_comedor';

    protected $fillable = [
        'comedor_id',
        'mes',
        'platillos_entregados',
        'precio_por_platillo',
        'platillos_minimos',
        'platillos_faltantes',
        'diferencia_minimo',
        'monto',
        'estado',
        'pagada_en',
    ];

    protected function casts(): array
    {
        return [
            'mes' => 'date:Y-m-d',
            'platillos_entregados' => 'integer',
            'precio_por_platillo' => 'decimal:2',
            'platillos_minimos' => 'integer',
            'platillos_faltantes' => 'integer',
            'diferencia_minimo' => 'decimal:2',
            'monto' => 'decimal:2',
            'pagada_en' => 'datetime',
        ];
    }

    public function comedor(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'comedor_id');
    }
}
