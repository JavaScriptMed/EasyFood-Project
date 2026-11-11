<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Address;
use App\Models\DisponibilidadDia;
use App\Models\Menu;
use App\Models\Paquete;
use App\Models\Pedido;
use App\Models\PedidoDia;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pedidos de clientes individuales: paquetes de 1, 2 o 6 días de servicio.
 *
 * - Las fechas son los siguientes días de servicio consecutivos desde la primera con el corte abierto
 *   (un pedido hecho después de las 18:00 entra al siguiente ciclo).
 * - Cada día lleva su platillo; su precio se congela al pedir. total = suma de precios × (1 − descuento del paquete).
 * - El pago es simulado: no hay cobro real, solo se registra método y últimos 4 dígitos.
 * - La dirección debe tener cobertura (caer dentro de una zona activa).
 * - Un pedido por pagar vence en el corte de su primer día de entrega (`vence_pago_en`): si pasa sin pagarse
 *   se cancela, se liberan sus porciones y hay que pedirlo de nuevo.
 */
class ContratacionDePaquetes
{
    public function __construct(private readonly CalendarioDeServicio $calendario) {}

    /**
     * @param  array<int, int|Menu>  $platillos  un platillo por cada día del paquete, en orden
     */
    public function pedir(User $cliente, Paquete $paquete, Address $direccion, array $platillos, ?CarbonInterface $ahora = null): Pedido
    {
        // Se vuelve a leer: los valores por defecto de la base (activo) no vienen en un modelo recién creado.
        $paquete = Paquete::query()->findOrFail($paquete->id);
        if (! $paquete->activo) {
            throw new ReglaDeNegocioException('paquete_inactivo', 'Ese paquete ya no está disponible.');
        }
        $direccion = Address::query()->findOrFail($direccion->id); // trae la zona que calculó la base
        if ($direccion->user_id !== $cliente->id) {
            throw new ReglaDeNegocioException('direccion_ajena', 'La dirección de entrega no es del cliente.');
        }
        if ($direccion->sin_cobertura) {
            throw new ReglaDeNegocioException('sin_cobertura', 'Esa dirección está fuera de cobertura: elige otra o pide que se amplíe la zona.');
        }
        if (count($platillos) !== $paquete->dias) {
            throw new ReglaDeNegocioException('platillos_incompletos', "El paquete «{$paquete->nombre}» necesita {$paquete->dias} platillo(s): uno por día.");
        }

        $ahora = $this->calendario->ahora($ahora);

        return DB::transaction(function () use ($cliente, $paquete, $direccion, $platillos, $ahora) {
            $fechas = $this->calendario->fechasDeUnPaquete($paquete->dias, $ahora);
            $dias = [];
            $suma = 0;

            foreach ($fechas as $i => $fecha) {
                $menu = Menu::query()->find($platillos[$i] instanceof Menu ? $platillos[$i]->id : $platillos[$i]);
                if (! $menu || $menu->estado_revision !== 'publicado' || ! $menu->is_available) {
                    throw new ReglaDeNegocioException('platillo_no_disponible', "El platillo del día {$fecha->toDateString()} no está disponible.");
                }
                $disponibilidad = DisponibilidadDia::query()->where('menu_id', $menu->id)->whereDate('fecha', $fecha->toDateString())->lockForUpdate()->first();
                if (! $disponibilidad || $disponibilidad->porciones_tomadas >= $disponibilidad->porciones_maximas) {
                    throw new ReglaDeNegocioException('sin_porciones', "Ya no quedan porciones de «{$menu->name}» para el {$fecha->toDateString()}.");
                }
                $disponibilidad->increment('porciones_tomadas');

                $precio = Dinero::centavos($menu->price);
                $suma += $precio;
                $dias[] = ['fecha' => $fecha->toDateString(), 'menu_id' => $menu->id, 'precio_congelado' => Dinero::formato($precio)];
            }

            $descuento = (float) $paquete->descuento_porcentaje;
            $total = (int) round($suma * (100 - $descuento) / 100);

            $pedido = Pedido::create([
                'folio' => Folios::pedido($ahora),
                'vence_pago_en' => $this->calendario->momentoDelCorte($fechas[0]),
                'cliente_id' => $cliente->id, 'paquete_id' => $paquete->id, 'direccion_id' => $direccion->id,
                'fecha_inicio' => $fechas[0]->toDateString(), 'fecha_fin' => end($fechas)->toDateString(),
                'total' => Dinero::formato($total), 'estado' => 'pendiente_pago',
            ]);
            foreach ($dias as $dia) {
                PedidoDia::create($dia + ['pedido_id' => $pedido->id, 'estado' => 'programada']);
            }

            return $pedido->refresh();
        });
    }

    /** Pago simulado: no cobra nada. Un pedido pagado entra al corte; uno por pagar, no. */
    public function pagar(Pedido $pedido, string $metodoPago, ?string $ultimosDigitos = null, ?CarbonInterface $ahora = null): Pedido
    {
        $pedido = Pedido::query()->findOrFail($pedido->id);
        if ($pedido->estado !== 'pendiente_pago') {
            throw new ReglaDeNegocioException('pedido_no_pagable', 'Solo se puede pagar un pedido pendiente de pago.');
        }

        $primero = $pedido->dias()->orderBy('fecha')->first();
        if ($this->haVencido($pedido, $primero?->fecha, $ahora)) {
            $this->cancelar($pedido); // fuera de cualquier transacción propia: la cancelación queda guardada aunque se avise del error
            throw new ReglaDeNegocioException('pedido_vencido', 'El tiempo para pagar terminó (el corte de la primera entrega ya pasó): el pedido se canceló y hay que pedirlo de nuevo.');
        }

        $pedido->update([
            'estado' => 'recibido', 'metodo_pago' => $metodoPago, 'ultimos_digitos' => $ultimosDigitos,
            'pagado_en' => $this->calendario->ahora($ahora),
        ]);

        return $pedido->refresh();
    }

    /**
     * Cancela los pedidos por pagar cuyo plazo terminó (llegó su `vence_pago_en`, o el corte de su primer día ya se ejecutó)
     * y libera sus porciones. Es seguro repetirlo. Con $hastaFechaDeEntrega también cancela los que empiezan en esa fecha o antes
     * (lo usa el corte: al cerrarse el día, esos pedidos ya no se pueden pagar).
     *
     * @return int cuántos pedidos canceló
     */
    public function cancelarVencidos(?CarbonInterface $ahora = null, ?string $hastaFechaDeEntrega = null): int
    {
        $ahora = $this->calendario->ahora($ahora);

        /** @var Collection<int, Pedido> $vencidos */
        $vencidos = Pedido::query()->where('estado', 'pendiente_pago')
            ->where(fn ($q) => $q->where('vence_pago_en', '<=', $ahora)
                // El corte de esa fecha se está ejecutando ahora mismo: ya no se podrán pagar.
                ->when($hastaFechaDeEntrega, fn ($q) => $q->orWhereDate('fecha_inicio', '<=', $hastaFechaDeEntrega))
                ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))->from('cortes')
                    ->whereColumn('cortes.fecha_entrega', 'pedidos.fecha_inicio')->where('cortes.sin_cambios', false)->where('cortes.estado', '<>', 'fallido')))
            ->get();

        foreach ($vencidos as $pedido) {
            $this->cancelar($pedido);
        }

        return $vencidos->count();
    }

    /** Cuenta como vencido si llegó su plazo o si el corte de su primer día ya se ejecutó (aunque haya sido antes de tiempo). */
    private function haVencido(Pedido $pedido, ?CarbonInterface $primerDia, ?CarbonInterface $ahora): bool
    {
        $ahora = $this->calendario->ahora($ahora);

        return ($pedido->vence_pago_en && $ahora->gte($pedido->vence_pago_en))
            || ($primerDia && $this->calendario->corteYaEjecutado($primerDia));
    }

    /** Pedido por pagar → cancelado; sus días → cancelados y se devuelven las porciones que tenían apartadas. */
    private function cancelar(Pedido $pedido): void
    {
        DB::transaction(function () use ($pedido) {
            $dias = PedidoDia::query()->where('pedido_id', $pedido->id)->where('estado', 'programada')->lockForUpdate()->get();
            foreach ($dias as $dia) {
                DisponibilidadDia::query()->where('menu_id', $dia->menu_id)->whereDate('fecha', $dia->fecha->toDateString())
                    ->where('porciones_tomadas', '>', 0)->decrement('porciones_tomadas');
                $dia->update(['estado' => 'cancelada']);
            }
            $pedido->update(['estado' => 'cancelado']);
        });
    }
}
