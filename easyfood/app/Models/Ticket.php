<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends ModeloEasyLunch
{
    protected $table = 'tickets';

    protected $fillable = [
        'folio',
        'cliente_id',
        'pedido_id',
        'asunto',
        'estado',
        'mensajes_sin_leer',
    ];

    protected function casts(): array
    {
        return [
            'mensajes_sin_leer' => 'integer',
        ];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cliente_id');
    }

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }

    public function mensajes(): HasMany
    {
        return $this->hasMany(MensajeTicket::class, 'ticket_id');
    }
}
