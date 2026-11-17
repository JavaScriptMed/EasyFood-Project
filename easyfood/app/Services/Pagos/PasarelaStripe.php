<?php

namespace App\Services\Pagos;

use Stripe\Charge;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Webhook;

/** Stripe en modo de prueba. Las claves salen de config('services.stripe') (que lee .env). */
class PasarelaStripe implements PasarelaDePagos
{
    private ?StripeClient $cliente = null;

    public function __construct(private readonly ?string $secreto, private readonly ?string $secretoDelWebhook) {}

    public function configurada(): bool
    {
        return filled($this->secreto) && filled($this->secretoDelWebhook);
    }

    public function crearIntento(int $centavos, string $moneda, array $metadatos, string $llaveIdempotente): array
    {
        $intento = $this->llamar(fn () => $this->cliente()->paymentIntents->create([
            'amount' => $centavos,
            'currency' => $moneda,
            'metadata' => $metadatos,
            // Sin `payment_method_types`: la API actual de Stripe ya no lo acepta; los métodos de pago (tarjeta) se activan en el Dashboard.
        ], ['idempotency_key' => $llaveIdempotente]));

        return $this->intento($intento);
    }

    public function obtenerIntento(string $intentoId): array
    {
        return $this->intento($this->llamar(fn () => $this->cliente()->paymentIntents->retrieve($intentoId)));
    }

    public function cargo(string $cargoId): ?array
    {
        $cargo = $this->llamar(fn () => $this->cliente()->charges->retrieve($cargoId));

        return ['creado' => (int) $cargo->created, 'ultimos4' => $cargo->payment_method_details->card->last4 ?? null];
    }

    public function cargoDeIntento(string $intentoId): ?array
    {
        $intento = $this->llamar(fn () => $this->cliente()->paymentIntents->retrieve($intentoId, ['expand' => ['latest_charge']]));
        $cargo = $intento->latest_charge;

        return $cargo instanceof Charge ? ['id' => (string) $cargo->id, 'monto' => (int) $cargo->amount, 'reembolsado' => (int) $cargo->amount_refunded, 'creado' => (int) $cargo->created] : null;
    }

    public function crearReembolso(string $intentoId, int $centavos, string $llaveIdempotente, array $metadatos): array
    {
        $reembolso = $this->llamar(fn () => $this->cliente()->refunds->create(
            ['payment_intent' => $intentoId, 'amount' => $centavos, 'metadata' => $metadatos],
            ['idempotency_key' => $llaveIdempotente],
        ));

        return ['id' => (string) $reembolso->id, 'estado' => (string) $reembolso->status, 'monto' => (int) $reembolso->amount, 'creado' => (int) $reembolso->created];
    }

    public function ultimoReembolso(string $cargoId): ?array
    {
        $reembolso = $this->llamar(fn () => $this->cliente()->refunds->all(['charge' => $cargoId, 'limit' => 1]))->data[0] ?? null;

        return $reembolso ? ['id' => (string) $reembolso->id, 'estado' => (string) $reembolso->status, 'monto' => (int) $reembolso->amount, 'creado' => (int) $reembolso->created] : null;
    }

    public function evento(string $cuerpo, ?string $firma): array
    {
        try {
            $evento = Webhook::constructEvent($cuerpo, (string) $firma, (string) $this->secretoDelWebhook);
        } catch (SignatureVerificationException|UnexpectedValueException $e) {
            throw new FirmaDeWebhookInvalida('La firma del webhook no es válida.', 0, $e);
        }

        $objeto = $evento->data->object ?? null;
        $esIntento = $objeto instanceof PaymentIntent;
        $esCargo = $objeto instanceof Charge;

        return [
            'id' => (string) $evento->id, 'tipo' => (string) $evento->type, 'creado' => (int) $evento->created,
            'intento' => $esIntento ? [
                'id' => (string) $objeto->id, 'monto' => (int) $objeto->amount, 'moneda' => (string) $objeto->currency, 'estado' => (string) $objeto->status,
                'metadatos' => $objeto->metadata ? $objeto->metadata->toArray() : [],
                'cargo_id' => is_string($objeto->latest_charge ?? null) ? $objeto->latest_charge : ($objeto->latest_charge->id ?? null),
            ] : null,
            'cargo' => $esCargo ? [
                'id' => (string) $objeto->id, 'intento_id' => is_string($objeto->payment_intent ?? null) ? $objeto->payment_intent : ($objeto->payment_intent->id ?? null),
                'monto' => (int) $objeto->amount, 'reembolsado' => (int) $objeto->amount_refunded, 'reembolso_total' => (bool) $objeto->refunded,
            ] : null,
        ];
    }

    /** Ejecuta una llamada al SDK y convierte sus errores en ErrorDePasarela. */
    private function llamar(callable $llamada): mixed
    {
        try {
            return $llamada();
        } catch (ApiErrorException $e) {
            throw new ErrorDePasarela($e->getMessage(), 0, $e);
        }
    }

    private function intento(PaymentIntent $i): array
    {
        return ['id' => (string) $i->id, 'client_secret' => (string) $i->client_secret, 'estado' => (string) $i->status, 'monto' => (int) $i->amount, 'moneda' => (string) $i->currency];
    }

    private function cliente(): StripeClient
    {
        return $this->cliente ??= new StripeClient((string) $this->secreto);
    }
}
