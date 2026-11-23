<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Qué tal fue la entrega: puntualidad, trato del chofer y estado del empaque, de 1 a 5. */
class CalificacionEntrega extends ModeloEasyLunch
{
    protected $table = 'calificaciones_entrega';

    protected $fillable = ['calificador_id', 'entrega_empresa_id', 'pedido_dia_id', 'puntualidad', 'trato_chofer', 'empaque', 'comentario'];

    public function calificador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calificador_id');
    }
}
