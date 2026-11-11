<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Consecutivos de folio sin repeticiones aunque dos procesos pidan uno al mismo tiempo (dos cortes, dos pedidos…).
 * El UPSERT toma el candado de la fila hasta que termine la transacción de quien lo pide, así que el segundo
 * espera y recibe el número siguiente; si la transacción se deshace, el número también (no quedan huecos).
 */
final class Folios
{
    public static function siguiente(string $clave): int
    {
        $fila = DB::selectOne(
            'insert into contadores_folio (clave, valor) values (?, 1)
             on conflict (clave) do update set valor = contadores_folio.valor + 1
             returning valor',
            [$clave],
        );

        return (int) $fila->valor;
    }

    /** EL-AAAAMM-NNN: el consecutivo de facturas del mes. */
    public static function factura(\Carbon\CarbonInterface $mes): string
    {
        $periodo = $mes->format('Ym');

        return sprintf('EL-%s-%03d', $periodo, self::siguiente("factura:{$periodo}"));
    }

    /** GDL-AAAAMMDD-NNNN: el consecutivo de pedidos del día en que se hicieron. */
    public static function pedido(\Carbon\CarbonInterface $dia): string
    {
        $periodo = $dia->format('Ymd');

        return sprintf('GDL-%s-%04d', $periodo, self::siguiente("pedido:{$periodo}"));
    }
}
