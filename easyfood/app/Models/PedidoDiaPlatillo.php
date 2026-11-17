<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un platillo de un día de entrega de un pedido individual: uno por tiempo de comida. */
class PedidoDiaPlatillo extends ModeloEasyLunch
{
    protected $table = 'pedido_dia_platillos';

    protected $fillable = ['pedido_dia_id', 'tiempo_comida', 'menu_id', 'precio_congelado', 'es_extra'];

    protected function casts(): array
    {
        return ['precio_congelado' => 'decimal:2', 'es_extra' => 'boolean'];
    }

    public function dia(): BelongsTo
    {
        return $this->belongsTo(PedidoDia::class, 'pedido_dia_id');
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }
}
