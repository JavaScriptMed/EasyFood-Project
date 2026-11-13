<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PedidoDia extends ModeloEasyLunch
{
    protected $table = 'pedido_dias';

    protected $fillable = [
        'pedido_id',
        'fecha',
        'menu_id',
        'precio_congelado',
        'estado',
        'evidencia_foto_url',
        'entregada_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'precio_congelado' => 'decimal:2',
            'entregada_en' => 'datetime',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }

    public function parada(): HasOne
    {
        return $this->hasOne(Parada::class, 'pedido_dia_id');
    }
}
