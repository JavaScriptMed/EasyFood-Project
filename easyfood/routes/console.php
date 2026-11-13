<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tareas automáticas de EasyLunch
|--------------------------------------------------------------------------
| Corren con `php artisan schedule:work` (servicio «scheduler» de docker-compose) o con un cron de producción:
|   * * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
| Cada comando decide por sí mismo si ya le toca (hora de corte configurable, días de servicio, trabajo ya hecho),
| así que llamarlos de más no cambia nada. Hora de Guadalajara (APP_TIMEZONE).
*/

// Corte diario: cada minuto revisa si ya llegó la hora de corte de la siguiente fecha de entrega (hoy 18:00 por defecto).
Schedule::command('easylunch:corte-automatico')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onFailure(fn () => Log::error('El corte automático terminó con error: revisa el último registro «fallido» de la tabla cortes.'));

// Pedidos individuales que siguen sin pagarse cuando pasa el corte de su primer día.
Schedule::command('easylunch:cancelar-pedidos-vencidos')
    ->everyMinute()
    ->withoutOverlapping(10);

// Contratos que vencen este mes o en los 7 días siguientes: una revisión diaria por la mañana.
Schedule::command('easylunch:avisos-de-contratos')
    ->dailyAt('08:00')
    ->withoutOverlapping(30);
