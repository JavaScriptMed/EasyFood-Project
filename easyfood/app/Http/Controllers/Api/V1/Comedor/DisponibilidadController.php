<?php

namespace App\Http\Controllers\Api\V1\Comedor;

use App\Exceptions\ReglaDeNegocioException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\DisponibilidadDia;
use App\Models\Menu;
use App\Services\CalendarioDeServicio;
use App\Support\Api\ContextoDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DisponibilidadController extends Controller
{
    /** Porciones por platillo y día del propio comedor entre `desde` y `hasta` (AAAA-MM-DD). */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['desde' => 'required|date_format:Y-m-d', 'hasta' => 'required|date_format:Y-m-d|after_or_equal:desde']);
        $comedor = ContextoDelUsuario::comedor($request);

        return response()->json(
            DisponibilidadDia::query()->whereHas('menu', fn ($q) => $q->where('provider_id', $comedor->id))
                ->whereBetween('fecha', [$f['desde'], $f['hasta']])->orderBy('fecha')->orderBy('menu_id')->get()->map(fn ($d) => Vistas::disponibilidad($d))->values()
        );
    }

    /**
     * Guarda los cambios (crea o actualiza) en una sola operación: o se guardan todos o ninguno. Solo para platillos
     * publicados del propio comedor y para fechas cuyo corte aún no se ejecuta. Devuelve cuántos cambios guardó.
     */
    public function guardar(Request $request, CalendarioDeServicio $calendario): JsonResponse
    {
        $datos = $request->validate([
            'cambios' => 'required|array|min:1|max:500', 'cambios.*.platillo_id' => 'required|integer',
            'cambios.*.fecha' => 'required|date_format:Y-m-d', 'cambios.*.porciones_maximas' => 'required|integer|min:0|max:100000',
        ]);
        $comedor = ContextoDelUsuario::comedor($request);

        $guardados = DB::transaction(function () use ($datos, $comedor, $calendario) {
            $platillos = Menu::query()->where('provider_id', $comedor->id)->whereIn('id', collect($datos['cambios'])->pluck('platillo_id'))->get()->keyBy('id');

            foreach ($datos['cambios'] as $cambio) {
                $platillo = $platillos->get($cambio['platillo_id']) ?? throw new ReglaDeNegocioException('platillo_ajeno', "El platillo {$cambio['platillo_id']} no es de tu comedor.");
                if ($platillo->estado_revision !== 'publicado') {
                    throw new ReglaDeNegocioException('platillo_no_publicado', "«{$platillo->name}» todavía no está publicado: no se le pueden poner porciones.");
                }
                if (! $calendario->corteAbierto($cambio['fecha'])) {
                    throw new ReglaDeNegocioException('corte_cerrado', "El corte del {$cambio['fecha']} ya pasó: ya no se pueden cambiar sus porciones.");
                }
                DisponibilidadDia::query()->updateOrCreate(['menu_id' => $platillo->id, 'fecha' => $cambio['fecha']], ['porciones_maximas' => $cambio['porciones_maximas']]);
            }

            return count($datos['cambios']);
        });

        return response()->json(['guardados' => $guardados, 'mensaje' => "Se guardaron {$guardados} cambio(s)."]);
    }
}
