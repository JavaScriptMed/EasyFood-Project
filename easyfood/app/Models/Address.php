<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Domicilio de un usuario (tabla `addresses` del equipo). Los pedidos de clientes individuales entregan aquí. */
class Address extends Model
{
    protected $fillable = [
        'user_id',
        'label',
        'street_address',
        'internal_number',
        'postal_code',
        'latitude',
        'longitude',
        'delivery_instructions',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'direccion_id');
    }
}
