<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base de los modelos de tablas en español: las marcas de tiempo se llaman creado_en / actualizado_en
 * (timestamptz), no created_at / updated_at como en las tablas del equipo.
 */
abstract class ModeloEasyLunch extends Model
{
    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';
}
