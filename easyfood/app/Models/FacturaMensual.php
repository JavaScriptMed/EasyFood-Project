<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacturaMensual extends ModeloEasyLunch
{
    protected $table = 'facturas_mensuales';

    protected $fillable = [
        'folio',
        'empresa_id',
        'mes',
        'platillos',
        'parte_empresa',
        'parte_empleados',
        'platillos_faltantes',
        'diferencia_minimo',
        'total',
        'estado',
        'emitida_en',
        'pagada_en',
    ];

    protected function casts(): array
    {
        return [
            'mes' => 'date:Y-m-d',
            'platillos' => 'integer',
            'parte_empresa' => 'decimal:2',
            'parte_empleados' => 'decimal:2',
            'platillos_faltantes' => 'integer',
            'diferencia_minimo' => 'decimal:2',
            'total' => 'decimal:2',
            'emitida_en' => 'datetime',
            'pagada_en' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}
