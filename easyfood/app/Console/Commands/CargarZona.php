<?php

namespace App\Console\Commands;

use App\Exceptions\ReglaDeNegocioException;
use App\Services\ZonasDeEntrega;
use Illuminate\Console\Command;

class CargarZona extends Command
{
    protected $signature = 'easylunch:cargar-zona {nombre : Nombre de la zona (el mismo que usan empresas, repartidores y direcciones)} {archivo : Archivo GeoJSON con su polígono, o «-» para leerlo de la entrada estándar}';

    protected $description = 'Carga (o reemplaza) una zona de entrega desde un archivo GeoJSON (Polygon, MultiPolygon, Feature o FeatureCollection) y recalcula la zona de todas las direcciones';

    public function handle(ZonasDeEntrega $zonas): int
    {
        $ruta = $this->argument('archivo');
        $contenido = $ruta === '-' ? stream_get_contents(STDIN) : (is_file($ruta) && is_readable($ruta) ? file_get_contents($ruta) : false);
        if ($contenido === false || $contenido === '') {
            $this->error($ruta === '-' ? 'No llegó nada por la entrada estándar.' : "No puedo leer el archivo {$ruta}.");

            return self::FAILURE;
        }

        try {
            $zona = $zonas->guardar(null, $this->argument('nombre'), json_decode($contenido, true) ?? []);
        } catch (ReglaDeNegocioException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($zona['creada'] ? 'Zona creada' : 'Zona actualizada')." «{$zona['nombre']}» (".number_format($zona['area_km2'], 1).' km²). Las direcciones ya registradas se recalcularon.');

        return self::SUCCESS;
    }
}
