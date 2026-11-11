<?php

namespace App\Models;

use App\Models\Concerns\Desactivable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContratoEmpresa extends ModeloEasyLunch
{
    use Desactivable;

    protected $table = 'contratos_empresa';

    protected $fillable = [
        'empresa_id',
        'porcentaje_subsidio',
        'compra_minima_mensual',
        'precio_por_platillo',
        'vigencia_inicio',
        'vigencia_fin',
        'hora_entrega',
        'ventana_entrega_inicio',
        'ventana_entrega_fin',
    ];

    protected function casts(): array
    {
        return [
            'porcentaje_subsidio' => 'decimal:2',
            'compra_minima_mensual' => 'integer',
            'precio_por_platillo' => 'decimal:2',
            'vigencia_inicio' => 'date:Y-m-d',
            'vigencia_fin' => 'date:Y-m-d',
            'activo' => 'boolean',
            'desactivado_en' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }
}
