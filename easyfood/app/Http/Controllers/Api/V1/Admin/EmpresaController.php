<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Empresa;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class EmpresaController extends Controller
{
    public function index(): JsonResponse
    {
        $empresas = $this->consulta()->orderBy('nombre')->get();
        $this->conPlatillosDelMes($empresas);

        return response()->json($empresas->map(fn (Empresa $e) => Vistas::empresaDetalle($e))->values());
    }

    public function show(int $id): JsonResponse
    {
        $empresa = $this->consulta()->findOrFail($id);
        $this->conPlatillosDelMes(collect([$empresa]));

        return response()->json(Vistas::empresaDetalle($empresa));
    }

    private function consulta()
    {
        return Empresa::query()->with(['usuario.profile', 'usuario.role', 'contratos'])
            ->withCount(['empleados as empleados_activos_count' => fn ($q) => $q->where('activo', true)]);
    }

    /** Platillos elegidos en el mes en curso por los empleados de cada empresa. */
    private function conPlatillosDelMes($empresas): void
    {
        $inicio = now()->startOfMonth()->toDateString();
        $fin = now()->endOfMonth()->toDateString();
        $cuentas = DB::table('elecciones_empleado as e')->join('empleados as emp', 'emp.id', '=', 'e.empleado_id')
            ->whereBetween('e.fecha', [$inicio, $fin])->whereIn('emp.empresa_id', $empresas->pluck('id'))
            ->groupBy('emp.empresa_id')->selectRaw('emp.empresa_id, count(*) as total')->pluck('total', 'empresa_id');

        foreach ($empresas as $empresa) {
            $empresa->platillos_mes = (int) ($cuentas[$empresa->id] ?? 0);
        }
    }
}
