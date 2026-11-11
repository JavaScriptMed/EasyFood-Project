<?php

namespace App\Services;

/** Aritmética de montos en centavos enteros: nunca se suman ni se multiplican decimales con float. */
final class Dinero
{
    /** '130.00' / 130 / '49.5' → 13000 */
    public static function centavos(string|int|float $monto): int
    {
        return (int) round(((float) $monto) * 100);
    }

    /** 13000 → '130.00' */
    public static function formato(int $centavos): string
    {
        return sprintf('%s%d.%02d', $centavos < 0 ? '-' : '', intdiv(abs($centavos), 100), abs($centavos) % 100);
    }
}
