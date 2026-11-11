<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EleccionEmpleado extends ModeloEasyLunch
{
    protected $table = 'elecciones_empleado';

    protected $fillable = [
        'empleado_id',
        'fecha',
        'tiempo_comida',
        'menu_id',
        'precio_congelado',
        'porcentaje_subsidio_congelado',
        'origen',
        'cerrada_en',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'precio_congelado' => 'decimal:2',
            'porcentaje_subsidio_congelado' => 'decimal:2',
            'cerrada_en' => 'datetime',
        ];
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class, 'empleado_id');
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }
}
