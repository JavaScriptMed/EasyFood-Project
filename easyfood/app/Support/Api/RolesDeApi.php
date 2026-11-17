<?php

namespace App\Support\Api;

/**
 * Los roles de la base están en inglés (los de tu equipo) y los del front en español. Aquí se traducen y se decide a qué portal entra cada uno.
 */
final class RolesDeApi
{
    /** nombre en la base => nombre en el front */
    public const A_FRONT = [
        'admin' => 'administrador', 'support' => 'soporte', 'provider' => 'comedor', 'company' => 'empresa',
        'employee' => 'empleado', 'customer' => 'cliente', 'driver' => 'repartidor',
    ];

    /** rol del front => portal del front (los clientes y repartidores usan la app móvil: no tienen portal web) */
    private const PORTAL = ['administrador' => 'admin', 'soporte' => 'admin', 'comedor' => 'comedores', 'empresa' => 'empresas', 'empleado' => 'empleados'];

    public static function front(string $rolDeLaBase): string
    {
        return self::A_FRONT[$rolDeLaBase] ?? $rolDeLaBase;
    }

    public static function base(string $rolDelFront): ?string
    {
        $inverso = array_flip(self::A_FRONT);

        return $inverso[$rolDelFront] ?? null;
    }

    public static function portal(string $rolDelFront): ?string
    {
        return self::PORTAL[$rolDelFront] ?? null;
    }
}
