<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Pedido;
use App\Models\User;
use App\Services\Pagos\ErrorDePasarela;
use App\Services\Pagos\PasarelaDePagos;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Reembolsos de pedidos pagados con Stripe. Siempre son totales.
 *
 * - Por administración o soporte (`reembolsar`): reembolsa el cobro del pedido y lo cancela en el momento.
 * - Automático (`reembolsarCobroNoAplicable`): el webhook recibió un cobro sobre un pedido cancelado o vencido, que ya no se puede
 *   pagar; se devuelve el dinero y el pedido no cambia. Si Stripe falla, queda un aviso en la bitácora para hacerlo a mano.
 * - Hecho desde Stripe (`aplicarReembolso`, vía el webhook `charge.refunded`): el pedido pasa a cancelado.
 *
 * No hay estado nuevo: un pedido reembolsado queda «cancelado» con reembolsado_en, monto_reembolsado y stripe_refund_id.
 * Nunca se reembolsa más de lo cobrado (lo valida la base y Stripe) ni dos veces (llave de idempotencia por cobro).
 */
class ReembolsosDePedidos
{
    public function __construct(
        private readonly PasarelaDePagos $pasarela,
        private readonly ContratacionDePaquetes $paquetes,
    ) {}

    /**
     * Reembolso total de un pedido pagado, pedido por una persona de administración o soporte.
     *
     * @return Pedido el pedido ya cancelado y con los datos del reembolso
     *
     * @throws ReglaDeNegocioException si el pedido no se puede reembolsar
     * @throws ErrorDePasarela si Stripe no responde o lo rechaza (el pedido no cambia)
     */
    public function reembolsar(int $pedidoId, User $quien, ?CarbonInterface $ahora = null): Pedido
    {
        return DB::transaction(function () use ($pedidoId, $quien, $ahora) {
            $pedido = Pedido::query()->lockForUpdate()->findOrFail($pedidoId);

            if ($pedido->reembolsado_en !== null) {
                throw new ReglaDeNegocioException('pedido_ya_reembolsado', 'Este pedido ya se reembolsó.');
            }
            if (! in_array($pedido->estado, ['recibido', 'activo'], true)) {
                throw new ReglaDeNegocioException('pedido_no_reembolsable', 'Solo se reembolsa un pedido pagado (recibido o activo).');
            }
            if (! $pedido->stripe_payment_intent_id) {
                throw new ReglaDeNegocioException('pedido_sin_cobro_en_stripe', 'Este pedido no se pagó con Stripe: no hay nada que reembolsar desde aquí.');
            }

            $cargo = $this->pasarela->cargoDeIntento($pedido->stripe_payment_intent_id);
            if (! $cargo) {
                throw new ReglaDeNegocioException('pedido_sin_cobro_en_stripe', 'Stripe no tiene un cobro de este pedido.');
            }
            if ($cargo['monto'] !== Dinero::centavos($pedido->total)) {
                throw new ReglaDeNegocioException('cobro_distinto_al_pedido', 'Lo cobrado en Stripe no coincide con el total del pedido: revísalo en Stripe antes de reembolsar.');
            }
            $porReembolsar = $cargo['monto'] - $cargo['reembolsado'];
            if ($porReembolsar <= 0) {
                throw new ReglaDeNegocioException('cobro_ya_reembolsado', 'Stripe ya reembolsó este cobro.');
            }

            // Se reembolsa lo que falta del cobro, nunca más. La llave hace que un reintento devuelva el mismo reembolso.
            $reembolso = $this->pasarela->crearReembolso(
                $pedido->stripe_payment_intent_id, $porReembolsar, 'easylunch-reembolso-pedido-'.$pedido->id.'-'.$cargo['id'],
                ['pedido_id' => (string) $pedido->id, 'folio' => $pedido->folio, 'solicitado_por' => (string) $quien->id],
            );

            // monto_reembolsado es todo lo devuelto del cobro (lo que ya se había devuelto desde Stripe más este reembolso).
            $this->aplicarReembolso($pedido, $reembolso['id'], $cargo['reembolsado'] + $reembolso['monto'], CarbonImmutable::createFromTimestamp($reembolso['creado'], config('app.timezone')), $quien);

            return $pedido->refresh();
        });
    }

    /**
     * Cancela el pedido y anota el reembolso. Es idempotente: si ya estaba anotado no hace nada y devuelve false.
     * Lo llaman el endpoint de reembolso y el webhook `charge.refunded` (que es quien se entera de un reembolso hecho desde Stripe).
     */
    public function aplicarReembolso(Pedido $pedido, string $reembolsoId, int $centavos, CarbonInterface $momento, ?User $quien = null): bool
    {
        if ($pedido->reembolsado_en !== null) {
            return false;
        }

        $this->paquetes->cancelar($pedido); // sus días por entregar se cancelan y devuelven sus porciones
        $pedido->update(['reembolsado_en' => $momento, 'monto_reembolsado' => Dinero::formato($centavos), 'stripe_refund_id' => $reembolsoId]);

        Bitacora::registrar(
            $quien, 'pedido.reembolsado', 'pedidos', $pedido->id,
            sprintf('Reembolso total de %s MXN del pedido %s (Stripe %s). %s', Dinero::formato($centavos), $pedido->folio, $reembolsoId, $quien ? "Lo hizo {$quien->name}." : 'Se hizo desde Stripe.'),
        );

        return true;
    }

    /**
     * Un cobro que llegó a un pedido que ya no se puede pagar (cancelado o vencido): se reembolsa completo y el pedido no cambia.
     *
     * @param  array{id: string, monto: int, moneda: string}  $intento
     * @return string `reembolso_automatico`, o `reembolso_fallido` si Stripe no pudo (queda el aviso en la bitácora para hacerlo a mano)
     */
    public function reembolsarCobroNoAplicable(Pedido $pedido, array $intento, string $motivo): string
    {
        try {
            $reembolso = $this->pasarela->crearReembolso($intento['id'], $intento['monto'], 'easylunch-reembolso-auto-'.$intento['id'], ['pedido_id' => (string) $pedido->id, 'folio' => $pedido->folio, 'motivo' => 'cobro_no_aplicable']);
        } catch (ErrorDePasarela $e) {
            Bitacora::registrar(
                null, 'pago.reembolso_manual', 'pedidos', $pedido->id,
                sprintf('Stripe cobró %s %s (intento %s) por el pedido %s, pero %s. Se intentó reembolsarlo automáticamente y falló (%s): hay que reembolsarlo manualmente en Stripe.', Dinero::formato($intento['monto']), strtoupper($intento['moneda']), $intento['id'], $pedido->folio, $motivo, $e->getMessage()),
            );

            return 'reembolso_fallido';
        }

        Bitacora::registrar(
            null, 'pago.reembolsado_automatico', 'pedidos', $pedido->id,
            sprintf('Stripe cobró %s %s (intento %s) por el pedido %s, pero %s. Se reembolsó automáticamente (%s); el pedido no cambió.', Dinero::formato($intento['monto']), strtoupper($intento['moneda']), $intento['id'], $pedido->folio, $motivo, $reembolso['id']),
        );

        return 'reembolso_automatico';
    }
}
