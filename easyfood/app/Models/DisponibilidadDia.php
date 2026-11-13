<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisponibilidadDia extends ModeloEasyLunch
{
    protected $table = 'disponibilidad_dia';

    protected $fillable = [
        'menu_id',
        'fecha',
        'porciones_maximas',
        'porciones_tomadas',
        'es_platillo_del_dia',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'porciones_maximas' => 'integer',
            'porciones_tomadas' => 'integer',
            'es_platillo_del_dia' => 'boolean',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }
}
