<?php

namespace App\Models;

use App\Models\Concerns\Desactivable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un gasto de la operación (reparto, empaque, nómina…) que entra al balance de utilidad. Anular = desactivar. */
class CostoOperativo extends ModeloEasyLunch
{
    use Desactivable;

    protected $table = 'costos_operativos';

    protected $fillable = ['fecha', 'categoria', 'descripcion', 'monto', 'registrado_por', 'activo', 'desactivado_en'];

    protected function casts(): array
    {
        return ['fecha' => 'date:Y-m-d', 'monto' => 'decimal:2', 'activo' => 'boolean', 'desactivado_en' => 'datetime'];
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
