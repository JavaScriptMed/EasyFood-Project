<?php

namespace App\Models;

use App\Models\Concerns\Desactivable;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Zona de cobertura: un polígono PostGIS (`area`) que carga el administrador. No se lee como atributo;
 * se escribe con SQL, p. ej. ST_Multi(ST_GeogFromText('POLYGON((lon lat, ...))')).
 */
class Zona extends ModeloEasyLunch
{
    use Desactivable;

    protected $table = 'zonas';

    protected $fillable = ['nombre'];

    protected $hidden = ['area'];

    public function direcciones(): HasMany
    {
        return $this->hasMany(Address::class, 'zona', 'nombre');
    }
}
