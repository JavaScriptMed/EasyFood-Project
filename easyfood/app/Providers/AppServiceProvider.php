<?php

namespace App\Providers;

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
        //
    }
}
