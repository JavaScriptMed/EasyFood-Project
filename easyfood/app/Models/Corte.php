<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Corte extends ModeloEasyLunch
{
    protected $table = 'cortes';

    public $timestamps = false;

    protected $fillable = [
        'fecha_entrega',
        'ejecutado_en',
        'ejecutado_por',
        'estado',
        'sin_cambios',
        'elecciones_cerradas',
        'empleados_por_defecto',
        'ordenes_generadas',
        'entregas_consolidadas',
        'rutas_creadas',
        'mensaje',
    ];

    protected function casts(): array
    {
        return [
            'fecha_entrega' => 'date:Y-m-d',
            'ejecutado_en' => 'datetime',
            'sin_cambios' => 'boolean',
            'elecciones_cerradas' => 'integer',
            'empleados_por_defecto' => 'integer',
            'ordenes_generadas' => 'integer',
            'entregas_consolidadas' => 'integer',
            'rutas_creadas' => 'integer',
        ];
    }

    public function ejecutadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ejecutado_por');
    }

    public function ordenesProduccion(): HasMany
    {
        return $this->hasMany(OrdenProduccion::class, 'corte_id');
    }

    public function entregasEmpresa(): HasMany
    {
        return $this->hasMany(EntregaEmpresa::class, 'corte_id');
    }

    public function rutas(): HasMany
    {
        return $this->hasMany(Ruta::class, 'corte_id');
    }
}
