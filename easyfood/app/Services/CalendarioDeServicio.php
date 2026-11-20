<?php

namespace App\Services;

use App\Exceptions\ReglaDeNegocioException;
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
 * - Los clientes individuales son distintos: reciben TODO su pedido en una sola entrega, el sábado de cada semana. Los pedidos de
 *   lunes a viernes entran al sábado de esa semana; los del sábado, hasta la hora de corte DE ESE MISMO SÁBADO (después pasan al
 *   siguiente). Las empresas no cambian: siguen recibiendo de lunes a sábado.
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
            ->where('tipo', 'general')
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

    // ------------------------------------------------------------------ clientes individuales: una entrega por semana, el sábado

    /** ¿Es un sábado en que se entrega a clientes individuales? (un sábado festivo o sin servicio no cuenta). */
    public function esDiaDeEntregaDeIndividuales(CarbonInterface|string $fecha): bool
    {
        $dia = $this->dia($fecha);

        return $dia->isSaturday() && $this->esDiaDeServicio($dia);
    }

    /**
     * Instante en que cierran los pedidos de individuales de ese sábado: la hora de corte del MISMO sábado (a diferencia de las
     * empresas, cuyo corte es el día anterior). Para pasarlo a «el viernes a la hora de corte» basta con restar un día aquí.
     */
    public function momentoDeCierreDeIndividuales(CarbonInterface|string $sabado): CarbonImmutable
    {
        [$hora, $minuto] = array_map('intval', explode(':', $this->horaDeCorte()));

        return $this->dia($sabado)->setTime($hora, $minuto);
    }

    public function corteDeIndividualesYaEjecutado(CarbonInterface|string $sabado): bool
    {
        return Corte::query()
            ->whereDate('fecha_entrega', $this->dia($sabado)->toDateString())
            ->where('tipo', 'individuales')->where('sin_cambios', false)->where('estado', '<>', 'fallido')
            ->exists();
    }

    /** ¿Todavía entra un pedido de individuales a la entrega de ese sábado? Falso desde la hora de cierre o si su corte ya se ejecutó. */
    public function pedidosDeIndividualesAbiertos(CarbonInterface|string $sabado, ?CarbonInterface $ahora = null): bool
    {
        return $this->ahora($ahora)->lt($this->momentoDeCierreDeIndividuales($sabado)) && ! $this->corteDeIndividualesYaEjecutado($sabado);
    }

    /**
     * Sábado en que llega un pedido de individuales hecho en $ahora: el primer sábado de servicio que todavía admite pedidos.
     * Lunes a viernes → el sábado de esa semana; sábado antes de la hora de corte → ese sábado; sábado después → el siguiente.
     */
    public function fechaDeEntregaDeIndividuales(?CarbonInterface $ahora = null): CarbonImmutable
    {
        $ahora = $this->ahora($ahora);
        $candidata = $ahora->startOfDay();

        for ($i = 0; $i < 70; $i++, $candidata = $candidata->addDay()) {
            if ($this->esDiaDeEntregaDeIndividuales($candidata) && $this->pedidosDeIndividualesAbiertos($candidata, $ahora)) {
                return $candidata;
            }
        }

        throw new ReglaDeNegocioException('sin_dia_de_entrega', 'Por ahora no hay sábados de entrega para clientes individuales: intenta más tarde.');
    }

    /**
     * Los $cantidad días de comida de un pedido de individuales: los siguientes días de servicio consecutivos DESPUÉS de la entrega
     * (lunes a sábado de la semana siguiente; el domingo y los festivos no cuentan).
     *
     * @return array<int, CarbonImmutable>
     */
    public function diasDeComidaDeUnPedido(int $cantidad, CarbonInterface|string $entrega): array
    {
        $fechas = [];
        for ($candidata = $this->dia($entrega)->addDay(); count($fechas) < $cantidad; $candidata = $candidata->addDay()) {
            if ($this->esDiaDeServicio($candidata)) {
                $fechas[] = $candidata;
            }
        }

        return $fechas;
    }

    /** ¿Ya terminó el mes (AAAA-MM o una fecha de ese mes)? Facturas y liquidaciones solo se generan de meses terminados. */
    public function mesTerminado(CarbonInterface|string $mes, ?CarbonInterface $ahora = null): bool
    {
        return $this->ahora($ahora)->gte($this->dia($mes)->startOfMonth()->addMonth());
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
