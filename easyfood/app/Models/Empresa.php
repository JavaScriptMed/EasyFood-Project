<?php

namespace App\Models;

use App\Models\Concerns\Desactivable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Empresa extends ModeloEasyLunch
{
    use Desactivable;

    protected $table = 'empresas';

    protected $fillable = [
        'usuario_id',
        'nombre',
        'dominio',
        'zona',
        'direccion_entrega',
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

    public function contratos(): HasMany
    {
        return $this->hasMany(ContratoEmpresa::class, 'empresa_id');
    }

    public function empleados(): HasMany
    {
        return $this->hasMany(Empleado::class, 'empresa_id');
    }

    public function entregas(): HasMany
    {
        return $this->hasMany(EntregaEmpresa::class, 'empresa_id');
    }

    public function facturas(): HasMany
    {
        return $this->hasMany(FacturaMensual::class, 'empresa_id');
    }

    public function diasSinServicio(): HasMany
    {
        return $this->hasMany(DiaSinServicio::class, 'empresa_id');
    }
}
