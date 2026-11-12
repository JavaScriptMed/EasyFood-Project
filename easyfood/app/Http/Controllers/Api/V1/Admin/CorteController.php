<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Configuracion;
use App\Models\Corte;
use App\Models\EntregaEmpresa;
use App\Models\Ruta;
use App\Services\Bitacora;
use App\Services\CalendarioDeServicio;
use App\Services\EjecutorDelCorte;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CorteController extends Controller
{
    /** Historial de cortes, del más reciente al más antiguo. */
    public function index(Request $request): JsonResponse
    {
        $limite = (int) ($request->validate(['limite' => 'nullable|integer|min:1|max:200'])['limite'] ?? 60);

        return response()->json(Corte::query()->orderByDesc('ejecutado_en')->orderByDesc('id')->limit($limite)->get()->map(fn ($c) => Vistas::corte($c))->values());
    }

    /**
     * «Ejecutar corte ahora». Sin `fecha_entrega` corta la primera fecha de servicio que todavía no se ha cortado.
     * Es idempotente: repetirlo no cambia nada y queda como «sin cambios».
     */
    public function ejecutar(Request $request, EjecutorDelCorte $ejecutor, CalendarioDeServicio $calendario): JsonResponse
    {
        $fecha = $request->validate(['fecha_entrega' => 'nullable|date_format:Y-m-d'])['fecha_entrega']
            ?? $this->primeraFechaSinCortar($calendario)->toDateString();

        $corte = $ejecutor->ejecutar($fecha, $request->user()->id);
        Bitacora::registrar($request->user(), 'corte.ejecutado', 'cortes', $corte->id,
            $corte->sin_cambios ? "Ejecutó el corte del {$fecha}: sin cambios (ya estaba cerrado)." : "Ejecutó el corte del {$fecha}: {$corte->estado}.");

        return response()->json($this->resultado($corte, $fecha, $calendario));
    }

    /** Qué va a pasar con la próxima fecha sin cortar: cuántos empleados activos hay y cuántos todavía no han elegido. */
    public function vistaPrevia(CalendarioDeServicio $calendario): JsonResponse
    {
        $fecha = $this->primeraFechaSinCortar($calendario)->toDateString();
        $activos = $this->empleadosActivosEseDia($fecha);
        $conEleccion = DB::table('elecciones_empleado')->whereDate('fecha', $fecha)->pluck('empleado_id')->all();

        return response()->json([
            'fecha_entrega' => $fecha, 'hora_corte' => $calendario->horaDeCorte(),
            'regla_sin_eleccion' => Configuracion::find('regla_sin_eleccion')?->valor ?? 'ultimo_platillo',
            'ya_cerrado' => $calendario->corteYaEjecutado($fecha), 'empleados_activos' => count($activos),
            'sin_elegir' => count(array_diff($activos, $conEleccion)),
        ]);
    }

    /** El corte y lo que dejó para esa fecha: quién recibió platillo por defecto, órdenes por comedor, entregas por empresa y rutas. */
    private function resultado(Corte $corte, string $fecha, CalendarioDeServicio $calendario): array
    {
        $porDefecto = DB::table('elecciones_empleado as e')->join('empleados as emp', 'emp.id', '=', 'e.empleado_id')->join('users as u', 'u.id', '=', 'emp.usuario_id')
            ->join('empresas as ep', 'ep.id', '=', 'emp.empresa_id')->join('menus as m', 'm.id', '=', 'e.menu_id')
            ->whereDate('e.fecha', $fecha)->where('e.origen', 'por_defecto')->orderBy('u.name')
            ->get(['emp.id as empleado_id', 'u.name as nombre', 'ep.nombre as empresa_nombre', 'm.name as platillo_nombre'])->map(fn ($f) => (array) $f)->all();

        $conEleccion = DB::table('elecciones_empleado')->whereDate('fecha', $fecha)->pluck('empleado_id')->all();

        return [
            'corte' => Vistas::corte($corte), 'fecha_entrega' => $fecha, 'sin_cambios' => $corte->sin_cambios,
            'elecciones_cerradas' => $corte->elecciones_cerradas, 'empleados_por_defecto' => $porDefecto,
            'ordenes_generadas' => $corte->ordenes_generadas, 'entregas_consolidadas' => $corte->entregas_consolidadas, 'rutas_creadas' => $corte->rutas_creadas,
            'empleados_sin_entrega' => count(array_diff($this->empleadosActivosEseDia($fecha), $conEleccion)),
            'ordenes_por_comedor' => DB::table('ordenes_produccion as o')->join('providers as p', 'p.id', '=', 'o.comedor_id')->whereDate('o.fecha', $fecha)
                ->groupBy('o.comedor_id', 'p.business_name', 'p.commercial_name')->orderBy('o.comedor_id')
                ->selectRaw('o.comedor_id, coalesce(p.commercial_name, p.business_name) as comedor_nombre, count(*) as ordenes, sum(o.porciones_totales) as porciones')->get()
                ->map(fn ($f) => ['comedor_id' => $f->comedor_id, 'comedor_nombre' => $f->comedor_nombre, 'ordenes' => (int) $f->ordenes, 'porciones' => (int) $f->porciones])->all(),
            'entregas_por_empresa' => EntregaEmpresa::query()->with('empresa')->whereDate('fecha', $fecha)->orderBy('id')->get()->map(fn (EntregaEmpresa $e) => [
                'empresa_id' => $e->empresa_id, 'empresa_nombre' => $e->empresa->nombre, 'platillos' => $e->platillos,
                'hora_entrega' => Vistas::hora($calendario->contratoVigente($e->empresa_id, $fecha)?->hora_entrega ?? $e->ventana_inicio), 'ruta_id' => $e->ruta_id,
            ])->all(),
            'rutas' => Ruta::query()->with('repartidor.usuario')->whereDate('fecha', $fecha)->orderBy('id')->get()->map(fn (Ruta $r) => [
                'id' => $r->id, 'repartidor_nombre' => $r->repartidor->usuario->name, 'zona' => $r->zona, 'entregas' => $r->entregas_asignadas, 'platillos' => $r->platillos_asignados,
            ])->all(),
            'advertencias' => $corte->mensaje ? [$corte->mensaje] : [],
        ];
    }

    /** @return array<int, int> ids de los empleados activos de empresas activas con contrato vigente y sin cierre ese día */
    private function empleadosActivosEseDia(string $fecha): array
    {
        return DB::table('empleados as emp')->join('empresas as ep', 'ep.id', '=', 'emp.empresa_id')
            ->where('emp.activo', true)->where('ep.activo', true)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('contratos_empresa as c')->whereColumn('c.empresa_id', 'emp.empresa_id')->where('c.activo', true)
                ->whereDate('c.vigencia_inicio', '<=', $fecha)->whereDate('c.vigencia_fin', '>=', $fecha))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('dias_sin_servicio as d')->whereColumn('d.empresa_id', 'emp.empresa_id')->whereDate('d.fecha', $fecha))
            ->pluck('emp.id')->all();
    }

    private function primeraFechaSinCortar(CalendarioDeServicio $calendario)
    {
        $fecha = $calendario->ahora()->startOfDay()->addDay();
        for ($i = 0; $i < 60; $i++, $fecha = $fecha->addDay()) {
            if ($calendario->esDiaDeServicio($fecha) && ! $calendario->corteYaEjecutado($fecha)) {
                return $fecha;
            }
        }

        return $calendario->ahora()->startOfDay()->addDay();
    }
}
