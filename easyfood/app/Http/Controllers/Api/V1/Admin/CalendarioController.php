<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ReglaDeNegocioException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Corte;
use App\Models\DiaSinServicio;
use App\Services\Bitacora;
use App\Services\CalendarioDeServicio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarioController extends Controller
{
    public function __construct(private readonly CalendarioDeServicio $calendario) {}

    /** Días sin servicio del mes (AAAA-MM; por defecto el actual) y las fechas cuyo corte ya se ejecutó. */
    public function show(Request $request): JsonResponse
    {
        $mes = $request->validate(['mes' => 'nullable|date_format:Y-m'])['mes'] ?? now()->format('Y-m');
        $inicio = $this->calendario->dia("{$mes}-01");
        $fin = $inicio->endOfMonth();

        return response()->json([
            'mes' => $mes,
            'dias' => DiaSinServicio::query()->with('empresa')->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
                ->orderBy('fecha')->orderBy('id')->get()->map(fn ($d) => Vistas::diaSinServicio($d))->values(),
            'fechas_cerradas' => Corte::query()->where('sin_cambios', false)->where('estado', '<>', 'fallido')
                ->whereBetween('fecha_entrega', [$inicio->toDateString(), $fin->toDateString()])->orderBy('fecha_entrega')->get()
                ->map(fn ($c) => $c->fecha_entrega->toDateString())->values(),
        ]);
    }

    public function marcarDia(Request $request): JsonResponse
    {
        $datos = $request->validate(['fecha' => 'required|date_format:Y-m-d', 'tipo' => 'required|in:festivo,sin_servicio', 'motivo' => 'required|string|max:255']);
        $this->exigirFechaModificable($datos['fecha']);

        if (DiaSinServicio::query()->whereDate('fecha', $datos['fecha'])->whereNull('empresa_id')->exists()) {
            throw new ReglaDeNegocioException('dia_ya_marcado', 'Ese día ya está marcado como día sin servicio.');
        }

        $dia = DiaSinServicio::create($datos);
        Bitacora::registrar($request->user(), 'calendario.dia_sin_servicio', 'dias_sin_servicio', $dia->id, "Marcó el {$dia->fecha->toDateString()} como {$dia->tipo}: {$dia->motivo}.");

        return response()->json(Vistas::diaSinServicio($dia->refresh()->load('empresa')), 201);
    }

    public function cerrarEmpresa(Request $request): JsonResponse
    {
        $datos = $request->validate(['fecha' => 'required|date_format:Y-m-d', 'empresa_id' => 'required|integer|exists:empresas,id', 'motivo' => 'required|string|max:255']);
        $this->exigirFechaModificable($datos['fecha']);

        if (DiaSinServicio::query()->whereDate('fecha', $datos['fecha'])->where('empresa_id', $datos['empresa_id'])->exists()) {
            throw new ReglaDeNegocioException('dia_ya_marcado', 'Esa empresa ya tiene cerrado ese día.');
        }

        $dia = DiaSinServicio::create($datos + ['tipo' => 'cierre_empresa']);
        Bitacora::registrar($request->user(), 'calendario.cierre_empresa', 'dias_sin_servicio', $dia->id, "Cerró la empresa {$dia->empresa_id} el {$dia->fecha->toDateString()}: {$dia->motivo}.");

        return response()->json(Vistas::diaSinServicio($dia->refresh()->load('empresa')), 201);
    }

    public function quitarDia(Request $request, int $id): JsonResponse
    {
        $dia = DiaSinServicio::query()->findOrFail($id);
        $this->exigirFechaModificable($dia->fecha->toDateString());

        $dia->delete();
        Bitacora::registrar($request->user(), 'calendario.dia_restablecido', 'dias_sin_servicio', $id, "Quitó el día sin servicio del {$dia->fecha->toDateString()}.");

        return response()->json(['mensaje' => 'Listo: ese día vuelve a tener servicio.']);
    }

    /** No se tocan fechas pasadas ni fechas cuyo corte ya se ejecutó (las elecciones ya se cerraron con ese calendario). */
    private function exigirFechaModificable(string $fecha): void
    {
        if ($this->calendario->dia($fecha)->lte($this->calendario->ahora()->startOfDay())) {
            throw new ReglaDeNegocioException('fecha_pasada', 'Solo se pueden cambiar fechas futuras.');
        }
        if ($this->calendario->corteYaEjecutado($fecha)) {
            throw new ReglaDeNegocioException('corte_ejecutado', 'El corte de esa fecha ya se ejecutó: el calendario ya no se puede cambiar.');
        }
    }
}
