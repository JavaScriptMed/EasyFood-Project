<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pedido extends ModeloEasyLunch
{
    protected $table = 'pedidos';

    protected $fillable = [
        'folio',
        'cliente_id',
        'paquete_id',
        'direccion_id',
        'fecha_inicio',
        'fecha_fin',
        'total',
        'estado',
        'metodo_pago',
        'ultimos_digitos',
        'pagado_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date:Y-m-d',
            'fecha_fin' => 'date:Y-m-d',
            'total' => 'decimal:2',
            'pagado_en' => 'datetime',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cliente_id');
    }

    public function paquete(): BelongsTo
    {
        return $this->belongsTo(Paquete::class, 'paquete_id');
    }

    public function direccion(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'direccion_id');
    }

    public function dias(): HasMany
    {
        return $this->hasMany(PedidoDia::class, 'pedido_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'pedido_id');
    }
}
