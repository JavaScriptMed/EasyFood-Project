<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\CalificacionEntrega;
use App\Models\CalificacionPlatillo;
use App\Models\EleccionEmpleado;
use App\Models\Empleado;
use App\Models\EntregaEmpresa;
use App\Models\Pedido;
use App\Models\PedidoDia;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Calificaciones de lo ya entregado, de 1 a 5 estrellas.
 *
 * - De la entrega (última milla): puntualidad, trato del chofer y estado del empaque. La hace un empleado sobre la entrega de su
 *   empresa, o un cliente sobre un día de su pedido.
 * - Del platillo: sabor y calidad. La hace quien lo recibió.
 * Solo se califica lo que ya se entregó (también lo exige la base), una vez por persona, y dentro de los 14 días siguientes.
 */
class Calificaciones
{
    public const DIAS_PARA_CALIFICAR = 14;

    public function __construct(private readonly CalendarioDeServicio $calendario) {}

    /**
     * Lo que un empleado ya recibió y todavía no calificó (últimos 14 días): por día, la entrega y cada platillo.
     *
     * @return array<int, array{fecha: string, entrega_calificada: bool, platillos: array<int, array{tiempo_comida: string, platillo_id: int, platillo_nombre: string, calificado: bool}>}>
     */
    public function pendientesDelEmpleado(Empleado $empleado, ?CarbonInterface $ahora = null): array
    {
        $hoy = $this->calendario->ahora($ahora)->startOfDay();
        $desde = $hoy->subDays(self::DIAS_PARA_CALIFICAR)->toDateString();

        $entregadas = EntregaEmpresa::query()->where('empresa_id', $empleado->empresa_id)->where('estado', 'entregada')
            ->whereDate('fecha', '>=', $desde)->whereDate('fecha', '<=', $hoy->toDateString())->orderByDesc('fecha')->get()->keyBy(fn ($e) => $e->fecha->toDateString());
        if ($entregadas->isEmpty()) {
            return [];
        }

        $califEntrega = CalificacionEntrega::query()->where('calificador_id', $empleado->usuario_id)->whereIn('entrega_empresa_id', $entregadas->pluck('id'))->pluck('entrega_empresa_id')->all();
        $califPlatillos = CalificacionPlatillo::query()->where('calificador_id', $empleado->usuario_id)->whereDate('fecha', '>=', $desde)->get()
            ->map(fn ($c) => $c->menu_id.'|'.$c->fecha->toDateString())->all();

        $dias = [];
        $elecciones = EleccionEmpleado::query()->with('menu')->where('empleado_id', $empleado->id)->whereIn('fecha', $entregadas->keys())->orderBy('fecha')->get()->groupBy(fn ($e) => $e->fecha->toDateString());
        foreach ($entregadas as $fecha => $entrega) {
            if (! $elecciones->has($fecha)) {
                continue; // ese día este empleado no pidió: no recibió nada
            }
            $dias[] = [
                'fecha' => $fecha, 'entrega_calificada' => in_array($entrega->id, $califEntrega, true),
                'platillos' => $elecciones[$fecha]->map(fn ($e) => [
                    'tiempo_comida' => $e->tiempo_comida, 'platillo_id' => $e->menu_id, 'platillo_nombre' => $e->menu->name, 'calificado' => in_array($e->menu_id.'|'.$fecha, $califPlatillos, true),
                ])->values()->all(),
            ];
        }

        return array_values(array_filter($dias, fn ($d) => ! $d['entrega_calificada'] || collect($d['platillos'])->contains('calificado', false)));
    }

    /** Un empleado califica la entrega que recibió su empresa ese día (y en la que él pidió). */
    public function calificarEntregaComoEmpleado(User $usuario, Empleado $empleado, string $fecha, array $estrellas, ?string $comentario = null, ?CarbonInterface $ahora = null): CalificacionEntrega
    {
        $this->dentroDelPlazo($fecha, $ahora);
        $entrega = EntregaEmpresa::query()->where('empresa_id', $empleado->empresa_id)->whereDate('fecha', $fecha)->first();
        $pidio = EleccionEmpleado::query()->where('empleado_id', $empleado->id)->whereDate('fecha', $fecha)->exists();
        if (! $entrega || ! $pidio || $entrega->estado !== 'entregada') {
            throw new ReglaDeNegocioException('sin_entrega_que_calificar', 'Solo se califica una entrega que ya recibiste.');
        }
        if (CalificacionEntrega::query()->where('calificador_id', $usuario->id)->where('entrega_empresa_id', $entrega->id)->exists()) {
            throw new ReglaDeNegocioException('ya_calificada', 'Ya calificaste esta entrega.');
        }

        return CalificacionEntrega::create(['calificador_id' => $usuario->id, 'entrega_empresa_id' => $entrega->id, 'comentario' => $comentario] + $this->estrellasDeEntrega($estrellas));
    }

    /** Un empleado califica el sabor y la calidad del platillo que recibió en ese tiempo de comida. */
    public function calificarPlatilloComoEmpleado(User $usuario, Empleado $empleado, string $fecha, string $tiempoComida, int $estrellas, ?string $comentario = null, ?CarbonInterface $ahora = null): CalificacionPlatillo
    {
        $this->dentroDelPlazo($fecha, $ahora);
        $eleccion = EleccionEmpleado::query()->where('empleado_id', $empleado->id)->whereDate('fecha', $fecha)->where('tiempo_comida', $tiempoComida)->first();
        $entregada = EntregaEmpresa::query()->where('empresa_id', $empleado->empresa_id)->whereDate('fecha', $fecha)->where('estado', 'entregada')->exists();
        if (! $eleccion || ! $entregada) {
            throw new ReglaDeNegocioException('sin_platillo_que_calificar', 'Solo se califica un platillo que ya recibiste.');
        }

        return $this->guardarPlatillo($usuario, $eleccion->menu_id, $fecha, $estrellas, $comentario);
    }

    /** Un cliente califica la entrega de un día de su pedido. */
    public function calificarEntregaComoCliente(User $usuario, Pedido $pedido, string $fecha, array $estrellas, ?string $comentario = null, ?CarbonInterface $ahora = null): CalificacionEntrega
    {
        $this->dentroDelPlazo($fecha, $ahora);
        $dia = $this->diaEntregado($usuario, $pedido, $fecha);
        if (CalificacionEntrega::query()->where('calificador_id', $usuario->id)->where('pedido_dia_id', $dia->id)->exists()) {
            throw new ReglaDeNegocioException('ya_calificada', 'Ya calificaste esta entrega.');
        }

        return CalificacionEntrega::create(['calificador_id' => $usuario->id, 'pedido_dia_id' => $dia->id, 'comentario' => $comentario] + $this->estrellasDeEntrega($estrellas));
    }

    /** Un cliente califica un platillo de un día entregado de su pedido. */
    public function calificarPlatilloComoCliente(User $usuario, Pedido $pedido, string $fecha, int $platilloId, int $estrellas, ?string $comentario = null, ?CarbonInterface $ahora = null): CalificacionPlatillo
    {
        $this->dentroDelPlazo($fecha, $ahora);
        $dia = $this->diaEntregado($usuario, $pedido, $fecha);
        if (! $dia->platillos()->where('menu_id', $platilloId)->exists()) {
            throw new ReglaDeNegocioException('sin_platillo_que_calificar', 'Ese platillo no fue parte de tu pedido ese día.');
        }

        return $this->guardarPlatillo($usuario, $platilloId, $fecha, $estrellas, $comentario);
    }

    private function guardarPlatillo(User $usuario, int $menuId, string $fecha, int $estrellas, ?string $comentario): CalificacionPlatillo
    {
        if (CalificacionPlatillo::query()->where('calificador_id', $usuario->id)->where('menu_id', $menuId)->whereDate('fecha', $fecha)->exists()) {
            throw new ReglaDeNegocioException('ya_calificada', 'Ya calificaste este platillo.');
        }

        return CalificacionPlatillo::create(['calificador_id' => $usuario->id, 'menu_id' => $menuId, 'fecha' => $fecha, 'estrellas' => $estrellas, 'comentario' => $comentario]);
    }

    private function diaEntregado(User $usuario, Pedido $pedido, string $fecha): PedidoDia
    {
        $dia = $pedido->cliente_id === $usuario->id ? PedidoDia::query()->where('pedido_id', $pedido->id)->whereDate('fecha', $fecha)->first() : null;
        if (! $dia || $dia->estado !== 'entregada') {
            throw new ReglaDeNegocioException('sin_entrega_que_calificar', 'Solo se califica una entrega de tu pedido que ya recibiste.');
        }

        return $dia;
    }

    /** @param  array{puntualidad: int, trato_chofer: int, empaque: int}  $estrellas */
    private function estrellasDeEntrega(array $estrellas): array
    {
        return ['puntualidad' => (int) $estrellas['puntualidad'], 'trato_chofer' => (int) $estrellas['trato_chofer'], 'empaque' => (int) $estrellas['empaque']];
    }

    private function dentroDelPlazo(string $fecha, ?CarbonInterface $ahora): void
    {
        $hoy = $this->calendario->ahora($ahora)->startOfDay();
        $dia = $this->calendario->dia($fecha);
        if ($dia->gt($hoy) || $dia->lt($hoy->subDays(self::DIAS_PARA_CALIFICAR))) {
            throw new ReglaDeNegocioException('fuera_de_plazo', 'Solo se califica lo entregado en los últimos '.self::DIAS_PARA_CALIFICAR.' días.');
        }
    }
}
