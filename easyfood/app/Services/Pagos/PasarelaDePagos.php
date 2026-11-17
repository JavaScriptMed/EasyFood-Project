<?php

namespace App\Services\Pagos;

/**
 * Lo que EasyLunch necesita de la pasarela de pagos. La implementación real es Stripe (PasarelaStripe);
 * las pruebas usan una falsa, así que ninguna prueba llama a la red.
 *
 * Los datos salen como arreglos sencillos (no objetos del SDK) para no atar el resto del código a Stripe.
 */
interface PasarelaDePagos
{
    /** false si faltan las claves en .env: los endpoints de pago responden 503. */
    public function configurada(): bool;

    /**
     * Crea un intento de pago. `$llaveIdempotente` hace que repetir la llamada devuelva el mismo intento.
     *
     * @param  array<string, string>  $metadatos
     * @return array{id: string, client_secret: string, estado: string, monto: int, moneda: string}
     */
    public function crearIntento(int $centavos, string $moneda, array $metadatos, string $llaveIdempotente): array;

    /** @return array{id: string, client_secret: string, estado: string, monto: int, moneda: string} */
    public function obtenerIntento(string $intentoId): array;

    /**
     * Del cargo de un pago: cuándo se hizo (segundos Unix) y los últimos 4 dígitos de la tarjeta. Nada más.
     *
     * @return array{creado: int, ultimos4: ?string}|null
     */
    public function cargo(string $cargoId): ?array;

    /**
     * Verifica la firma del webhook con el cuerpo crudo y devuelve el evento ya normalizado.
     *
     * @return array{id: string, tipo: string, creado: int, intento: array{id: string, monto: int, moneda: string, estado: string, metadatos: array<string, string>, cargo_id: ?string}|null}
     *
     * @throws FirmaDeWebhookInvalida si la firma no corresponde
     */
    public function evento(string $cuerpo, ?string $firma): array;
}
