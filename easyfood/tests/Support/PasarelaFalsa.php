<?php

namespace Tests\Support;

use App\Services\Pagos\FirmaDeWebhookInvalida;
use App\Services\Pagos\PasarelaDePagos;

/**
 * Pasarela de pagos de mentira para las pruebas: no llama a la red. Guarda los intentos en memoria y entiende los «webhooks»
 * que arma `webhook()`: la firma válida es la constante FIRMA y el cuerpo es el evento ya normalizado, en JSON.
 */
class PasarelaFalsa implements PasarelaDePagos
{
    public const FIRMA = 'firma-valida';

    public bool $configurada = true;

    /** @var array<string, array{id: string, client_secret: string, estado: string, monto: int, moneda: string, metadatos: array<string, string>}> */
    public array $intentos = [];

    /** @var array<string, array{creado: int, ultimos4: ?string}> */
    public array $cargos = [];

    /** @var array<int, array<string, mixed>> */
    public array $llamadas = [];

    public function configurada(): bool
    {
        return $this->configurada;
    }

    public function crearIntento(int $centavos, string $moneda, array $metadatos, string $llaveIdempotente): array
    {
        $this->llamadas[] = ['crearIntento', $centavos, $moneda, $metadatos, $llaveIdempotente];
        foreach ($this->intentos as $intento) {
            if (($intento['llave'] ?? null) === $llaveIdempotente) {
                return $intento;
            }
        }
        $id = 'pi_prueba_'.(count($this->intentos) + 1);

        return $this->intentos[$id] = ['id' => $id, 'client_secret' => $id.'_secret', 'estado' => 'requires_payment_method', 'monto' => $centavos, 'moneda' => $moneda, 'metadatos' => $metadatos, 'llave' => $llaveIdempotente];
    }

    public function obtenerIntento(string $intentoId): array
    {
        $this->llamadas[] = ['obtenerIntento', $intentoId];

        return $this->intentos[$intentoId];
    }

    public function cargo(string $cargoId): ?array
    {
        $this->llamadas[] = ['cargo', $cargoId];

        return $this->cargos[$cargoId] ?? null;
    }

    public function evento(string $cuerpo, ?string $firma): array
    {
        if ($firma !== self::FIRMA) {
            throw new FirmaDeWebhookInvalida('La firma del webhook no es válida.');
        }

        return json_decode($cuerpo, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Un evento de cobro listo para mandarlo al webhook. `$cargoEn` es la hora del cargo (la que cuenta); los últimos 4 salen del cargo.
     *
     * @return array{0: string, 1: array<string, string>} cuerpo y encabezados
     */
    public function webhook(string $eventoId, string $tipo, string $intentoId, int $monto, ?string $cargoEn = null, ?string $ultimos4 = '4242', string $moneda = 'mxn'): array
    {
        $cargoId = 'ch_'.$eventoId;
        $creado = $cargoEn ? strtotime($cargoEn) : time();
        $this->cargos[$cargoId] = ['creado' => $creado, 'ultimos4' => $ultimos4];
        $cuerpo = json_encode([
            'id' => $eventoId, 'tipo' => $tipo, 'creado' => $creado + 5,
            'intento' => ['id' => $intentoId, 'monto' => $monto, 'moneda' => $moneda, 'estado' => $tipo === 'payment_intent.succeeded' ? 'succeeded' : 'requires_payment_method', 'metadatos' => [], 'cargo_id' => $cargoId],
        ], JSON_THROW_ON_ERROR);

        return [$cuerpo, ['Stripe-Signature' => self::FIRMA]];
    }
}
