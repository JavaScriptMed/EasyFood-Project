<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EntregaEmpresa extends ModeloEasyLunch
{
    protected $table = 'entregas_empresa';

    protected $fillable = [
        'corte_id',
        'empresa_id',
        'ruta_id',
        'fecha',
        'platillos',
        'ventana_inicio',
        'ventana_fin',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'platillos' => 'integer',
        ];
    }

    public function corte(): BelongsTo
    {
        return $this->belongsTo(Corte::class, 'corte_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function ruta(): BelongsTo
    {
        return $this->belongsTo(Ruta::class, 'ruta_id');
    }

    public function paradas(): HasMany
    {
        return $this->hasMany(Parada::class, 'entrega_empresa_id');
    }

    public function incidencias(): HasMany
    {
        return $this->hasMany(Incidencia::class, 'entrega_empresa_id');
    }
}
