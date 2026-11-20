<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Ubica una dirección escrita en el mapa (Nominatim / OpenStreetMap) cuando la app no manda las coordenadas.
 * Idea de Alberto (AddressController de rama_chida). A diferencia de su versión, si no la encuentra devuelve null
 * en lugar de inventar un punto en el centro de la ciudad: un domicilio mal ubicado es una entrega perdida.
 */
class Geocodificador
{
    /** @return array{latitud: float, longitud: float}|null */
    public function ubicar(string $direccion, ?string $codigoPostal = null): ?array
    {
        $consulta = trim($direccion.($codigoPostal ? ", C.P. {$codigoPostal}" : '').', Jalisco, México');

        try {
            $respuesta = Http::withUserAgent('EasyLunch')->timeout(5)->get('https://nominatim.openstreetmap.org/search', [
                'q' => $consulta, 'countrycodes' => 'mx', 'format' => 'json', 'limit' => 1,
            ]);
        } catch (\Throwable) {
            return null;
        }

        $resultado = $respuesta->successful() ? ($respuesta->json()[0] ?? null) : null;

        return isset($resultado['lat'], $resultado['lon']) ? ['latitud' => (float) $resultado['lat'], 'longitud' => (float) $resultado['lon']] : null;
    }
}
