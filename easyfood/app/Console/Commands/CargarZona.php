<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CargarZona extends Command
{
    protected $signature = 'easylunch:cargar-zona {nombre : Nombre de la zona (el mismo que usan empresas, repartidores y direcciones)} {archivo : Archivo GeoJSON con su polígono, o «-» para leerlo de la entrada estándar}';

    protected $description = 'Carga (o reemplaza) una zona de entrega desde un archivo GeoJSON (Polygon, MultiPolygon, Feature o FeatureCollection) y recalcula la zona de todas las direcciones';

    public function handle(): int
    {
        $nombre = trim($this->argument('nombre'));
        $ruta = $this->argument('archivo');
        if ($nombre === '') {
            $this->error('Escribe el nombre de la zona.');

            return self::FAILURE;
        }
        $contenido = $ruta === '-' ? stream_get_contents(STDIN) : (is_file($ruta) && is_readable($ruta) ? file_get_contents($ruta) : false);
        if ($contenido === false || $contenido === '') {
            $this->error($ruta === '-' ? 'No llegó nada por la entrada estándar.' : "No puedo leer el archivo {$ruta}.");

            return self::FAILURE;
        }

        $geometria = $this->geometriaDe(json_decode($contenido, true));
        if (! $geometria) {
            $this->error('El archivo no trae un polígono GeoJSON (Polygon o MultiPolygon).');

            return self::FAILURE;
        }
        if (! $this->coordenadasValidas($geometria['coordinates'])) {
            $this->error('Las coordenadas deben ir como [longitud, latitud] (Guadalajara ≈ [-103.35, 20.67]) y los anillos deben cerrar.');

            return self::FAILURE;
        }

        $json = json_encode($geometria);
        $revision = DB::selectOne('select ST_IsValid(g) as valido, ST_IsValidReason(g) as motivo from (select ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) as g) t', [$json]);
        if (! $revision->valido) {
            $this->error("El polígono no es válido: {$revision->motivo}");

            return self::FAILURE;
        }

        $existente = DB::table('zonas')->whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])->first();
        if ($existente) {
            DB::update('update zonas set area = ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))::geography, activo = true, desactivado_en = null, actualizado_en = now() where id = ?', [$json, $existente->id]);
        } else {
            DB::insert('insert into zonas (nombre, area) values (?, ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))::geography)', [$nombre, $json]);
        }

        $km2 = DB::selectOne('select ST_Area(area) / 1000000 as km2 from zonas where lower(nombre) = ?', [mb_strtolower($nombre)])->km2;
        $this->info(($existente ? 'Zona actualizada' : 'Zona creada').": «{$nombre}» (".number_format((float) $km2, 1).' km²). Las direcciones ya registradas se recalcularon.');

        return self::SUCCESS;
    }

    /** @return array{type: string, coordinates: array}|null */
    private function geometriaDe(mixed $datos): ?array
    {
        if (! is_array($datos)) {
            return null;
        }
        if (($datos['type'] ?? null) === 'FeatureCollection') {
            $datos = $datos['features'][0] ?? null;
        }
        if (is_array($datos) && ($datos['type'] ?? null) === 'Feature') {
            $datos = $datos['geometry'] ?? null;
        }

        return is_array($datos) && in_array($datos['type'] ?? null, ['Polygon', 'MultiPolygon'], true) && is_array($datos['coordinates'] ?? null)
            ? ['type' => $datos['type'], 'coordinates' => $datos['coordinates']] : null;
    }

    /** Todos los puntos son [lon, lat] dentro de rango y cada anillo está cerrado. */
    private function coordenadasValidas(array $coordenadas): bool
    {
        foreach ($coordenadas as $nivel) {
            if (! is_array($nivel)) {
                return false;
            }
            if (isset($nivel[0]) && is_numeric($nivel[0])) { // un punto
                return count($nivel) >= 2 && abs((float) $nivel[0]) <= 180 && abs((float) $nivel[1]) <= 90;
            }
            if (isset($nivel[0][0]) && is_numeric($nivel[0][0])) { // un anillo
                if (count($nivel) < 4 || $nivel[0] != $nivel[count($nivel) - 1]) {
                    return false;
                }
                foreach ($nivel as $punto) {
                    if (! $this->coordenadasValidas([$punto])) {
                        return false;
                    }
                }

                continue;
            }
            if (! $this->coordenadasValidas($nivel)) {
                return false;
            }
        }

        return true;
    }
}
