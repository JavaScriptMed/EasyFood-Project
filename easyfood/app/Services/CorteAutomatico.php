<?php

namespace App\Services;

use App\Models\Corte;
use Carbon\CarbonInterface;

/**
 * Decide si ya toca ejecutar el corte del sistema y, si sí, lo ejecuta. El scheduler lo llama cada minuto.
 *
 * El corte automático es el de la siguiente fecha calendario (mañana) cuando es día de servicio y ya llegó su hora de corte
 * (la hora configurada del día anterior a la entrega: hoy a las 18:00 para la entrega de mañana). Así:
 *  - viernes 18:00 corta el sábado; sábado 18:00 no corta nada (domingo no hay servicio); domingo 18:00 corta el lunes;
 *  - los festivos y cierres generales no se cortan;
 *  - si ya hay un corte real de esa fecha (automático o manual) no hace nada, así que repetir la llamada no deja registros «sin cambios».
 *
 * Si el corte falla, deja un registro «fallido» (la base queda igual) y se reintenta cada {@see self::REINTENTO_CADA_MINUTOS}
 * minutos, hasta {@see self::INTENTOS_MAXIMOS} veces; después se deja para que un administrador lo resuelva y lo ejecute a mano.
 * Si el sistema estuvo apagado hasta pasada la medianoche, la fecha de entrega ya no se puede cortar (el corte solo acepta
 * fechas futuras): esos casos se ven en el panel porque quedan elecciones sin cerrar.
 */
class CorteAutomatico
{
    public const REINTENTO_CADA_MINUTOS = 5;

    public const INTENTOS_MAXIMOS = 12;

    public function __construct(
        private readonly CalendarioDeServicio $calendario,
        private readonly EjecutorDelCorte $ejecutor,
    ) {}

    /** @return Corte|null el corte ejecutado en esta llamada, o null si no tocaba */
    public function ejecutarSiCorresponde(?CarbonInterface $ahora = null): ?Corte
    {
        $ahora = $this->calendario->ahora($ahora);
        $fecha = $ahora->startOfDay()->addDay();

        if (! $this->calendario->esDiaDeServicio($fecha)
            || $ahora->lt($this->calendario->momentoDelCorte($fecha))
            || $this->calendario->corteYaEjecutado($fecha)) {
            return null;
        }

        $fallidos = Corte::query()->whereDate('fecha_entrega', $fecha->toDateString())->where('estado', 'fallido')->orderByDesc('ejecutado_en')->get();
        if ($fallidos->count() >= self::INTENTOS_MAXIMOS) {
            return null;
        }
        if ($fallidos->isNotEmpty() && $fallidos->first()->ejecutado_en->gt($ahora->subMinutes(self::REINTENTO_CADA_MINUTOS))) {
            return null;
        }

        return $this->ejecutor->ejecutar($fecha, null, $ahora);
    }
}
