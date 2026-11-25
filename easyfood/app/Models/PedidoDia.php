<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PedidoDia extends ModeloEasyLunch
{
    protected $table = 'pedido_dias';

    protected $fillable = [
        'pedido_id',
        'fecha',
        'estado',
        'evidencia_foto_url',
        'entregada_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'entregada_en' => 'datetime',
        ];
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    /** Todo lo que lleva esta entrega: los platillos de cada día de comida, uno por tiempo de comida. */
    public function platillos(): HasMany
    {
        return $this->hasMany(PedidoDiaPlatillo::class, 'pedido_dia_id');
    }

    /** Las confirmaciones de «Recibido» de esta entrega. */
    public function recepciones(): HasMany
    {
        return $this->hasMany(Recepcion::class, 'pedido_dia_id');
    }

    public function parada(): HasOne
    {
        return $this->hasOne(Parada::class, 'pedido_dia_id');
    }
}
