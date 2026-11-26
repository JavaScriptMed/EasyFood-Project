<?php

namespace App\Models;

/** La constancia de que quien recibe (cliente o empleado) confirmó con «Recibido» que su comida le llegó. */
class Recepcion extends ModeloEasyLunch
{
    protected $table = 'recepciones';

    protected $fillable = ['usuario_id', 'entrega_empresa_id', 'pedido_dia_id', 'confirmada_en'];

    protected function casts(): array
    {
        return ['confirmada_en' => 'datetime'];
    }
}
