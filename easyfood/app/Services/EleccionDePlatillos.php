<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\DisponibilidadDia;
use App\Models\EleccionEmpleado;
use App\Models\Empleado;
use App\Models\Menu;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Un empleado elige (o cambia, o quita) su platillo de un día y tiempo de comida, solo antes del corte.
 * Al elegir se congelan el precio y el porcentaje de subsidio del contrato de su empresa: cambios posteriores
 * del contrato no tocan lo ya elegido.
 */
class EleccionDePlatillos
{
    public function __construct(private readonly CalendarioDeServicio $calendario) {}

    public function elegir(Empleado $empleado, CarbonInterface|string $fecha, string $tiempoComida, Menu|int $menu, ?CarbonInterface $ahora = null): EleccionEmpleado
    {
        $dia = $this->calendario->dia($fecha);

        return DB::transaction(function () use ($empleado, $dia, $tiempoComida, $menu, $ahora) {
            // Se vuelve a leer: los valores por defecto de la base (activo) no vienen en un modelo recién creado.
            $empleado = Empleado::query()->with('empresa')->findOrFail($empleado->id);
            $this->exigirEmpleadoActivo($empleado);
            $this->exigirCorteAbierto($dia, $ahora);

            if (! $this->calendario->esDiaDeServicio($dia, $empleado->empresa_id)) {
                throw new ReglaDeNegocioException('dia_sin_servicio', 'Ese día no hay servicio para tu empresa.');
            }

            $contrato = $this->calendario->contratoVigente($empleado->empresa_id, $dia)
                ?? throw new ReglaDeNegocioException('sin_contrato_vigente', 'La empresa no tiene un contrato vigente para ese día.');

            $platillo = Menu::query()->find($menu instanceof Menu ? $menu->id : $menu);
            if (! $platillo || $platillo->estado_revision !== 'publicado' || ! $platillo->is_available || $platillo->tiempo_comida !== $tiempoComida) {
                throw new ReglaDeNegocioException('platillo_no_disponible', 'Ese platillo no está disponible para ese tiempo de comida.');
            }

            $actual = EleccionEmpleado::query()
                ->where('empleado_id', $empleado->id)->whereDate('fecha', $dia->toDateString())->where('tiempo_comida', $tiempoComida)
                ->lockForUpdate()->first();

            if ($actual && $actual->menu_id === $platillo->id) {
                return $actual; // Ya era su elección: no se vuelve a congelar ni a contar.
            }

            $disponibilidad = DisponibilidadDia::query()->where('menu_id', $platillo->id)->whereDate('fecha', $dia->toDateString())->lockForUpdate()->first();
            if (! $disponibilidad || $disponibilidad->porciones_tomadas >= $disponibilidad->porciones_maximas) {
                throw new ReglaDeNegocioException('sin_porciones', 'Ya no quedan porciones de ese platillo para ese día.');
            }

            $congelado = ['precio_congelado' => $contrato->precio_por_platillo, 'porcentaje_subsidio_congelado' => $contrato->porcentaje_subsidio];

            if ($actual) {
                $this->liberarPorcion($actual->menu_id, $dia->toDateString());
                $actual->update($congelado + ['menu_id' => $platillo->id, 'origen' => 'manual']);
                $eleccion = $actual;
            } else {
                $eleccion = EleccionEmpleado::create($congelado + [
                    'empleado_id' => $empleado->id, 'fecha' => $dia->toDateString(), 'tiempo_comida' => $tiempoComida,
                    'menu_id' => $platillo->id, 'origen' => 'manual',
                ]);
            }
            $disponibilidad->increment('porciones_tomadas');

            return $eleccion->refresh();
        });
    }

    public function quitar(Empleado $empleado, CarbonInterface|string $fecha, string $tiempoComida, ?CarbonInterface $ahora = null): void
    {
        $dia = $this->calendario->dia($fecha);

        DB::transaction(function () use ($empleado, $dia, $tiempoComida, $ahora) {
            $this->exigirCorteAbierto($dia, $ahora);

            $eleccion = EleccionEmpleado::query()
                ->where('empleado_id', $empleado->id)->whereDate('fecha', $dia->toDateString())->where('tiempo_comida', $tiempoComida)
                ->lockForUpdate()->first()
                ?? throw new ReglaDeNegocioException('sin_eleccion', 'No hay una elección para ese día y tiempo de comida.');

            $this->liberarPorcion($eleccion->menu_id, $dia->toDateString());
            $eleccion->delete();
        });
    }

    private function exigirEmpleadoActivo(Empleado $empleado): void
    {
        if (! $empleado->activo || ! $empleado->empresa?->activo) {
            throw new ReglaDeNegocioException('empleado_inactivo', 'El empleado o su empresa están desactivados.');
        }
    }

    private function exigirCorteAbierto($dia, ?CarbonInterface $ahora): void
    {
        if (! $this->calendario->corteAbierto($dia, $ahora)) {
            $corte = $this->calendario->momentoDelCorte($dia)->locale('es')->isoFormat('dddd D [de] MMMM, HH:mm');
            throw new ReglaDeNegocioException('corte_cerrado', "El corte de ese día ya pasó ({$corte}): la elección ya no se puede cambiar.");
        }
    }

    private function liberarPorcion(int $menuId, string $fecha): void
    {
        DisponibilidadDia::query()->where('menu_id', $menuId)->whereDate('fecha', $fecha)->where('porciones_tomadas', '>', 0)->decrement('porciones_tomadas');
    }
}
