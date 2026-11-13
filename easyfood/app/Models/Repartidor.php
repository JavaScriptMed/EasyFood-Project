<?php

namespace App\Models;

use App\Models\Concerns\Desactivable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Repartidor extends ModeloEasyLunch
{
    use Desactivable;

    protected $table = 'repartidores';

    protected $fillable = [
        'usuario_id',
        'numero_empleado',
        'placas',
        'zona_base',
        'estado',
        'ultima_ubicacion_en',
    ];

    protected $hidden = ['ultima_ubicacion'];

    // `ultima_ubicacion` es geography(Point,4326): no se asigna en masa ni se lee como atributo. Se escribe y consulta con SQL de PostGIS (ST_MakePoint, ST_X/ST_Y).

    protected function casts(): array
    {
        return [
            'ultima_ubicacion_en' => 'datetime',
            'activo' => 'boolean',
            'desactivado_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function rutas(): HasMany
    {
        return $this->hasMany(Ruta::class, 'repartidor_id');
    }
}
