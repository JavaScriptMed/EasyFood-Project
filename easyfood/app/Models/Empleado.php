<?php

namespace App\Models;

use App\Models\Concerns\Desactivable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Empleado extends ModeloEasyLunch
{
    use Desactivable;

    protected $table = 'empleados';

    protected $fillable = [
        'usuario_id',
        'empresa_id',
        'departamento',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'desactivado_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function elecciones(): HasMany
    {
        return $this->hasMany(EleccionEmpleado::class, 'empleado_id');
    }
}
