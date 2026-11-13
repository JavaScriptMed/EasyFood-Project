<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Configuracion extends ModeloEasyLunch
{
    protected $table = 'configuracion';

    protected $primaryKey = 'clave';

    public $incrementing = false;

    protected $keyType = 'string';

    public const CREATED_AT = null;

    protected $fillable = [
        'clave',
        'valor',
        'actualizado_por',
    ];

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }
}
