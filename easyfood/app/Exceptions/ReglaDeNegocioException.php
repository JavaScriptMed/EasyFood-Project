<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Una regla de negocio impidió la operación (corte cerrado, sin porciones, mes sin terminar…).
 * `codigo` es estable para que la API y el front distingan el caso sin leer el mensaje.
 */
class ReglaDeNegocioException extends RuntimeException
{
    public function __construct(public readonly string $codigo, string $mensaje)
    {
        parent::__construct($mensaje);
    }
}
