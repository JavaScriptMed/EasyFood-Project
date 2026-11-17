<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Pedido;
use App\Models\StripeEvento;
use App\Models\User;
use App\Services\Pagos\PasarelaDePagos;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Pago con tarjeta de los pedidos individuales (Stripe en modo de prueba).
 *
 * - iniciar(): el cliente pide pagar su pedido y recibe el `client_secret` de un PaymentIntent por el total en MXN.
 *   Solo si el pedido es suyo, está por pagar y no ha vencido. No cambia el pedido.
 * - procesarEvento(): el webhook de Stripe avisa que se cobró. Es idempotente (cada evento se procesa una sola vez) y es lo único
 *   que pasa el pedido a «recibido». Un pago fallido, o uno que llega cuando el pedido ya no se puede pagar, no lo cambia:
 *   el corte sigue cancelando los pedidos sin pagar, y el cobro tardío queda en la bitácora para reembolsarlo a mano.
 * - Nunca se guardan datos de tarjeta: solo los últimos 4 dígitos.
 */
class PagosDePedidos
{
    /** Estados de un PaymentIntent que todavía sirven para pagar. */
    private const REUTILIZABLES = ['requires_payment_method', 'requires_confirmation', 'requires_action', 'processing'];

    public function __construct(
        private readonly PasarelaDePagos $pasarela,
        private readonly ContratacionDePaquetes $paquetes,
    ) {}

    /**
     * @return array{pedido_id: int, payment_intent_id: string, client_secret: string, monto: string, moneda: string}
     */
    public function iniciar(User $cliente, int $pedidoId, ?CarbonInterface $ahora = null): array
    {
        $pedido = Pedido::query()->where('cliente_id', $cliente->id)->findOrFail($pedidoId);

        if ($pedido->estado !== 'pendiente_pago') {
            throw new ReglaDeNegocioException('pedido_no_pagable', 'Solo se puede pagar un pedido pendiente de pago.');
        }
        if ($this->paquetes->yaNoSePuedePagar($pedido, $ahora)) {
            throw new ReglaDeNegocioException('pedido_vencido', 'El tiempo para pagar terminó (el corte de la primera entrega ya pasó): hay que pedirlo de nuevo.');
        }

        $centavos = Dinero::centavos($pedido->total);
        $moneda = (string) config('services.stripe.moneda', 'mxn');

        $intento = $pedido->stripe_payment_intent_id ? $this->pasarela->obtenerIntento($pedido->stripe_payment_intent_id) : null;
        if ($intento === null || $intento['monto'] !== $centavos || ! in_array($intento['estado'], self::REUTILIZABLES, true)) {
            // La llave incluye el intento anterior: si ese ya no sirve, se pide uno nuevo en vez de repetir el mismo.
            $llave = 'easylunch-pedido-'.$pedido->id.'-'.$centavos.'-'.substr(sha1((string) $pedido->stripe_payment_intent_id), 0, 8);
            $intento = $this->pasarela->crearIntento($centavos, $moneda, ['pedido_id' => (string) $pedido->id, 'folio' => $pedido->folio], $llave);
            $pedido->update(['stripe_payment_intent_id' => $intento['id']]);
        }

        return [
            'pedido_id' => $pedido->id, 'payment_intent_id' => $intento['id'], 'client_secret' => $intento['client_secret'],
            'monto' => Dinero::formato($centavos), 'moneda' => strtoupper($moneda),
        ];
    }

    /**
     * Procesa un evento ya verificado del webhook. Devuelve qué pasó: `repetido` si ya se había procesado, o el resultado
     * guardado en stripe_eventos (pagado, ignorado, fallo_de_pago, pedido_no_pagable, monto_distinto).
     *
     * @param  array{id: string, tipo: string, creado: int, intento: array<string, mixed>|null}  $evento
     */
    public function procesarEvento(array $evento): string
    {
        return DB::transaction(function () use ($evento) {
            // Un evento, una fila: si el webhook llega repetido (o dos veces a la vez), la segunda espera a la primera y no hace nada.
            $nuevo = DB::table('stripe_eventos')->insertOrIgnore(['stripe_event_id' => $evento['id'], 'tipo' => $evento['tipo'], 'resultado' => 'ignorado']);
            if ($nuevo === 0) {
                return 'repetido';
            }

            [$resultado, $pedidoId] = match ($evento['tipo']) {
                'payment_intent.succeeded' => $this->cobroExitoso($evento),
                'payment_intent.payment_failed' => $this->cobroFallido($evento),
                default => ['ignorado', null],
            };

            StripeEvento::query()->where('stripe_event_id', $evento['id'])->update(['resultado' => $resultado, 'pedido_id' => $pedidoId]);

            return $resultado;
        });
    }

    /** @return array{0: string, 1: ?int} */
    private function cobroExitoso(array $evento): array
    {
        $intento = $evento['intento'];
        $pedido = $intento ? Pedido::query()->where('stripe_payment_intent_id', $intento['id'])->lockForUpdate()->first() : null;
        if (! $pedido) {
            return ['ignorado', null]; // un cobro que no es de ningún pedido nuestro (por ejemplo, un evento de prueba del CLI)
        }

        // Cuándo se hizo el cargo (y los últimos 4): se compara contra eso, no contra la hora en que llegó el webhook.
        $cargo = $intento['cargo_id'] ? $this->pasarela->cargo($intento['cargo_id']) : null;
        $momento = CarbonImmutable::createFromTimestamp($cargo['creado'] ?? $evento['creado'], config('app.timezone')); // en la zona del sistema, como el resto de las fechas

        if ($intento['monto'] !== Dinero::centavos($pedido->total) || strtolower($intento['moneda']) !== strtolower((string) config('services.stripe.moneda', 'mxn'))) {
            $this->avisarParaReembolso($pedido, $intento, 'el monto o la moneda cobrados no son los del pedido');

            return ['monto_distinto', $pedido->id];
        }
        if ($pedido->estado !== 'pendiente_pago' || $this->paquetes->pagoLlegoTarde($pedido, $momento)) {
            $motivo = $pedido->estado === 'pendiente_pago' ? 'el cargo se hizo después del plazo para pagar' : "el pedido ya estaba {$pedido->estado}";
            $this->avisarParaReembolso($pedido, $intento, $motivo);

            return ['pedido_no_pagable', $pedido->id];
        }

        $this->paquetes->confirmarPago($pedido, 'tarjeta', $cargo['ultimos4'] ?? null, $momento);
        Bitacora::registrar(null, 'pedido.pagado', 'pedidos', $pedido->id, "Pago con tarjeta confirmado por Stripe para el pedido {$pedido->folio}.");

        return ['pagado', $pedido->id];
    }

    /** @return array{0: string, 1: ?int} */
    private function cobroFallido(array $evento): array
    {
        $intento = $evento['intento'];
        $pedido = $intento ? Pedido::query()->where('stripe_payment_intent_id', $intento['id'])->first() : null;

        return ['fallo_de_pago', $pedido?->id]; // el pedido no cambia: sigue por pagar y el cliente puede reintentar
    }

    /** Un cobro que no se puede aplicar al pedido: se deja constancia para devolver el dinero a mano. No hay reembolso automático. */
    private function avisarParaReembolso(Pedido $pedido, array $intento, string $motivo): void
    {
        Bitacora::registrar(
            null, 'pago.reembolso_manual', 'pedidos', $pedido->id,
            sprintf('Stripe cobró %s %s (intento %s) por el pedido %s, pero %s. Hay que reembolsarlo manualmente en Stripe.', Dinero::formato($intento['monto']), strtoupper($intento['moneda']), $intento['id'], $pedido->folio, $motivo),
        );
    }
}
