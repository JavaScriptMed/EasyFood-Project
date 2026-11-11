<?php

namespace App\Services;

use App\Models\RegistroAuditoria;
use App\Models\User;

/** Deja constancia de lo que hace una persona (o el sistema) en la bitácora, que solo se agrega: nunca se edita ni se borra. */
final class Bitacora
{
    /** @param  User|int|null  $usuario  null = el sistema */
    public static function registrar(User|int|null $usuario, string $accion, string $entidad, int|string $entidadId, string $detalle): RegistroAuditoria
    {
        return RegistroAuditoria::create([
            'usuario_id' => $usuario instanceof User ? $usuario->id : $usuario, 'accion' => $accion, 'entidad' => $entidad,
            'entidad_id' => (string) $entidadId, 'detalle' => $detalle,
        ]);
    }
}
