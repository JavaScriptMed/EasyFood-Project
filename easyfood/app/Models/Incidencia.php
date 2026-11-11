<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Incidencia extends ModeloEasyLunch
{
    protected $table = 'incidencias';

    protected $fillable = [
        'fecha',
        'tipo',
        'descripcion',
        'prioridad',
        'estado',
        'hora',
        'foto_url',
        'nota',
        'ruta_id',
        'entrega_empresa_id',
        'repartidor_reasignado_id',
        'resuelta_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'resuelta_en' => 'datetime',
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

    public function repartidorReasignado(): BelongsTo
    {
        return $this->belongsTo(Repartidor::class, 'repartidor_reasignado_id');
    }
}
