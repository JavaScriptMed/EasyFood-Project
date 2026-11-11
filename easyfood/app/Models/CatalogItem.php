<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Elemento de un catálogo (tabla `catalog_items` del equipo). Ya lo referenciaba CatalogCategory::items(). */
class CatalogItem extends Model
{
    protected $fillable = [
        'category_id',
        'code',
        'label',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CatalogCategory::class, 'category_id');
    }
}
