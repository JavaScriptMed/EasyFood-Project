<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Configuracion;
use App\Services\Bitacora;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConfiguracionController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(self::actual());
    }

    public function update(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'hora_corte' => ['required', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'],
            'dias_entrega' => 'required|in:lunes_viernes,lunes_sabado',
            'regla_sin_eleccion' => 'required|in:ultimo_platillo,platillo_del_dia,no_entregar',
            'capacidad_auto_entregas' => 'required|integer|min:1|max:100000',
            'capacidad_auto_platillos' => 'required|integer|min:1|max:100000',
        ]);

        $antes = self::actual();
        DB::transaction(function () use ($datos, $request) {
            foreach ($datos as $clave => $valor) {
                Configuracion::query()->whereKey($clave)->update(['valor' => (string) $valor, 'actualizado_por' => $request->user()->id, 'actualizado_en' => now()]);
            }
        });

        $cambios = collect($datos)->filter(fn ($valor, $clave) => (string) $antes[$clave] !== (string) $valor)->map(fn ($valor, $clave) => "{$clave}: {$antes[$clave]} → {$valor}");
        if ($cambios->isNotEmpty()) {
            Bitacora::registrar($request->user(), 'configuracion.actualizada', 'configuracion', 'operacion', $cambios->implode('; ').'.');
        }

        return response()->json(self::actual());
    }

    public static function actual(): array
    {
        $v = Configuracion::query()->pluck('valor', 'clave');

        return [
            'hora_corte' => $v['hora_corte'] ?? '18:00', 'zona_horaria' => config('app.timezone'), 'dias_entrega' => $v['dias_entrega'] ?? 'lunes_sabado',
            'regla_sin_eleccion' => $v['regla_sin_eleccion'] ?? 'ultimo_platillo',
            'capacidad_auto_entregas' => (int) ($v['capacidad_auto_entregas'] ?? 30), 'capacidad_auto_platillos' => (int) ($v['capacidad_auto_platillos'] ?? 120),
        ];
    }
}
