<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistroAuditoria extends ModeloEasyLunch
{
    protected $table = 'registros_auditoria';

    public const UPDATED_AT = null;

    protected $fillable = [
        'usuario_id',
        'accion',
        'entidad',
        'entidad_id',
        'detalle',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
