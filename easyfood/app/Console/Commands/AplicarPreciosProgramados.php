<?php

namespace App\Console\Commands;

use App\Services\HistorialDePrecios;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AplicarPreciosProgramados extends Command
{
    protected $signature = 'easylunch:aplicar-precios-programados';

    protected $description = 'Copia a cada platillo el precio de venta que entra en vigor hoy (los precios programados)';

    public function handle(HistorialDePrecios $historial): int
    {
        $cambiados = $historial->aplicarVigentes();

        Log::info("Precios de platillos aplicados: {$cambiados}.");
        $this->info("Platillos con precio nuevo: {$cambiados}.");

        return self::SUCCESS;
    }
}
