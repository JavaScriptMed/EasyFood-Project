<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Exceptions\ReglaDeNegocioException;
use App\Models\Corte;
use App\Models\Ruta;
use App\Services\Bitacora;
use App\Services\CalendarioDeServicio;
use App\Services\GeneradorDeRutas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RutaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $fecha = $request->validate(['fecha' => 'required|date_format:Y-m-d'])['fecha'];

        return response()->json(
            Ruta::query()->with(['repartidor.usuario', 'paradas'])->whereDate('fecha', $fecha)->orderBy('id')->get()->map(fn ($r) => Vistas::ruta($r, true))->values()
        );
    }

    /**
     * Completa las rutas de una fecha ya cortada con lo que quedó sin ruta (por ejemplo, cuando entra un repartidor nuevo).
     * No toca las rutas que ya existen; crea rutas nuevas en borrador y cierra las incidencias de lo que ya tiene repartidor.
     */
    public function generar(Request $request, GeneradorDeRutas $generador, CalendarioDeServicio $calendario): JsonResponse
    {
        $fecha = $request->validate(['fecha' => 'required|date_format:Y-m-d'])['fecha'];
        $dia = $calendario->dia($fecha);

        if (! $calendario->esDiaDeServicio($dia)) {
            throw new ReglaDeNegocioException('dia_sin_servicio', "El {$fecha} no es día de servicio: no hay rutas.");
        }
        if ($dia->lt($calendario->ahora()->startOfDay())) {
            throw new ReglaDeNegocioException('fecha_pasada', 'No se pueden generar rutas de una fecha que ya pasó.');
        }
        $corte = Corte::query()->whereDate('fecha_entrega', $fecha)->where('sin_cambios', false)->where('estado', '<>', 'fallido')->latest('id')->first();
        if (! $corte) {
            throw new ReglaDeNegocioException('corte_pendiente', "Todavía no se ejecuta el corte del {$fecha}: sin él no hay entregas que repartir.");
        }

        [$creadas, $sinRuta] = $generador->generar($corte, $fecha);
        Bitacora::registrar($request->user(), 'rutas.generadas', 'rutas', $fecha, "Generó rutas del {$fecha}: {$creadas} nueva(s), {$sinRuta} entrega(s) sin ruta.");

        return response()->json([
            'creadas' => $creadas, 'sin_ruta' => $sinRuta,
            'mensaje' => $creadas ? "Se crearon {$creadas} ruta(s) nueva(s)." : 'No se creó ninguna ruta nueva: no había entregas pendientes o no hay repartidores con capacidad.',
            'rutas' => Ruta::query()->with(['repartidor.usuario', 'paradas'])->whereDate('fecha', $fecha)->orderBy('id')->get()->map(fn ($r) => Vistas::ruta($r, true))->values(),
        ]);
    }

    /** Aprueba todas las rutas en borrador de una fecha. Devuelve cuántas aprobó. */
    public function aprobar(Request $request): JsonResponse
    {
        $fecha = $request->validate(['fecha' => 'required|date_format:Y-m-d'])['fecha'];

        $aprobadas = Ruta::query()->whereDate('fecha', $fecha)->where('estado', 'borrador')->update(['estado' => 'aprobada', 'actualizado_en' => now()]);
        if ($aprobadas > 0) {
            Bitacora::registrar($request->user(), 'rutas.aprobadas', 'rutas', $fecha, "Aprobó {$aprobadas} ruta(s) del {$fecha}.");
        }

        return response()->json(['aprobadas' => $aprobadas, 'mensaje' => $aprobadas ? "Se aprobaron {$aprobadas} ruta(s)." : 'No había rutas en borrador para esa fecha.']);
    }
}
