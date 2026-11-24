<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Las zonas de entrega: un polígono por zona (PostGIS). Su nombre es el que se escribe en empresas, comedores, repartidores, rutas y direcciones,
 * por eso solo se cambia con `renombrar`, que actualiza todos esos textos en la misma transacción (editar el polígono nunca toca el nombre). Se valida el GeoJSON antes de guardar
 * (coordenadas [longitud, latitud], anillos cerrados, sin cruzarse), y al guardar la base recalcula la zona de todas las direcciones.
 *
 * Lo usan el comando `easylunch:cargar-zona` y la API (`/admin/zonas`).
 */
class ZonasDeEntrega
{
    /**
     * Crea la zona, o reemplaza su polígono si ya existe una con ese nombre (sin importar mayúsculas).
     *
     * @param  array<string, mixed>  $geojson  Polygon, MultiPolygon, Feature o FeatureCollection de un solo polígono
     * @return array{id: int, nombre: string, creada: bool, area_km2: float}
     */
    public function guardar(?User $actor, string $nombre, array $geojson, bool $soloSiNueva = false): array
    {
        $nombre = trim($nombre);
        if ($nombre === '' || mb_strlen($nombre) > 100) {
            throw new ReglaDeNegocioException('nombre_de_zona_invalido', 'El nombre de la zona es obligatorio y de hasta 100 caracteres.');
        }
        $json = json_encode($this->geometriaValida($geojson));

        return DB::transaction(function () use ($actor, $nombre, $json, $soloSiNueva) {
            $existente = DB::table('zonas')->whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])->lockForUpdate()->first();
            if ($existente && $soloSiNueva) {
                throw new ReglaDeNegocioException('zona_repetida', "Ya hay una zona llamada «{$existente->nombre}»: edítala en lugar de crearla de nuevo.");
            }

            if ($existente) {
                DB::update('update zonas set area = ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))::geography, actualizado_en = now() where id = ?', [$json, $existente->id]);
                $id = $existente->id;
                $nombreFinal = $existente->nombre;
            } else {
                $id = DB::selectOne('insert into zonas (nombre, area) values (?, ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))::geography) returning id', [$nombre, $json])->id;
                $nombreFinal = $nombre;
            }
            Bitacora::registrar($actor, $existente ? 'actualizar' : 'crear', 'zonas', $id, ($existente ? 'Cambió el polígono de' : 'Creó').' la zona «'.$nombreFinal.'».');

            return ['id' => $id, 'nombre' => $nombreFinal, 'creada' => ! $existente, 'area_km2' => $this->area($id)];
        });
    }

    /**
     * Cambia el nombre de una zona (por ejemplo, para corregir una falta de ortografía) y lo actualiza en todos los lugares donde está escrito como
     * texto: empresas, comedores, repartidores y rutas. Las direcciones se recalculan solas (el trigger de `zonas`). Todo o nada.
     *
     * @return array<string, mixed> la zona con su polígono, como `detalle`
     */
    public function renombrar(?User $actor, int $id, string $nombre): array
    {
        $nombre = trim($nombre);
        if ($nombre === '' || mb_strlen($nombre) > 100) {
            throw new ReglaDeNegocioException('nombre_de_zona_invalido', 'El nombre de la zona es obligatorio y de hasta 100 caracteres.');
        }

        return DB::transaction(function () use ($actor, $id, $nombre) {
            $zona = DB::table('zonas')->where('id', $id)->lockForUpdate()->first() ?? throw new \Illuminate\Database\Eloquent\ModelNotFoundException;
            if ($zona->nombre === $nombre) {
                return $this->detalle($id);
            }
            $repetida = DB::table('zonas')->where('id', '<>', $id)->whereRaw('lower(nombre) = lower(?)', [$nombre])->first();
            if ($repetida) {
                throw new ReglaDeNegocioException('zona_repetida', "Ya hay otra zona llamada «{$repetida->nombre}»: elige un nombre distinto.");
            }

            DB::update('update zonas set nombre = ?, actualizado_en = now() where id = ?', [$nombre, $id]);
            foreach ([['empresas', 'zona'], ['providers', 'zona'], ['repartidores', 'zona_base'], ['rutas', 'zona']] as [$tabla, $columna]) {
                DB::table($tabla)->whereRaw("lower({$columna}) = lower(?)", [$zona->nombre])->update([$columna => $nombre]);
            }
            Bitacora::registrar($actor, 'actualizar', 'zonas', $id, "Cambió el nombre de la zona «{$zona->nombre}» a «{$nombre}».");

            return $this->detalle($id);
        });
    }

    /**
     * Carga varias zonas de una vez (un FeatureCollection con `properties.nombre` en cada polígono). Todo o nada: si una falla, no se guarda ninguna.
     *
     * @param  array<string, mixed>  $coleccion
     * @return array<int, array{id: int, nombre: string, creada: bool, area_km2: float}>
     */
    public function importar(?User $actor, array $coleccion): array
    {
        $features = $coleccion['features'] ?? null;
        if (($coleccion['type'] ?? null) !== 'FeatureCollection' || ! is_array($features) || $features === []) {
            throw new ReglaDeNegocioException('geojson_invalido', 'Manda un FeatureCollection con una zona por polígono, cada una con su nombre en properties.nombre.');
        }
        $nombres = [];
        foreach ($features as $n => $feature) {
            $nombre = trim((string) ($feature['properties']['nombre'] ?? $feature['properties']['name'] ?? ''));
            if ($nombre === '') {
                throw new ReglaDeNegocioException('nombre_de_zona_invalido', 'La zona número '.($n + 1).' no tiene nombre: ponlo en properties.nombre.');
            }
            if (in_array(mb_strtolower($nombre), $nombres, true)) {
                throw new ReglaDeNegocioException('zona_repetida', "El nombre «{$nombre}» viene dos veces en el archivo.");
            }
            $nombres[] = mb_strtolower($nombre);
        }

        return DB::transaction(function () use ($actor, $features) {
            $resultado = [];
            foreach ($features as $feature) {
                $nombre = trim((string) ($feature['properties']['nombre'] ?? $feature['properties']['name']));
                try {
                    $resultado[] = $this->guardar($actor, $nombre, $feature);
                } catch (ReglaDeNegocioException $e) {
                    throw new ReglaDeNegocioException($e->codigo, "Zona «{$nombre}»: {$e->getMessage()} No se guardó ninguna.");
                }
            }

            return $resultado;
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function lista(): array
    {
        return array_map(fn ($z) => $this->vista($z), DB::select($this->consultaBase().' order by z.nombre'));
    }

    /**
     * Las zonas donde hay servicio (solo las activas), para mostrarlas sin iniciar sesión: nombre y un polígono simplificado (unos 40 m de
     * tolerancia, 4 decimales) como Feature de GeoJSON. No incluye conteos ni nada interno.
     *
     * @return array<int, array{id: int, nombre: string, geojson: array<string, mixed>}>
     */
    public function disponibles(): array
    {
        $filas = DB::select('select id, nombre, ST_AsGeoJSON(ST_SimplifyPreserveTopology(area::geometry, 0.0004), 4) as g from zonas where activo order by nombre');

        return array_map(fn ($z) => [
            'id' => $z->id, 'nombre' => $z->nombre,
            'geojson' => ['type' => 'Feature', 'properties' => ['nombre' => $z->nombre], 'geometry' => json_decode($z->g, true)],
        ], $filas);
    }

    /** @return array<string, mixed> la zona con su polígono como un Feature de GeoJSON */
    public function detalle(int $id): array
    {
        $z = DB::selectOne($this->consultaBase().' where z.id = ?', [$id]) ?? throw new \Illuminate\Database\Eloquent\ModelNotFoundException;
        $geometria = DB::selectOne('select ST_AsGeoJSON(area::geometry, 6) as g from zonas where id = ?', [$id])->g;

        return $this->vista($z) + ['geojson' => ['type' => 'Feature', 'properties' => ['nombre' => $z->nombre], 'geometry' => json_decode($geometria, true)]];
    }

    /**
     * Calcula qué zona activa cubre un punto (PostGIS ST_Covers). Si dos zonas se traslapan, gana la más pequeña
     * (la más específica); a igualdad, la de menor id. Devuelve null si el punto está fuera de cobertura.
     */
    public function zonaParaPunto(float $latitud, float $longitud): ?string
    {
        $fila = DB::selectOne(
            'select z.nombre from zonas z where z.activo and ST_Covers(z.area, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) order by ST_Area(z.area), z.id limit 1',
            [$longitud, $latitud]
        );

        return $fila?->nombre;
    }

    /** Desactivar una zona deja sin cobertura las direcciones que cubría: no se puede mientras haya empresas, comedores o repartidores activos en ella. */
    public function cambiarEstado(?User $actor, int $id, bool $activo): array
    {
        $z = DB::table('zonas')->where('id', $id)->first() ?? throw new \Illuminate\Database\Eloquent\ModelNotFoundException;
        if ((bool) $z->activo !== $activo) {
            if (! $activo) {
                $usos = $this->usosActivos($z->nombre);
                if (array_sum($usos) > 0) {
                    throw new ReglaDeNegocioException('zona_en_uso', "La zona «{$z->nombre}» la usan {$usos['empresas']} empresa(s), {$usos['comedores']} comedor(es) y {$usos['repartidores']} repartidor(es) activos: cámbiales la zona antes de desactivarla.");
                }
            }
            DB::update('update zonas set activo = ?, desactivado_en = ?, actualizado_en = now() where id = ?', [$activo ? 't' : 'f', $activo ? null : now(), $id]);
            Bitacora::registrar($actor, $activo ? 'reactivar' : 'desactivar', 'zonas', $id, ($activo ? 'Reactivó' : 'Desactivó')." la zona «{$z->nombre}».");
        }

        return $this->detalle($id);
    }

    // ------------------------------------------------------------------ validación del GeoJSON

    /** @return array{type: string, coordinates: array<int, mixed>} */
    private function geometriaValida(mixed $datos): array
    {
        $geometria = $this->geometriaDe($datos);
        if (! $geometria) {
            throw new ReglaDeNegocioException('geojson_invalido', 'El GeoJSON no trae un polígono (Polygon o MultiPolygon).');
        }
        if (! $this->coordenadasValidas($geometria['coordinates'])) {
            throw new ReglaDeNegocioException('coordenadas_invalidas', 'Las coordenadas deben ir como [longitud, latitud] (Guadalajara ≈ [-103.35, 20.67]) y los anillos deben cerrar.');
        }
        $revision = DB::selectOne('select ST_IsValid(g) as valido, ST_IsValidReason(g) as motivo from (select ST_SetSRID(ST_GeomFromGeoJSON(?), 4326) as g) t', [json_encode($geometria)]);
        if (! $revision->valido) {
            throw new ReglaDeNegocioException('poligono_invalido', "El polígono no es válido: {$revision->motivo}");
        }

        return $geometria;
    }

    /** @return array{type: string, coordinates: array<int, mixed>}|null */
    private function geometriaDe(mixed $datos): ?array
    {
        if (! is_array($datos)) {
            return null;
        }
        if (($datos['type'] ?? null) === 'FeatureCollection') {
            $datos = count($datos['features'] ?? []) === 1 ? $datos['features'][0] : null;
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
                return count($nivel) >= 2 && is_numeric($nivel[1]) && abs((float) $nivel[0]) <= 180 && abs((float) $nivel[1]) <= 90;
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

    // ------------------------------------------------------------------ apoyo

    private function consultaBase(): string
    {
        return "select z.id, z.nombre, z.activo, z.desactivado_en, round((ST_Area(z.area) / 1000000)::numeric, 2) as area_km2,
                  (select count(*) from addresses a where a.zona = z.nombre) as direcciones,
                  (select count(*) from empresas e where e.zona = z.nombre and e.activo) as empresas,
                  (select count(*) from providers p where p.zona = z.nombre and p.is_active) as comedores,
                  (select count(*) from repartidores r where r.zona_base = z.nombre and r.activo) as repartidores
                from zonas z";
    }

    /** @return array<string, mixed> */
    private function vista(object $z): array
    {
        return [
            'id' => $z->id, 'nombre' => $z->nombre, 'activo' => (bool) $z->activo, 'area_km2' => (float) $z->area_km2, 'direcciones' => (int) $z->direcciones,
            'empresas' => (int) $z->empresas, 'comedores' => (int) $z->comedores, 'repartidores' => (int) $z->repartidores,
        ];
    }

    /** @return array{empresas: int, comedores: int, repartidores: int} */
    private function usosActivos(string $nombre): array
    {
        return [
            'empresas' => DB::table('empresas')->where('zona', $nombre)->where('activo', true)->count(),
            'comedores' => DB::table('providers')->where('zona', $nombre)->where('is_active', true)->count(),
            'repartidores' => DB::table('repartidores')->where('zona_base', $nombre)->where('activo', true)->count(),
        ];
    }

    private function area(int $id): float
    {
        return (float) DB::selectOne('select round((ST_Area(area) / 1000000)::numeric, 2) as km2 from zonas where id = ?', [$id])->km2;
    }
}
