<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\ContratoEmpresa;
use App\Models\Empresa;
use App\Models\FacturaMensual;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Factura mensual de una empresa (una por empresa y mes).
 *
 *   consumo           = platillos entregados × precio congelado de cada elección
 *   parte de empresa  = por cada platillo, precio congelado × subsidio congelado %
 *   parte empleados   = consumo − parte de la empresa
 *   diferencia mínimo = (compra mínima del contrato − platillos entregados) × precio por platillo del contrato, si es positiva
 *   total             = parte empresa + parte empleados + diferencia por mínimo
 *
 * «Entregado» = la entrega de la empresa ese día quedó en estado «entregada». Mientras la factura es borrador se puede
 * volver a generar (recalcula); emitida o pagada ya no cambia.
 */
class GeneradorDeFacturas
{
    public function __construct(private readonly CalendarioDeServicio $calendario) {}

    public function generar(Empresa|int $empresa, CarbonInterface|string $mes, ?CarbonInterface $ahora = null): FacturaMensual
    {
        $empresaId = $empresa instanceof Empresa ? $empresa->id : $empresa;
        $inicio = $this->calendario->dia($mes)->startOfMonth();
        $siguiente = $inicio->addMonth();

        if ($this->calendario->ahora($ahora)->lt($siguiente)) {
            throw new ReglaDeNegocioException('mes_sin_terminar', 'La factura de un mes solo se genera cuando el mes ya terminó.');
        }

        return DB::transaction(function () use ($empresaId, $inicio, $siguiente) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["factura:{$empresaId}:{$inicio->toDateString()}"]);

            $existente = FacturaMensual::query()->where('empresa_id', $empresaId)->whereDate('mes', $inicio->toDateString())->first();
            if ($existente && $existente->estado !== 'borrador') {
                return $existente;
            }

            $contrato = $this->contratoDelMes($empresaId, $inicio, $siguiente->subDay());
            $consumo = DB::selectOne(<<<'SQL'
                select count(*) as platillos,
                       coalesce(sum(round(e.precio_congelado * e.porcentaje_subsidio_congelado / 100, 2)), 0) as parte_empresa,
                       coalesce(sum(e.precio_congelado), 0) as consumo
                from elecciones_empleado e
                join empleados emp on emp.id = e.empleado_id
                join entregas_empresa en on en.empresa_id = emp.empresa_id and en.fecha = e.fecha and en.estado = 'entregada'
                where emp.empresa_id = ? and e.fecha >= ? and e.fecha < ?
            SQL, [$empresaId, $inicio->toDateString(), $siguiente->toDateString()]);

            $platillos = (int) $consumo->platillos;
            $parteEmpresa = Dinero::centavos($consumo->parte_empresa);
            $parteEmpleados = Dinero::centavos($consumo->consumo) - $parteEmpresa;
            $faltantes = max(0, $contrato->compra_minima_mensual - $platillos);
            $diferencia = $faltantes * Dinero::centavos($contrato->precio_por_platillo);

            $datos = [
                'platillos' => $platillos,
                'parte_empresa' => Dinero::formato($parteEmpresa),
                'parte_empleados' => Dinero::formato($parteEmpleados),
                'platillos_faltantes' => $faltantes,
                'diferencia_minimo' => Dinero::formato($diferencia),
                'total' => Dinero::formato($parteEmpresa + $parteEmpleados + $diferencia),
            ];

            if ($existente) {
                $existente->update($datos);

                return $existente->refresh();
            }

            return FacturaMensual::create($datos + [
                'folio' => sprintf('EL-%s-%03d', $inicio->format('Ym'), $empresaId),
                'empresa_id' => $empresaId, 'mes' => $inicio->toDateString(), 'estado' => 'borrador',
            ])->refresh();
        });
    }

    public function emitir(FacturaMensual $factura, ?CarbonInterface $ahora = null): FacturaMensual
    {
        if ($factura->estado !== 'borrador') {
            throw new ReglaDeNegocioException('factura_ya_emitida', "La factura {$factura->folio} ya fue emitida.");
        }
        $factura->update(['estado' => 'emitida', 'emitida_en' => $this->calendario->ahora($ahora)]);

        return $factura->refresh();
    }

    public function marcarPagada(FacturaMensual $factura, ?CarbonInterface $ahora = null): FacturaMensual
    {
        if ($factura->estado !== 'emitida') {
            throw new ReglaDeNegocioException('factura_no_emitida', "Solo se puede pagar una factura emitida ({$factura->folio} está {$factura->estado}).");
        }
        $factura->update(['estado' => 'pagada', 'pagada_en' => $this->calendario->ahora($ahora)]);

        return $factura->refresh();
    }

    /** El contrato que cubre el mes (si hubo dos, el más reciente). No exige que siga activo: un contrato vencido factura sus meses. */
    private function contratoDelMes(int $empresaId, CarbonImmutable $inicio, CarbonImmutable $fin): ContratoEmpresa
    {
        return ContratoEmpresa::query()->where('empresa_id', $empresaId)
            ->whereDate('vigencia_inicio', '<=', $fin->toDateString())->whereDate('vigencia_fin', '>=', $inicio->toDateString())
            ->orderByDesc('vigencia_inicio')->first()
            ?? throw new ReglaDeNegocioException('sin_contrato', 'La empresa no tuvo un contrato que cubra ese mes.');
    }
}
