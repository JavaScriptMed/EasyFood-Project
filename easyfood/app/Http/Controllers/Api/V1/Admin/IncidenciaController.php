<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ReglaDeNegocioException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Incidencia;
use App\Services\Bitacora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncidenciaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'abiertas' => 'nullable|boolean', 'fecha' => 'nullable|date_format:Y-m-d', 'tipos' => 'nullable|array',
            'tipos.*' => 'in:falta_repartidores,pago_pendiente,ruta_excede_bloque,entrega_no_localizada,empresa_cerrada,empleado_ausente,repartidor_no_llego,platillo_danado,otro',
        ]);

        $incidencias = Incidencia::query()->with(['ruta.repartidor.usuario'])
            ->when($request->boolean('abiertas'), fn ($q) => $q->where('estado', '<>', 'resuelta'))
            ->when($f['fecha'] ?? null, fn ($q, $v) => $q->whereDate('fecha', $v))
            ->when($f['tipos'] ?? null, fn ($q, $v) => $q->whereIn('tipo', $v))
            ->orderByRaw("case prioridad when 'inmediata' then 0 else 1 end")->orderByDesc('fecha')->orderByDesc('id')->get();

        return response()->json($incidencias->map(fn ($i) => Vistas::incidencia($i))->values());
    }

    public function resolver(Request $request, int $id): JsonResponse
    {
        $nota = $request->validate(['nota' => 'nullable|string|max:1000'])['nota'] ?? null;
        $incidencia = Incidencia::query()->findOrFail($id);

        if ($incidencia->estado === 'resuelta') {
            throw new ReglaDeNegocioException('incidencia_ya_resuelta', 'Esa incidencia ya está resuelta.');
        }

        $incidencia->update(['estado' => 'resuelta', 'resuelta_en' => now(), 'nota' => $nota ?? $incidencia->nota]);
        Bitacora::registrar($request->user(), 'incidencia.resuelta', 'incidencias', $incidencia->id, "Resolvió la incidencia «{$incidencia->tipo}»".($nota ? ": {$nota}" : '.'));

        return response()->json(Vistas::incidencia($incidencia->refresh()->load('ruta.repartidor.usuario')));
    }
}
