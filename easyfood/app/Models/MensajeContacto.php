<?php

namespace App\Models;

class MensajeContacto extends ModeloEasyLunch
{
    protected $table = 'mensajes_contacto';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'correo',
        'empresa',
        'mensaje',
        'enviado_en',
    ];

    protected function casts(): array
    {
        return [
            'enviado_en' => 'datetime',
        ];
    }
}
