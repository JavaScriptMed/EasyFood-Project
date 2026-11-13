<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Tablas con desactivación lógica (columnas `activo` y `desactivado_en`). La base impide el DELETE
 * (trigger `impedir_borrado`), así que "borrar" un registro es desactivarlo.
 *
 * @method static Builder activos()
 */
trait Desactivable
{
    public function scopeActivos(Builder $consulta): Builder
    {
        return $consulta->where($this->qualifyColumn('activo'), true);
    }

    public function scopeInactivos(Builder $consulta): Builder
    {
        return $consulta->where($this->qualifyColumn('activo'), false);
    }

    public function desactivar(): bool
    {
        return $this->forceFill(['activo' => false, 'desactivado_en' => now()])->save();
    }

    public function reactivar(): bool
    {
        return $this->forceFill(['activo' => true, 'desactivado_en' => null])->save();
    }
}
