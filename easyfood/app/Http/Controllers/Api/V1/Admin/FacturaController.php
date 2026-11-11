<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ReglaDeNegocioException;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\Vistas;
use App\Models\Empresa;
use App\Models\FacturaMensual;
use App\Services\Bitacora;
use App\Services\CalendarioDeServicio;
use App\Services\GeneradorDeFacturas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FacturaController extends Controller
{
    public function __construct(private readonly GeneradorDeFacturas $facturas) {}

    public function index(Request $request): JsonResponse
    {
        $mes = $request->validate(['mes' => 'required|date_format:Y-m'])['mes'];

        $facturas = FacturaMensual::query()->whereDate('mes', "{$mes}-01")->orderBy('folio')->get();
        $empresas = Empresa::query()->with(['usuario.profile', 'usuario.role', 'contratos'])
            ->withCount(['empleados as empleados_activos_count' => fn ($q) => $q->where('activo', true)])->whereIn('id', $facturas->pluck('empresa_id'))->get()->keyBy('id');

        return response()->json($facturas->map(fn ($f) => Vistas::factura($f) + ['empresa' => Vistas::empresaDetalle($empresas[$f->empresa_id])])->values());
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(self::detalle(FacturaMensual::query()->findOrFail($id)));
    }

    /** Genera (o recalcula, si siguen en borrador) las facturas del mes de todas las empresas con contrato en ese mes. */
    public function generar(Request $request): JsonResponse
    {
        $mes = $request->validate(['mes' => 'required|date_format:Y-m'])['mes'];
        if (! app(CalendarioDeServicio::class)->mesTerminado("{$mes}-01")) {
            throw new ReglaDeNegocioException('mes_sin_terminar', 'Solo se genera la facturación de un mes que ya terminó.');
        }

        $generadas = [];
        foreach (Empresa::query()->activos()->orderBy('id')->get() as $empresa) {
            try {
                $generadas[] = $this->facturas->generar($empresa, "{$mes}-01");
            } catch (ReglaDeNegocioException $e) {
                if ($e->codigo !== 'sin_contrato') {
                    throw $e; // p. ej. «el mes no ha terminado»: aplica a todas
                }
            }
        }
        Bitacora::registrar($request->user(), 'facturacion.generada', 'facturas_mensuales', $mes, 'Generó la facturación de '.$mes.': '.count($generadas).' factura(s).');

        return response()->json(collect($generadas)->map(fn ($f) => Vistas::factura($f))->values());
    }

    public function emitir(Request $request, int $id): JsonResponse
    {
        $factura = $this->facturas->emitir(FacturaMensual::query()->findOrFail($id));
        Bitacora::registrar($request->user(), 'factura.emitida', 'facturas_mensuales', $factura->id, "Emitió la factura {$factura->folio}.");

        return response()->json(Vistas::factura($factura));
    }

    public function pagar(Request $request, int $id): JsonResponse
    {
        $factura = $this->facturas->marcarPagada(FacturaMensual::query()->findOrFail($id));
        Bitacora::registrar($request->user(), 'factura.pagada', 'facturas_mensuales', $factura->id, "Marcó como pagada la factura {$factura->folio}.");

        return response()->json(Vistas::factura($factura));
    }

    /** La factura con el consumo de cada empleado (platillos entregados, importe y partes). Lo usan también las empresas. */
    public static function detalle(FacturaMensual $factura): array
    {
        $inicio = $factura->mes->toDateString();
        $lineas = DB::select(<<<'SQL'
            select emp.id as empleado_id, u.name as nombre, u.email as correo, count(*) as platillos,
                   sum(e.precio_congelado) as importe, sum(round(e.precio_congelado * e.porcentaje_subsidio_congelado / 100, 2)) as parte_empresa
            from elecciones_empleado e
            join empleados emp on emp.id = e.empleado_id
            join users u on u.id = emp.usuario_id
            join entregas_empresa en on en.empresa_id = emp.empresa_id and en.fecha = e.fecha and en.estado = 'entregada'
            where emp.empresa_id = ? and e.fecha >= ? and e.fecha < (?::date + interval '1 month')
            group by emp.id, u.name, u.email order by u.name
        SQL, [$factura->empresa_id, $inicio, $inicio]);

        return Vistas::factura($factura) + [
            'importe_consumo' => Vistas::dinero($factura->parte_empresa + $factura->parte_empleados),
            'lineas' => array_map(fn ($l) => [
                'empleado_id' => $l->empleado_id, 'nombre' => $l->nombre, 'correo' => $l->correo, 'platillos' => (int) $l->platillos,
                'importe' => Vistas::dinero($l->importe), 'parte_empresa' => Vistas::dinero($l->parte_empresa),
                'parte_empleado' => Vistas::dinero($l->importe - $l->parte_empresa),
            ], $lineas),
        ];
    }
}
