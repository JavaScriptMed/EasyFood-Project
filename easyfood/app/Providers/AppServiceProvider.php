<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Pasarela de pagos: Stripe, con las claves de .env. Las pruebas la reemplazan por una falsa (sin red).
        $this->app->singleton(\App\Services\Pagos\PasarelaDePagos::class, fn () => new \App\Services\Pagos\PasarelaStripe(
            config('services.stripe.secret'), config('services.stripe.webhook_secret'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->limitarIntentos();
    }

    /** Límites de intentos contra adivinar contraseñas, enlaces o correos. Se cuentan por IP real (ver TRUSTED_PROXIES). */
    private function limitarIntentos(): void
    {
        $correo = fn (Request $r) => mb_strtolower(trim((string) $r->input('email', $r->input('correo', ''))));

        // Iniciar sesión: 5 intentos por minuto para la misma cuenta desde la misma IP, y 30 por minuto por IP en total.
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by('login|'.$correo($r).'|'.$r->ip()),
            Limit::perMinute(30)->by('login-ip|'.$r->ip()),
        ]);

        // Crear cuentas: pocas por hora desde una misma IP.
        RateLimiter::for('registro', fn (Request $r) => Limit::perHour(10)->by('registro|'.$r->ip()));

        // «Olvidé mi contraseña»: 3 por hora para un mismo correo (exista o no: la respuesta siempre es igual) y 10 por hora por IP.
        RateLimiter::for('recuperar', fn (Request $r) => [
            Limit::perHour(3)->by('recuperar|'.$correo($r)),
            Limit::perHour(10)->by('recuperar-ip|'.$r->ip()),
        ]);
    }
}
