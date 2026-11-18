<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ReglaDeNegocioException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Repartidor;
use App\Services\AltaDeCuentas;
use App\Services\Bitacora;
use App\Services\CalendarioDeServicio;
use App\Services\EdicionDeRegistros;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RepartidorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $estado = $request->validate(['estado' => 'nullable|in:libre,asignado,en_ruta,no_disponible'])['estado'] ?? null;

        return response()->json(
            Repartidor::query()->activos()->with('usuario')->when($estado, fn ($q) => $q->where('estado', $estado))->orderBy('id')->get()->map(fn ($r) => Vistas::repartidor($r))->values()
        );
    }

    /**
     * Da de alta a un repartidor (su cuenta con invitación y su ficha). Solo se reparte en auto: las placas son obligatorias y,
     * si se manda `tipo_vehiculo`, tiene que ser `automovil`. Sin `numero_empleado` se asigna el siguiente (R-003).
     */
    public function store(Request $request, AltaDeCuentas $altas): JsonResponse
    {
        $d = $request->validate([
            'nombre' => 'required|string|min:2|max:120', 'correo' => 'required|email|max:190', 'telefono' => 'nullable|string|max:20', 'numero_empleado' => 'nullable|string|max:20',
            'tipo_vehiculo' => 'nullable|string', 'placas' => 'required|string|min:3|max:15', 'zona_base' => 'required|string|max:100',
        ]);
        if (($d['tipo_vehiculo'] ?? 'automovil') !== 'automovil') {
            throw new ReglaDeNegocioException('solo_automovil', 'Solo se reparte en auto: elige «automóvil» y captura sus placas.');
        }

        $repartidor = $altas->crearRepartidor($request->user(), $d);

        return response()->json(Vistas::repartidor($repartidor->load('usuario')), 201);
    }

    /** Solo hay autos: cuántas entregas y cuántos platillos caben en uno (configuración). */
    public function capacidades(CalendarioDeServicio $calendario): JsonResponse
    {
        $capacidad = $calendario->capacidadDelAuto();

        return response()->json(['automovil' => $capacidad['entregas'], 'entregas' => $capacidad['entregas'], 'platillos' => $capacidad['platillos']]);
    }

    /** Alterna entre «libre» y «no disponible». Con una ruta asignada o en curso no se puede apagar. */
    public function disponibilidad(Request $request, int $id): JsonResponse
    {
        $repartidor = Repartidor::query()->activos()->with('usuario')->findOrFail($id);

        if (in_array($repartidor->estado, ['asignado', 'en_ruta'], true)) {
            throw new ReglaDeNegocioException('repartidor_ocupado', 'Ese repartidor tiene una ruta asignada o en curso: no se puede cambiar su disponibilidad.');
        }

        $nuevo = $repartidor->estado === 'libre' ? 'no_disponible' : 'libre';
        $repartidor->update(['estado' => $nuevo]);
        Bitacora::registrar($request->user(), 'repartidor.disponibilidad', 'repartidores', $repartidor->id, "{$repartidor->usuario?->name} pasó a «{$nuevo}».");

        return response()->json(Vistas::repartidor($repartidor->refresh()->load('usuario')));
    }

    public function update(Request $request, int $id, EdicionDeRegistros $ediciones): JsonResponse
    {
        $d = $request->validate([
            'nombre' => 'required|string|min:2|max:120', 'telefono' => 'nullable|string|max:20', 'numero_empleado' => 'nullable|string|max:20',
            'tipo_vehiculo' => 'nullable|string', 'placas' => 'required|string|min:3|max:15', 'zona_base' => 'required|string|max:100',
        ]);
        $repartidor = $ediciones->editarRepartidor($request->user(), Repartidor::query()->with('usuario')->findOrFail($id), $d);

        return response()->json(Vistas::repartidor($repartidor->load('usuario')));
    }

    /** Desactiva o reactiva a un repartidor (no se puede desactivar con rutas por hacer). */
    public function estado(Request $request, int $id, EdicionDeRegistros $ediciones): JsonResponse
    {
        $activo = $request->validate(['activo' => 'required|boolean'])['activo'];
        $repartidor = $ediciones->cambiarEstadoDeRepartidor($request->user(), Repartidor::query()->with('usuario')->findOrFail($id), $activo);

        return response()->json(Vistas::repartidor($repartidor->load('usuario')));
    }

    /** Cuántas entregas caben en un auto. Solo cambia las rutas que se armen desde ahora. */
    public function guardarCapacidades(Request $request, EdicionDeRegistros $ediciones, CalendarioDeServicio $calendario): JsonResponse
    {
        $d = $request->validate(['automovil' => 'required|integer|min:1|max:500']);
        $ediciones->guardarCapacidadDelAuto($request->user(), (int) $d['automovil']);

        return $this->capacidades($calendario);
    }
}
