<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\ContratoComedor;
use App\Models\LiquidacionComedor;
use App\Models\Provider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Liquidación mensual a un comedor (una por comedor y mes).
 *
 *   entregados = platillos de sus menús entregados a empresas + a clientes individuales en el mes
 *   faltantes  = max(0, compra mínima del contrato − entregados)
 *   diferencia = faltantes × precio por platillo   (si EasyLunch compró menos del mínimo, paga la diferencia)
 *   monto      = (entregados + faltantes) × precio por platillo
 *
 * Pendiente se puede regenerar; pagada ya no cambia.
 */
class GeneradorDeLiquidaciones
{
    public function __construct(private readonly CalendarioDeServicio $calendario) {}

    public function generar(Provider|int $comedor, CarbonInterface|string $mes, ?CarbonInterface $ahora = null): LiquidacionComedor
    {
        $comedorId = $comedor instanceof Provider ? $comedor->id : $comedor;
        $inicio = $this->calendario->dia($mes)->startOfMonth();
        $siguiente = $inicio->addMonth();

        if ($this->calendario->ahora($ahora)->lt($siguiente)) {
            throw new ReglaDeNegocioException('mes_sin_terminar', 'La liquidación de un mes solo se genera cuando el mes ya terminó.');
        }

        return DB::transaction(function () use ($comedorId, $inicio, $siguiente) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["liquidacion:{$comedorId}:{$inicio->toDateString()}"]);

            $existente = LiquidacionComedor::query()->where('comedor_id', $comedorId)->whereDate('mes', $inicio->toDateString())->first();
            if ($existente && $existente->estado === 'pagado') {
                return $existente;
            }

            $contrato = $this->contratoDelMes($comedorId, $inicio, $siguiente->subDay());
            $entregados = $this->platillosEntregados($comedorId, $inicio, $siguiente);
            $faltantes = max(0, $contrato->compra_minima_mensual - $entregados);
            $precio = Dinero::centavos($contrato->precio_por_platillo);

            $datos = [
                'platillos_entregados' => $entregados,
                'precio_por_platillo' => Dinero::formato($precio),
                'platillos_minimos' => $contrato->compra_minima_mensual,
                'platillos_faltantes' => $faltantes,
                'diferencia_minimo' => Dinero::formato($faltantes * $precio),
                'monto' => Dinero::formato(($entregados + $faltantes) * $precio),
            ];

            if ($existente) {
                $existente->update($datos);

                return $existente->refresh();
            }

            return LiquidacionComedor::create($datos + ['comedor_id' => $comedorId, 'mes' => $inicio->toDateString(), 'estado' => 'pendiente'])->refresh();
        });
    }

    public function marcarPagada(LiquidacionComedor $liquidacion, ?CarbonInterface $ahora = null): LiquidacionComedor
    {
        if ($liquidacion->estado === 'pagado') {
            throw new ReglaDeNegocioException('liquidacion_ya_pagada', 'Esa liquidación ya está pagada.');
        }
        $liquidacion->update(['estado' => 'pagado', 'pagada_en' => $this->calendario->ahora($ahora)]);

        return $liquidacion->refresh();
    }

    private function platillosEntregados(int $comedorId, CarbonImmutable $inicio, CarbonImmutable $siguiente): int
    {
        $fila = DB::selectOne(<<<'SQL'
            select
              (select count(*) from elecciones_empleado e
                 join menus m on m.id = e.menu_id
                 join empleados emp on emp.id = e.empleado_id
                 join entregas_empresa en on en.empresa_id = emp.empresa_id and en.fecha = e.fecha and en.estado = 'entregada'
                where m.provider_id = ? and e.fecha >= ? and e.fecha < ?)
              +
              (select count(*) from pedido_dias d join menus m on m.id = d.menu_id
                where m.provider_id = ? and d.estado = 'entregada' and d.fecha >= ? and d.fecha < ?) as total
        SQL, [$comedorId, $inicio->toDateString(), $siguiente->toDateString(), $comedorId, $inicio->toDateString(), $siguiente->toDateString()]);

        return (int) $fila->total;
    }

    private function contratoDelMes(int $comedorId, CarbonImmutable $inicio, CarbonImmutable $fin): ContratoComedor
    {
        return ContratoComedor::query()->where('comedor_id', $comedorId)
            ->whereDate('vigencia_inicio', '<=', $fin->toDateString())->whereDate('vigencia_fin', '>=', $inicio->toDateString())
            ->orderByDesc('vigencia_inicio')->first()
            ?? throw new ReglaDeNegocioException('sin_contrato', 'El comedor no tuvo un contrato que cubra ese mes.');
    }
}
