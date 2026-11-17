<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un evento del webhook de Stripe ya procesado. Sirve para no procesar dos veces el mismo evento. */
class StripeEvento extends ModeloEasyLunch
{
    protected $table = 'stripe_eventos';

    protected $fillable = ['stripe_event_id', 'tipo', 'pedido_id', 'resultado'];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(Pedido::class, 'pedido_id');
    }
}
