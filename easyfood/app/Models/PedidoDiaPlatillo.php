<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un platillo de un pedido individual: el día en que se come (`fecha`) y su tiempo de comida; viaja en la entrega del sábado. */
class PedidoDiaPlatillo extends ModeloEasyLunch
{
    protected $table = 'pedido_dia_platillos';

    protected $fillable = ['pedido_dia_id', 'fecha', 'tiempo_comida', 'menu_id', 'precio_congelado', 'es_extra'];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d', 'precio_congelado' => 'decimal:2', 'es_extra' => 'boolean'];
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
