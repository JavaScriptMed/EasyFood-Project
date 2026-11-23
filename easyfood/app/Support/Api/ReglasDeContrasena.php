<?php

namespace App\Support\Api;

/** La contraseña que se elige al aceptar una invitación o restablecerla: mínimo 8 caracteres, con letras y números (lo mismo que valida el front). */
final class ReglasDeContrasena
{
    public const REGLAS = ['required', 'string', 'min:8', 'max:128', 'regex:/[A-Za-zÁÉÍÓÚáéíóúñÑ]/', 'regex:/\d/'];

    public const MENSAJES = [
        'contrasena.required' => 'Escribe tu contraseña.',
        'contrasena.min' => 'La contraseña debe tener al menos 8 caracteres.',
        'contrasena.max' => 'La contraseña es demasiado larga.',
        'contrasena.regex' => 'La contraseña debe incluir letras y números.',
    ];
}
