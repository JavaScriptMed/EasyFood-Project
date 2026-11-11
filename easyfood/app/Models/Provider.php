<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'business_name',
        'commercial_name',
        'rfc',
        'phone',
        'email',
        'address',
        'postal_code',
        'zona',
        'latitude',
        'longitude',
        'daily_capacity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active'      => 'boolean',
            'daily_capacity' => 'integer',
            'latitude'       => 'float',
            'longitude'      => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class);
    }

    public function contratos(): HasMany
    {
        return $this->hasMany(ContratoComedor::class, 'comedor_id');
    }

    public function ordenesProduccion(): HasMany
    {
        return $this->hasMany(OrdenProduccion::class, 'comedor_id');
    }

    public function liquidaciones(): HasMany
    {
        return $this->hasMany(LiquidacionComedor::class, 'comedor_id');
    }
}
