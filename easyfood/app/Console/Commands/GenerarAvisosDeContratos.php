<?php

namespace App\Console\Commands;

use App\Services\AvisosDeVencimiento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerarAvisosDeContratos extends Command
{
    protected $signature = 'easylunch:avisos-de-contratos';

    protected $description = 'Avisa a las empresas y a los administradores de los contratos que vencen este mes o en los 7 días siguientes';

    public function handle(AvisosDeVencimiento $avisos): int
    {
        $nuevos = $avisos->generar();

        Log::info("Avisos de contratos por vencer creados: {$nuevos}.");
        $this->info("Avisos nuevos: {$nuevos}.");

        return self::SUCCESS;
    }
}
