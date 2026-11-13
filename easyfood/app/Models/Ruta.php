<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ruta extends ModeloEasyLunch
{
    protected $table = 'rutas';

    protected $fillable = [
        'fecha',
        'repartidor_id',
        'corte_id',
        'zona',
        'ventana_inicio',
        'ventana_fin',
        'capacidad_entregas',
        'capacidad_platillos',
        'entregas_asignadas',
        'platillos_asignados',
        'distancia_km',
        'hora_fin_estimada',
        'excede_bloque',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'capacidad_entregas' => 'integer',
            'capacidad_platillos' => 'integer',
            'entregas_asignadas' => 'integer',
            'platillos_asignados' => 'integer',
            'distancia_km' => 'decimal:2',
            'excede_bloque' => 'boolean',
        ];
    }

    public function repartidor(): BelongsTo
    {
        return $this->belongsTo(Repartidor::class, 'repartidor_id');
    }

    public function corte(): BelongsTo
    {
        return $this->belongsTo(Corte::class, 'corte_id');
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(EntregaEmpresa::class, 'ruta_id');
    }

    public function paradas(): HasMany
    {
        return $this->hasMany(Parada::class, 'ruta_id');
    }

    public function incidencias(): HasMany
    {
        return $this->hasMany(Incidencia::class, 'ruta_id');
    }
}
