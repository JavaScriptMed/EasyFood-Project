<?php

namespace App\Services\Pagos;

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
        $intento = $this->cliente()->paymentIntents->create([
            'amount' => $centavos,
            'currency' => $moneda,
            'metadata' => $metadatos,
            // Sin `payment_method_types`: la API actual de Stripe ya no lo acepta; los métodos de pago (tarjeta) se activan en el Dashboard.
        ], ['idempotency_key' => $llaveIdempotente]);

        return $this->intento($intento);
    }

    public function obtenerIntento(string $intentoId): array
    {
        return $this->intento($this->cliente()->paymentIntents->retrieve($intentoId));
    }

    public function cargo(string $cargoId): ?array
    {
        $cargo = $this->cliente()->charges->retrieve($cargoId);

        return ['creado' => (int) $cargo->created, 'ultimos4' => $cargo->payment_method_details->card->last4 ?? null];
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

        return [
            'id' => (string) $evento->id, 'tipo' => (string) $evento->type, 'creado' => (int) $evento->created,
            'intento' => $esIntento ? [
                'id' => (string) $objeto->id, 'monto' => (int) $objeto->amount, 'moneda' => (string) $objeto->currency, 'estado' => (string) $objeto->status,
                'metadatos' => $objeto->metadata ? $objeto->metadata->toArray() : [],
                'cargo_id' => is_string($objeto->latest_charge ?? null) ? $objeto->latest_charge : ($objeto->latest_charge->id ?? null),
            ] : null,
        ];
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
