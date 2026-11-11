<?php

namespace App\Models;

use App\Models\Concerns\Desactivable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Paquete extends ModeloEasyLunch
{
    use Desactivable;

    protected $table = 'paquetes';

    protected $fillable = [
        'nombre',
        'dias',
        'descuento_porcentaje',
        'detalle',
        'destacado',
    ];

    protected function casts(): array
    {
        return [
            'dias' => 'integer',
            'descuento_porcentaje' => 'decimal:2',
            'destacado' => 'boolean',
            'activo' => 'boolean',
            'desactivado_en' => 'datetime',
        ];
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Pedido::class, 'paquete_id');
    }
}
