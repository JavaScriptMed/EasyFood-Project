<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sabor y calidad de un platillo ya entregado, de 1 a 5 estrellas. */
class CalificacionPlatillo extends ModeloEasyLunch
{
    protected $table = 'calificaciones_platillo';

    protected $fillable = ['calificador_id', 'menu_id', 'fecha', 'estrellas', 'comentario'];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d'];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class, 'menu_id');
    }
}
