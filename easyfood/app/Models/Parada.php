<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Parada extends ModeloEasyLunch
{
    protected $table = 'paradas';

    protected $fillable = [
        'ruta_id',
        'orden',
        'entrega_empresa_id',
        'pedido_dia_id',
        'nombre_destinatario',
        'direccion',
        'hora_estimada',
        'estado',
    ];

    protected $hidden = ['ubicacion'];

    // `ubicacion` es geography(Point,4326): no se asigna en masa ni se lee como atributo. Se escribe y consulta con SQL de PostGIS (ST_MakePoint, ST_X/ST_Y).

    protected function casts(): array
    {
        return [
            'orden' => 'integer',
        ];
    }

    public function ruta(): BelongsTo
    {
        return $this->belongsTo(Ruta::class, 'ruta_id');
    }

    public function entregaEmpresa(): BelongsTo
    {
        return $this->belongsTo(EntregaEmpresa::class, 'entrega_empresa_id');
    }

    public function pedidoDia(): BelongsTo
    {
        return $this->belongsTo(PedidoDia::class, 'pedido_dia_id');
    }
}
