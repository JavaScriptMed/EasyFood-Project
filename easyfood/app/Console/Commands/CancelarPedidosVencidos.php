<?php

namespace App\Console\Commands;

use App\Services\ContratacionDePaquetes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CancelarPedidosVencidos extends Command
{
    protected $signature = 'easylunch:cancelar-pedidos-vencidos';

    protected $description = 'Cancela los pedidos individuales por pagar cuyo plazo (el corte de su primer día) ya pasó y libera sus porciones';

    public function handle(ContratacionDePaquetes $paquetes): int
    {
        $cancelados = $paquetes->cancelarVencidos();

        if ($cancelados > 0) {
            Log::info("Pedidos por pagar cancelados por vencimiento: {$cancelados}.");
            $this->info("Pedidos cancelados: {$cancelados}.");
        }

        return self::SUCCESS;
    }
}
