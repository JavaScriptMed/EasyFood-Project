<?php

namespace Tests\Support;

use App\Services\Pagos\ErrorDePasarela;
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

    /** @var array<string, array{id: string, estado: string, monto: int, creado: int, intento: string}> reembolsos por llave de idempotencia */
    public array $reembolsos = [];

    /** Si se escribe algo, el siguiente reembolso falla con ese mensaje (como si Stripe no respondiera). */
    public ?string $fallaAlReembolsar = null;

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

    /** Marca un intento como cobrado y le crea su cargo (como cuando el cliente paga con la tarjeta). Devuelve el id del cargo. */
    public function cobrar(string $intentoId, ?string $cargoEn = null, ?string $ultimos4 = '4242'): string
    {
        $cargoId = 'ch_'.$intentoId;
        $this->intentos[$intentoId]['estado'] = 'succeeded';
        $this->intentos[$intentoId]['cargo'] = ['id' => $cargoId, 'monto' => $this->intentos[$intentoId]['monto'], 'reembolsado' => 0, 'creado' => $cargoEn ? strtotime($cargoEn) : time()];
        $this->cargos[$cargoId] = ['creado' => $this->intentos[$intentoId]['cargo']['creado'], 'ultimos4' => $ultimos4];

        return $cargoId;
    }

    public function cargoDeIntento(string $intentoId): ?array
    {
        $this->llamadas[] = ['cargoDeIntento', $intentoId];

        return $this->intentos[$intentoId]['cargo'] ?? null;
    }

    public function crearReembolso(string $intentoId, int $centavos, string $llaveIdempotente, array $metadatos): array
    {
        $this->llamadas[] = ['crearReembolso', $intentoId, $centavos, $llaveIdempotente, $metadatos];
        if ($this->fallaAlReembolsar !== null) {
            throw new ErrorDePasarela($this->fallaAlReembolsar);
        }
        if (isset($this->reembolsos[$llaveIdempotente])) {
            return $this->reembolsos[$llaveIdempotente]; // la misma llave devuelve el mismo reembolso
        }

        // Como Stripe: nunca se reembolsa más de lo cobrado.
        $cobrado = $this->intentos[$intentoId]['cargo']['monto'] ?? $this->intentos[$intentoId]['monto'];
        $yaReembolsado = array_sum(array_map(fn ($r) => $r['intento'] === $intentoId ? $r['monto'] : 0, $this->reembolsos));
        if ($centavos > $cobrado - $yaReembolsado) {
            throw new ErrorDePasarela('Charge ch_'.$intentoId.' has already been refunded or the amount is greater than the charge.');
        }
        if (isset($this->intentos[$intentoId]['cargo'])) {
            $this->intentos[$intentoId]['cargo']['reembolsado'] += $centavos;
        }

        return $this->reembolsos[$llaveIdempotente] = ['id' => 're_prueba_'.(count($this->reembolsos) + 1), 'estado' => 'succeeded', 'monto' => $centavos, 'creado' => time(), 'intento' => $intentoId];
    }

    public function ultimoReembolso(string $cargoId): ?array
    {
        $this->llamadas[] = ['ultimoReembolso', $cargoId];
        $intento = substr($cargoId, 3);
        $propios = array_values(array_filter($this->reembolsos, fn ($r) => $r['intento'] === $intento));

        return $propios ? end($propios) : null;
    }

    public function evento(string $cuerpo, ?string $firma): array
    {
        if ($firma !== self::FIRMA) {
            throw new FirmaDeWebhookInvalida('La firma del webhook no es válida.');
        }

        return json_decode($cuerpo, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Un evento `charge.refunded` listo para mandarlo al webhook. Lo reembolsado sale del intento (ver crearReembolso).
     *
     * @return array{0: string, 1: array<string, string>} cuerpo y encabezados
     */
    public function webhookReembolso(string $eventoId, string $intentoId, ?int $reembolsado = null): array
    {
        $cargo = $this->intentos[$intentoId]['cargo'];
        $reembolsado ??= $cargo['reembolsado'];
        $cuerpo = json_encode([
            'id' => $eventoId, 'tipo' => 'charge.refunded', 'creado' => time(), 'intento' => null,
            'cargo' => ['id' => $cargo['id'], 'intento_id' => $intentoId, 'monto' => $cargo['monto'], 'reembolsado' => $reembolsado, 'reembolso_total' => $reembolsado >= $cargo['monto']],
        ], JSON_THROW_ON_ERROR);

        return [$cuerpo, ['Stripe-Signature' => self::FIRMA]];
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
