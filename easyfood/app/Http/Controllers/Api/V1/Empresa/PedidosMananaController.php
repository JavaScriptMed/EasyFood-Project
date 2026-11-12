<?php

namespace App\Http\Controllers\Api\V1\Empresa;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use App\Services\CalendarioDeServicio;
use App\Support\Api\ContextoDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PedidosMananaController extends Controller
{
    /**
     * Lo que han elegido los empleados de la empresa para la próxima entrega (la primera fecha de servicio con el corte abierto),
     * agrupado por platillo y de mayor a menor, y quiénes todavía no han elegido.
     */
    public function index(Request $request, CalendarioDeServicio $calendario): JsonResponse
    {
        $empresa = ContextoDelUsuario::empresa($request);
        $fecha = $calendario->fechaDeEntregaParaPedidoNuevo(null, $empresa->id)->toDateString();

        $elecciones = DB::table('elecciones_empleado as e')->join('empleados as emp', 'emp.id', '=', 'e.empleado_id')
            ->join('users as u', 'u.id', '=', 'emp.usuario_id')->join('menus as m', 'm.id', '=', 'e.menu_id')
            ->where('emp.empresa_id', $empresa->id)->whereDate('e.fecha', $fecha)->orderBy('u.name')
            ->get(['e.menu_id', 'm.name as platillo', 'emp.id as empleado_id', 'u.name as nombre']);

        $grupos = $elecciones->groupBy('menu_id')->map(fn ($filas, $menuId) => [
            'platillo_id' => (int) $menuId, 'platillo_nombre' => $filas->first()->platillo,
            'personas' => $filas->map(fn ($f) => ['empleado_id' => $f->empleado_id, 'nombre' => $f->nombre])->unique('empleado_id')->values()->all(),
        ])->sortByDesc(fn ($g) => count($g['personas']))->values();

        $conEleccion = $elecciones->pluck('empleado_id')->unique();
        $pendientes = Empleado::query()->activos()->with('usuario')->where('empresa_id', $empresa->id)->whereNotIn('id', $conEleccion)->get()
            ->sortBy(fn ($e) => $e->usuario->name)->map(fn ($e) => ['empleado_id' => $e->id, 'nombre' => $e->usuario->name])->values();

        return response()->json(['fecha' => $fecha, 'total' => $grupos->sum(fn ($g) => count($g['personas'])), 'grupos' => $grupos->all(), 'pendientes' => $pendientes->all()]);
    }
}
