<?php

namespace App\Console\Commands;

use App\Services\CorteDeIndividuales;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EjecutarCorteDeIndividuales extends Command
{
    protected $signature = 'easylunch:corte-individuales';

    protected $description = 'Ejecuta el corte de clientes individuales el sábado a la hora de corte: órdenes de producción y rutas de su entrega (el scheduler lo llama cada minuto)';

    public function handle(CorteDeIndividuales $corteDeIndividuales): int
    {
        $corte = $corteDeIndividuales->ejecutarSiCorresponde();

        if (! $corte) {
            return self::SUCCESS; // no es sábado de entrega, todavía no es hora o ya se cortó: sin ruido
        }

        $resumen = "Corte de individuales del {$corte->fecha_entrega->toDateString()}: {$corte->estado}. {$corte->mensaje}";
        if ($corte->estado === 'fallido') {
            Log::error($resumen);
            $this->error($resumen);

            return self::FAILURE;
        }

        Log::info($resumen, ['ordenes_generadas' => $corte->ordenes_generadas, 'rutas_creadas' => $corte->rutas_creadas]);
        $this->info($resumen);

        return self::SUCCESS;
    }
}
