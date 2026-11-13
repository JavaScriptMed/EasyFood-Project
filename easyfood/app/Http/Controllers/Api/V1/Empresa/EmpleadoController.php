<?php

namespace App\Http\Controllers\Api\V1\Empresa;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\EleccionEmpleado;
use App\Models\Empleado;
use App\Services\Bitacora;
use App\Services\CalendarioDeServicio;
use App\Support\Api\ContextoDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmpleadoController extends Controller
{
    /** Empleados de la empresa de quien consulta, con lo que han elegido por mes y su elección de la próxima entrega. */
    public function index(Request $request, CalendarioDeServicio $calendario): JsonResponse
    {
        $empresa = ContextoDelUsuario::empresa($request);
        $empleados = Empleado::query()->with('usuario.profile')->where('empresa_id', $empresa->id)->orderBy('id')->get();
        $manana = $calendario->fechaDeEntregaParaPedidoNuevo(null, $empresa->id)->toDateString();

        $porMes = DB::table('elecciones_empleado')->whereIn('empleado_id', $empleados->pluck('id'))
            ->selectRaw("empleado_id, to_char(fecha, 'YYYY-MM') as mes, count(*) as total")->groupBy('empleado_id', 'mes')->get()->groupBy('empleado_id');
        $elecciones = EleccionEmpleado::query()->with('menu')->whereIn('empleado_id', $empleados->pluck('id'))->whereDate('fecha', $manana)->get()->keyBy('empleado_id');

        return response()->json($empleados->map(function (Empleado $e) use ($porMes, $elecciones) {
            $eleccion = $elecciones->get($e->id);

            return Vistas::empleado($e) + [
                'platillos_por_mes' => ($porMes->get($e->id) ?? collect())->pluck('total', 'mes')->map(fn ($n) => (int) $n)->all(),
                'invitacion' => null,
                'eleccion_manana' => $eleccion ? ['platillo_id' => $eleccion->menu_id, 'platillo_nombre' => $eleccion->menu->name, 'origen' => $eleccion->origen] : null,
            ];
        })->values());
    }

    /** Activa o desactiva a un empleado de la propia empresa. Desactivado ya no puede elegir ni recibe platillo por defecto. */
    public function estado(Request $request, int $id): JsonResponse
    {
        $activo = $request->validate(['activo' => 'required|boolean'])['activo'];
        $empleado = Empleado::query()->with('usuario.profile')->where('empresa_id', ContextoDelUsuario::empresa($request)->id)->findOrFail($id);

        if ($empleado->activo !== $activo) {
            $activo ? $empleado->reactivar() : $empleado->desactivar();
            Bitacora::registrar($request->user(), $activo ? 'empleado.reactivado' : 'empleado.desactivado', 'empleados', $empleado->id, ($activo ? 'Reactivó' : 'Desactivó')." a {$empleado->usuario->name}.");
        }

        return response()->json(Vistas::empleado($empleado->refresh()));
    }
}
