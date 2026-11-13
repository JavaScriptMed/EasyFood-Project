<?php

namespace App\Http\Controllers\Api\V1\Empleado;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\DisponibilidadDia;
use App\Models\EleccionEmpleado;
use App\Services\CalendarioDeServicio;
use App\Services\Dinero;
use App\Support\Api\ContextoDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    /**
     * El menú del empleado para una fecha de entrega (por defecto la próxima con el corte abierto) y un tiempo de comida
     * (por defecto «comida»): opciones publicadas con porciones restantes, lo que cubre la empresa y lo que paga él,
     * y su elección actual.
     */
    public function show(Request $request, CalendarioDeServicio $calendario): JsonResponse
    {
        $f = $request->validate(['fecha' => 'nullable|date_format:Y-m-d', 'tiempo_comida' => 'nullable|in:desayuno,comida,cena']);
        $tiempo = $f['tiempo_comida'] ?? 'comida';
        $empleado = ContextoDelUsuario::empleado($request)->load('empresa', 'usuario');
        $fecha = isset($f['fecha']) ? $calendario->dia($f['fecha']) : $calendario->fechaDeEntregaParaPedidoNuevo(null, $empleado->empresa_id);
        $contrato = $calendario->contratoVigente($empleado->empresa_id, $fecha);

        $cerradoPorEmpresa = ! $calendario->esDiaDeServicio($fecha, $empleado->empresa_id);
        $cerradoPorCorte = ! $calendario->corteAbierto($fecha);
        $precio = $contrato ? Dinero::centavos($contrato->precio_por_platillo) : 0;
        $cubre = $contrato ? (int) round($precio * (float) $contrato->porcentaje_subsidio / 100) : 0;

        $opciones = $cerradoPorEmpresa ? collect() : DisponibilidadDia::query()->with('menu.provider')->whereDate('fecha', $fecha->toDateString())
            ->whereHas('menu', fn ($q) => $q->where('estado_revision', 'publicado')->where('is_available', true)->where('tiempo_comida', $tiempo))
            ->orderBy('menu_id')->get();
        $eleccion = EleccionEmpleado::query()->with('menu')->where('empleado_id', $empleado->id)->whereDate('fecha', $fecha->toDateString())->where('tiempo_comida', $tiempo)->first();

        return response()->json([
            'fecha' => $fecha->toDateString(), 'tiempo_comida' => $tiempo, 'hora_corte' => $calendario->horaDeCorte(),
            'cerrado' => $cerradoPorEmpresa || $cerradoPorCorte, 'motivo_cerrado' => $cerradoPorEmpresa ? 'empresa_cerrada' : ($cerradoPorCorte ? 'corte' : null),
            'empleado' => ['id' => $empleado->id, 'nombre' => $empleado->usuario->name, 'empresa_nombre' => $empleado->empresa->nombre, 'activo' => $empleado->activo],
            'hora_entrega' => Vistas::hora($contrato?->hora_entrega), 'precio' => Vistas::dinero(Dinero::formato($precio)),
            'porcentaje_subsidio' => Vistas::dinero($contrato?->porcentaje_subsidio ?? 0),
            'empresa_cubre' => Vistas::dinero(Dinero::formato($cubre)), 'empleado_paga' => Vistas::dinero(Dinero::formato($precio - $cubre)),
            'opciones' => $opciones->map(fn (DisponibilidadDia $d) => [
                'platillo' => Vistas::platillo($d->menu), 'porciones_maximas' => $d->porciones_maximas, 'porciones_tomadas' => $d->porciones_tomadas,
                'porciones_restantes' => max(0, $d->porciones_maximas - $d->porciones_tomadas), 'agotado' => $d->porciones_tomadas >= $d->porciones_maximas,
            ])->values(),
            'eleccion' => $eleccion ? ['platillo_id' => $eleccion->menu_id, 'platillo_nombre' => $eleccion->menu->name, 'origen' => $eleccion->origen] : null,
        ]);
    }
}
