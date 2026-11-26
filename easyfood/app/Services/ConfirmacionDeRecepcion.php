<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Empleado;
use App\Models\EleccionEmpleado;
use App\Models\EntregaEmpresa;
use App\Models\Pedido;
use App\Models\PedidoDia;
use App\Models\Recepcion;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * El botón «Recibido»: quien recibe la comida confirma que le llegó. Solo se confirma lo que el repartidor ya marcó como entregado;
 * confirmar dos veces no hace nada nuevo (devuelve la primera confirmación). No cambia estados de entregas ni montos, y no es requisito
 * para calificar.
 */
class ConfirmacionDeRecepcion
{
    private const SIN_ENTREGA = 'Todavía no hay una entrega que confirmar: espera a que el repartidor la marque como entregada.';

    /** El cliente individual confirma que recibió la entrega de su pedido. */
    public function comoCliente(User $usuario, Pedido $pedido): CarbonInterface
    {
        return DB::transaction(function () use ($usuario, $pedido) {
            $dias = PedidoDia::query()->where('pedido_id', $pedido->id)->where('estado', 'entregada')->orderBy('id')->lockForUpdate()->get();
            if ($dias->isEmpty()) {
                throw new ReglaDeNegocioException('sin_entrega_que_confirmar', self::SIN_ENTREGA);
            }
            $recepciones = $dias->map(fn (PedidoDia $dia) => $this->registrar($usuario, ['pedido_dia_id' => $dia->id], "del pedido {$pedido->folio}"));

            return $recepciones->min('confirmada_en');
        });
    }

    /** El empleado confirma que recibió la comida que su empresa recibió ese día (y que él pidió). */
    public function comoEmpleado(User $usuario, Empleado $empleado, string $fecha): CarbonInterface
    {
        return DB::transaction(function () use ($usuario, $empleado, $fecha) {
            $entrega = EntregaEmpresa::query()->where('empresa_id', $empleado->empresa_id)->whereDate('fecha', $fecha)->where('estado', 'entregada')->lockForUpdate()->first();
            $pidio = EleccionEmpleado::query()->where('empleado_id', $empleado->id)->whereDate('fecha', $fecha)->exists();
            if (! $entrega || ! $pidio) {
                throw new ReglaDeNegocioException('sin_entrega_que_confirmar', self::SIN_ENTREGA);
            }

            return $this->registrar($usuario, ['entrega_empresa_id' => $entrega->id], "del {$fecha}")->confirmada_en;
        });
    }

    /** @param  array<string, int>  $entrega */
    private function registrar(User $usuario, array $entrega, string $cual): Recepcion
    {
        $recepcion = Recepcion::query()->firstOrCreate(['usuario_id' => $usuario->id] + $entrega, ['confirmada_en' => now()]);
        if ($recepcion->wasRecentlyCreated) {
            Bitacora::registrar($usuario, 'entrega.recibida', 'recepciones', $recepcion->id, "{$usuario->name} confirmó que recibió la entrega {$cual}.");
        }

        return $recepcion;
    }
}
