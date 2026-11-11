<?php

namespace App\Services;

use App\Models\ContratoEmpresa;
use App\Models\Configuracion;
use App\Models\Corte;
use App\Models\DiaSinServicio;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Qué días se entrega y cuándo cierra cada uno.
 *
 * - Se entrega de lunes a sábado (o de lunes a viernes si configuracion.dias_entrega lo dice); el domingo nunca hay servicio.
 * - Los días festivos / sin servicio generales y los cierres de una empresa (tabla dias_sin_servicio) tampoco se entregan.
 * - El corte de una entrega es a la hora configurada (18:00 por defecto, hora de Guadalajara) del DÍA CALENDARIO ANTERIOR.
 * - Un pedido nuevo va a la primera fecha de servicio cuyo corte aún no pasó.
 */
class CalendarioDeServicio
{
    public function horaDeCorte(): string
    {
        return Configuracion::find('hora_corte')?->valor ?? '18:00';
    }

    /** 'lunes_viernes' o 'lunes_sabado'. */
    public function diasDeEntrega(): string
    {
        return Configuracion::find('dias_entrega')?->valor ?? 'lunes_sabado';
    }

    public function capacidadDelAuto(): array
    {
        $valores = Configuracion::query()->whereIn('clave', ['capacidad_auto_entregas', 'capacidad_auto_platillos'])->pluck('valor', 'clave');

        return [
            'entregas' => (int) ($valores['capacidad_auto_entregas'] ?? 30),
            'platillos' => (int) ($valores['capacidad_auto_platillos'] ?? 120),
        ];
    }

    public function dia(CarbonInterface|string $fecha): CarbonImmutable
    {
        return CarbonImmutable::parse($fecha instanceof CarbonInterface ? $fecha->toDateString() : $fecha, config('app.timezone'))->startOfDay();
    }

    public function ahora(?CarbonInterface $ahora = null): CarbonImmutable
    {
        return ($ahora ?? now())->toImmutable()->setTimezone(config('app.timezone'));
    }

    /** Solo el calendario general: días de la semana configurados y festivos/sin servicio generales. */
    public function esDiaDeServicio(CarbonInterface|string $fecha, ?int $empresaId = null): bool
    {
        $dia = $this->dia($fecha);

        if ($dia->isSunday() || ($dia->isSaturday() && $this->diasDeEntrega() === 'lunes_viernes')) {
            return false;
        }

        return ! DiaSinServicio::query()
            ->whereDate('fecha', $dia->toDateString())
            ->where(fn ($q) => $q->whereNull('empresa_id')->when($empresaId, fn ($q) => $q->orWhere('empresa_id', $empresaId)))
            ->exists();
    }

    /** Instante en que cierran las elecciones de una fecha de entrega: la hora de corte del día anterior. */
    public function momentoDelCorte(CarbonInterface|string $fechaEntrega): CarbonImmutable
    {
        [$hora, $minuto] = array_map('intval', explode(':', $this->horaDeCorte()));

        return $this->dia($fechaEntrega)->subDay()->setTime($hora, $minuto);
    }

    public function corteYaEjecutado(CarbonInterface|string $fechaEntrega): bool
    {
        return Corte::query()
            ->whereDate('fecha_entrega', $this->dia($fechaEntrega)->toDateString())
            ->where('sin_cambios', false)
            ->where('estado', '<>', 'fallido')
            ->exists();
    }

    /**
     * ¿Todavía se puede elegir, cambiar o pedir para esa fecha? Falso desde la hora de corte exacta
     * (a las 18:00:00 ya está cerrado) o si el corte ya se ejecutó a mano antes de tiempo.
     */
    public function corteAbierto(CarbonInterface|string $fechaEntrega, ?CarbonInterface $ahora = null): bool
    {
        return $this->ahora($ahora)->lt($this->momentoDelCorte($fechaEntrega)) && ! $this->corteYaEjecutado($fechaEntrega);
    }

    /**
     * Fecha de entrega que le toca a un pedido hecho en $ahora: la primera fecha de servicio con el corte abierto.
     * Viernes 17:00 → sábado; viernes 19:00 → lunes; sábado → lunes; domingo 17:00 → lunes; domingo 19:00 → martes.
     */
    public function fechaDeEntregaParaPedidoNuevo(?CarbonInterface $ahora = null, ?int $empresaId = null): CarbonImmutable
    {
        $ahora = $this->ahora($ahora);

        return $this->siguienteFechaDeServicio($ahora->startOfDay()->addDay(), $ahora, $empresaId);
    }

    /** Primera fecha desde $desde (inclusive) que sea de servicio y tenga el corte abierto. */
    private function siguienteFechaDeServicio(CarbonImmutable $desde, CarbonImmutable $ahora, ?int $empresaId): CarbonImmutable
    {
        $candidata = $desde;
        for ($i = 0; $i < 60; $i++) {
            if ($this->esDiaDeServicio($candidata, $empresaId) && $this->corteAbierto($candidata, $ahora)) {
                return $candidata;
            }
            $candidata = $candidata->addDay();
        }

        throw new \RuntimeException('No hay días de servicio en los próximos 60 días: revisa dias_sin_servicio.');
    }

    /**
     * Las siguientes $cantidad fechas de servicio consecutivas a partir de la que le toca a un pedido nuevo
     * (para paquetes de 1, 2 o 6 días).
     *
     * @return array<int, CarbonImmutable>
     */
    public function fechasDeUnPaquete(int $cantidad, ?CarbonInterface $ahora = null): array
    {
        $ahora = $this->ahora($ahora);
        $fechas = [$this->fechaDeEntregaParaPedidoNuevo($ahora)];

        while (count($fechas) < $cantidad) {
            $candidata = end($fechas)->addDay();
            while (! $this->esDiaDeServicio($candidata)) {
                $candidata = $candidata->addDay();
            }
            $fechas[] = $candidata;
        }

        return $fechas;
    }

    /** Contrato de la empresa que cubre una fecha (uno activo por empresa; debe estar dentro de su vigencia). */
    public function contratoVigente(int $empresaId, CarbonInterface|string $fecha): ?ContratoEmpresa
    {
        $dia = $this->dia($fecha)->toDateString();

        return ContratoEmpresa::query()->activos()
            ->where('empresa_id', $empresaId)
            ->whereDate('vigencia_inicio', '<=', $dia)
            ->whereDate('vigencia_fin', '>=', $dia)
            ->first();
    }
}
