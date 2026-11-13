<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrdenProduccion extends ModeloEasyLunch
{
    protected $table = 'ordenes_produccion';

    protected $fillable = [
        'corte_id',
        'comedor_id',
        'menu_id',
        'fecha',
        'porciones_totales',
        'porciones_empresas',
        'porciones_individuales',
        'hora_recoleccion',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'porciones_totales' => 'integer',
            'porciones_empresas' => 'integer',
            'porciones_individuales' => 'integer',
        ];
    }

    public function corte(): BelongsTo
    {
        return $this->belongsTo(Corte::class, 'corte_id');
    }

    public function comedor(): BelongsTo
    {
        return $this->belongsTo(Provider::class, 'comedor_id');
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }
}
