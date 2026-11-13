<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Casts\ArregloPostgres;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Menu extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'provider_id',
        'category_id',
        'name',
        'description',
        'price',
        'image_url',
        'daily_stock',
        'is_available',
        'available_days',
        'estado_revision',
        'motivo_rechazo',
        'tiempo_comida',
        'calorias',
        'alergenos',
        'etiquetas',
    ];

    protected function casts(): array
    {
        return [
            'price'          => 'float',
            'daily_stock'    => 'integer',
            'is_available'   => 'boolean',
            'available_days' => 'array',
            'calorias'       => 'integer',
            'alergenos'      => ArregloPostgres::class,
            'etiquetas'      => ArregloPostgres::class,
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogCategory::class, 'category_id');
    }

    public function disponibilidad(): HasMany
    {
        return $this->hasMany(DisponibilidadDia::class);
    }
}
