<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Address;
use App\Models\DisponibilidadDia;
use App\Models\Menu;
use App\Models\Paquete;
use App\Models\Pedido;
use App\Models\PedidoDia;
use App\Models\PedidoDiaPlatillo;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pedidos de clientes individuales: paquetes de 1, 2 o 6 días de servicio.
 *
 * - Las fechas son los siguientes días de servicio consecutivos desde la primera con el corte abierto
 *   (un pedido hecho después de las 18:00 entra al siguiente ciclo).
 * - El cliente elige cuántos días (paquete de 1, 2 o 6) y cuántas comidas por día (1, 2 o 3: desayuno, comida y/o cena).
 *   Cada día lleva un platillo por cada tiempo de comida elegido, y son los mismos tiempos todos los días.
 * - El precio de cada platillo se congela al pedir. total = suma de precios × (1 − descuento del paquete).
 * - Extra opcional: un platillo más por día, de cualquier tiempo de comida, en todos los días del pedido. Se cobra con el
 *   mismo descuento del paquete y se aparta como cualquier otro platillo.
 * - Una entrega por día: todas las comidas de ese día viajan juntas (una parada, un estado, una evidencia).
 * - El pago es simulado: no hay cobro real, solo se registra método y últimos 4 dígitos.
 * - La dirección debe tener cobertura (caer dentro de una zona activa).
 * - Un pedido por pagar vence en el corte de su primer día de entrega (`vence_pago_en`): si pasa sin pagarse
 *   se cancela, se liberan sus porciones y hay que pedirlo de nuevo.
 */
class ContratacionDePaquetes
{
    public function __construct(private readonly CalendarioDeServicio $calendario) {}

    /**
     * @param  array<int, int|Menu|array<string, int|Menu>>  $platillos  un elemento por cada día del paquete, en orden:
     *                                                                   un platillo suelto (una comida, del tiempo de comida del propio platillo)
     *                                                                   o un arreglo `tiempo_comida => platillo` (hasta tres), con la llave
     *                                                                   opcional `extra => platillo` (el mismo número de extras todos los días)
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
            throw new ReglaDeNegocioException('platillos_incompletos', "El paquete «{$paquete->nombre}» es de {$paquete->dias} día(s): elige los platillos de cada día.");
        }
        $porDia = $this->platillosPorDia(array_values($platillos));
        $tiempos = array_keys($porDia[0]['base']);
        $conExtra = $porDia[0]['extra'] !== null;

        $ahora = $this->calendario->ahora($ahora);

        return DB::transaction(function () use ($cliente, $paquete, $direccion, $porDia, $tiempos, $conExtra, $ahora) {
            $fechas = $this->calendario->fechasDeUnPaquete($paquete->dias, $ahora);
            $dias = [];
            $suma = 0;

            foreach ($fechas as $i => $fecha) {
                $comidas = [];
                $lineas = [];
                foreach ($porDia[$i]['base'] as $tiempo => $menu) {
                    $lineas[] = [$tiempo, $menu, false];
                }
                if ($porDia[$i]['extra'] !== null) {
                    $lineas[] = [$porDia[$i]['extra']->tiempo_comida, $porDia[$i]['extra'], true];
                }
                foreach ($lineas as [$tiempo, $menu, $esExtra]) {
                    if ($menu->estado_revision !== 'publicado' || ! $menu->is_available) {
                        throw new ReglaDeNegocioException('platillo_no_disponible', "El platillo de {$tiempo} del día {$fecha->toDateString()} no está disponible.");
                    }
                    $disponibilidad = DisponibilidadDia::query()->where('menu_id', $menu->id)->whereDate('fecha', $fecha->toDateString())->lockForUpdate()->first();
                    if (! $disponibilidad || $disponibilidad->porciones_tomadas >= $disponibilidad->porciones_maximas) {
                        throw new ReglaDeNegocioException('sin_porciones', "Ya no quedan porciones de «{$menu->name}» para el {$fecha->toDateString()}.");
                    }
                    $disponibilidad->increment('porciones_tomadas');

                    $precio = Dinero::centavos($menu->price);
                    $suma += $precio;
                    $comidas[] = ['tiempo_comida' => $tiempo, 'menu_id' => $menu->id, 'precio_congelado' => Dinero::formato($precio), 'es_extra' => $esExtra];
                }
                $dias[] = ['fecha' => $fecha->toDateString(), 'comidas' => $comidas];
            }

            $descuento = (float) $paquete->descuento_porcentaje;
            $total = (int) round($suma * (100 - $descuento) / 100);

            $pedido = Pedido::create([
                'folio' => Folios::pedido($ahora),
                'vence_pago_en' => $this->calendario->momentoDelCorte($fechas[0]),
                'cliente_id' => $cliente->id, 'paquete_id' => $paquete->id, 'direccion_id' => $direccion->id, 'comidas_por_dia' => count($tiempos), 'con_extra' => $conExtra,
                'fecha_inicio' => $fechas[0]->toDateString(), 'fecha_fin' => end($fechas)->toDateString(),
                'total' => Dinero::formato($total), 'estado' => 'pendiente_pago',
            ]);
            foreach ($dias as $dia) {
                $entrega = PedidoDia::create(['pedido_id' => $pedido->id, 'fecha' => $dia['fecha'], 'estado' => 'programada']);
                foreach ($dia['comidas'] as $comida) {
                    PedidoDiaPlatillo::create($comida + ['pedido_dia_id' => $entrega->id]);
                }
            }

            return $pedido->refresh();
        });
    }

    /**
     * Ordena lo que mandó el cliente: para cada día, las comidas base (`tiempo_comida => Menu`) y el extra (si lo pidió).
     * Todos los días llevan los mismos tiempos de comida y el mismo número de extras; cada platillo tiene que ser del tiempo
     * en el que se pone.
     *
     * @param  array<int, int|Menu|array<string, int|Menu>>  $dias
     * @return array<int, array{base: array<string, Menu>, extra: ?Menu}>
     */
    private function platillosPorDia(array $dias): array
    {
        $orden = ['desayuno' => 0, 'comida' => 1, 'cena' => 2];
        $resultado = [];
        $buscar = function ($platillo): Menu {
            $menu = $platillo instanceof Menu ? $platillo : Menu::query()->find($platillo);
            if (! $menu) {
                throw new ReglaDeNegocioException('platillo_no_disponible', 'Uno de los platillos elegidos no existe.');
            }

            return $menu;
        };

        foreach ($dias as $i => $dia) {
            $extra = null;
            $comidas = [];
            foreach (is_array($dia) ? $dia : [null => $dia] as $tiempo => $platillo) {
                if ($tiempo === 'extra') {
                    $extra = $platillo === null ? null : $buscar($platillo);

                    continue;
                }
                $menu = $buscar($platillo);
                $tiempo = $tiempo ?: $menu->tiempo_comida;
                if (! isset($orden[$tiempo])) {
                    throw new ReglaDeNegocioException('tiempo_de_comida_invalido', 'Las comidas del día son desayuno, comida y cena.');
                }
                if ($menu->tiempo_comida !== $tiempo) {
                    throw new ReglaDeNegocioException('platillo_de_otro_tiempo', "«{$menu->name}» no es de {$tiempo}: elige un platillo de ese tiempo de comida.");
                }
                $comidas[$tiempo] = $menu;
            }
            if ($comidas === [] || count($comidas) > 3) {
                throw new ReglaDeNegocioException('comidas_por_dia_invalidas', 'Cada día lleva de 1 a 3 comidas (desayuno, comida y/o cena).');
            }
            uksort($comidas, fn ($a, $b) => $orden[$a] <=> $orden[$b]);
            $resultado[$i] = ['base' => $comidas, 'extra' => $extra];

            if (array_keys($comidas) !== array_keys($resultado[0]['base'])) {
                throw new ReglaDeNegocioException('comidas_distintas_por_dia', 'Todos los días del paquete llevan las mismas comidas (por ejemplo, desayuno y comida).');
            }
            if (($extra === null) !== ($resultado[0]['extra'] === null)) {
                throw new ReglaDeNegocioException('extra_distinto_por_dia', 'El extra se pide para todos los días del paquete o para ninguno.');
            }
        }

        return $resultado;
    }

    /**
     * Pago simulado: no cobra nada. Método interno (lo usan el seeder y las pruebas); no tiene ruta pública, porque el pago de verdad
     * lo confirma el webhook de Stripe (PagosDePedidos). Un pedido pagado entra al corte; uno por pagar, no.
     */
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

        return $this->confirmarPago($pedido, $metodoPago, $ultimosDigitos, $this->calendario->ahora($ahora));
    }

    /** Pasa un pedido por pagar a «recibido» con su fecha de pago. Único lugar donde se marca un pago (simulado o de Stripe). */
    public function confirmarPago(Pedido $pedido, string $metodoPago, ?string $ultimosDigitos, CarbonInterface $pagadoEn): Pedido
    {
        $pedido->update(['estado' => 'recibido', 'metodo_pago' => $metodoPago, 'ultimos_digitos' => $ultimosDigitos, 'pagado_en' => $pagadoEn]);

        return $pedido->refresh();
    }

    /** ¿Ya no se puede empezar a pagar? (llegó su plazo o el corte de su primer día ya se ejecutó). No cancela nada. */
    public function yaNoSePuedePagar(Pedido $pedido, ?CarbonInterface $ahora = null): bool
    {
        return $this->haVencido($pedido, $pedido->dias()->orderBy('fecha')->first()?->fecha, $ahora);
    }

    /** ¿El pago hecho en `$momento` llegó después del plazo del pedido? Compara contra la hora del cargo, no contra la de hoy. */
    public function pagoLlegoTarde(Pedido $pedido, CarbonInterface $momento): bool
    {
        return $pedido->vence_pago_en !== null && $momento->gte($pedido->vence_pago_en);
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
            $dias = PedidoDia::query()->with('platillos')->where('pedido_id', $pedido->id)->where('estado', 'programada')->lockForUpdate()->get();
            foreach ($dias as $dia) {
                foreach ($dia->platillos as $comida) {
                    DisponibilidadDia::query()->where('menu_id', $comida->menu_id)->whereDate('fecha', $dia->fecha->toDateString())
                        ->where('porciones_tomadas', '>', 0)->decrement('porciones_tomadas');
                }
                $dia->update(['estado' => 'cancelada']);
            }
            $pedido->update(['estado' => 'cancelado']);
        });
    }
}
