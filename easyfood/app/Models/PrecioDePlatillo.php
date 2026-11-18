<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrecioDePlatillo extends ModeloEasyLunch
{
    protected $table = 'precios_de_platillos';

    protected $fillable = ['menu_id', 'precio_venta', 'costo', 'vigente_desde', 'vigente_hasta'];

    protected function casts(): array
    {
        return [
            'precio_venta' => 'decimal:2',
            'costo' => 'decimal:2',
            'vigente_desde' => 'date:Y-m-d',
            'vigente_hasta' => 'date:Y-m-d',
        ];
    }

    public function platillo(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }
}
