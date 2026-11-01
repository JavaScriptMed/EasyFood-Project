<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogCategory extends Model
{
    use HasFactory;

    protected $table = 'catalog_categories';

    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CatalogItem::class, 'category_id');
    }

    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class, 'category_id');
    }
}