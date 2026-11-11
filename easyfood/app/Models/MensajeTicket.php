<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MensajeTicket extends ModeloEasyLunch
{
    protected $table = 'mensajes_ticket';

    public $timestamps = false;

    protected $fillable = [
        'ticket_id',
        'autor_id',
        'autor_tipo',
        'texto',
        'enviado_en',
    ];

    protected function casts(): array
    {
        return [
            'enviado_en' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }
}
