<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}