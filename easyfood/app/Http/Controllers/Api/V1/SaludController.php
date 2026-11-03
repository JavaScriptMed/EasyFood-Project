<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GET /api/v1/salud: estado de la API y de la base de datos.
 * Responde 200 si todo funciona y 503 si la base de datos no responde (para monitoreo y balanceadores).
 */
class SaludController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $baseDeDatos = $this->revisarBaseDeDatos();
        $todoBien = $baseDeDatos['estado'] === 'ok';

        return response()->json([
            'estado' => $todoBien ? 'ok' : 'degradado',
            'api' => [
                'estado' => 'ok',
                'entorno' => app()->environment(),
                'zona_horaria' => config('app.timezone'),
                'hora' => now()->toIso8601String(),
            ],
            'base_de_datos' => $baseDeDatos,
        ], $todoBien ? 200 : 503)->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function revisarBaseDeDatos(): array
    {
        $inicio = hrtime(true);

        try {
            $conexion = DB::connection();
            $conexion->select('select 1');

            $datos = ['estado' => 'ok', 'motor' => $conexion->getDriverName()];

            if ($datos['motor'] === 'pgsql') {
                $datos['version'] = $conexion->selectOne("select current_setting('server_version') as v")->v;
                $datos['postgis'] = $this->versionPostgis($conexion);
            }

            $datos['latencia_ms'] = round((hrtime(true) - $inicio) / 1_000_000, 1);

            return $datos;
        } catch (Throwable $e) {
            // El detalle técnico va al log, no a la respuesta pública.
            Log::error('Falló la revisión de salud de la base de datos.', ['mensaje' => $e->getMessage()]);

            return ['estado' => 'error', 'mensaje' => 'No se pudo conectar con la base de datos.'];
        }
    }

    private function versionPostgis($conexion): ?string
    {
        try {
            return $conexion->selectOne('select postgis_lib_version() as v')->v;
        } catch (Throwable) {
            return null; // La extensión PostGIS no está instalada en esta base de datos.
        }
    }
}
