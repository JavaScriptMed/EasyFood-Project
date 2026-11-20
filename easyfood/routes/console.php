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

// Corte de clientes individuales: el sábado, cuando llega la hora de corte, cierra sus pedidos y arma su orden de producción y sus rutas.
Schedule::command('easylunch:corte-individuales')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onFailure(fn () => Log::error('El corte de individuales terminó con error: revisa el último registro «fallido» (tipo individuales) de la tabla cortes.'));

// Pedidos individuales que siguen sin pagarse cuando se cumple su plazo (el cierre de pedidos de su sábado).
Schedule::command('easylunch:cancelar-pedidos-vencidos')
    ->everyMinute()
    ->withoutOverlapping(10);

// Contratos que vencen este mes o en los 7 días siguientes: una revisión diaria por la mañana.
Schedule::command('easylunch:avisos-de-contratos')
    ->dailyAt('08:00')
    ->withoutOverlapping(30);

// Tokens de acceso vencidos (SANCTUM_EXPIRATION): se borran una vez al día.
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('02:30');

// Precios de platillos programados: cada madrugada entra en vigor el que toca ese día.
Schedule::command('easylunch:aplicar-precios-programados')
    ->dailyAt('00:05')
    ->withoutOverlapping(30);
