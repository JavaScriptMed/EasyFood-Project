<?php

namespace App\Console\Commands;

use App\Services\CorteAutomatico;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EjecutarCorteAutomatico extends Command
{
    protected $signature = 'easylunch:corte-automatico';

    protected $description = 'Ejecuta el corte diario del siguiente día de servicio cuando llega su hora (el scheduler lo llama cada minuto)';

    public function handle(CorteAutomatico $corteAutomatico): int
    {
        $corte = $corteAutomatico->ejecutarSiCorresponde();

        if (! $corte) {
            return self::SUCCESS; // todavía no es hora, no es día de servicio o ya se cortó: sin ruido
        }

        $resumen = "Corte de la entrega del {$corte->fecha_entrega->toDateString()}: {$corte->estado}. {$corte->mensaje}";
        if ($corte->estado === 'fallido') {
            Log::error($resumen);
            $this->error($resumen);

            return self::FAILURE;
        }

        Log::info($resumen, [
            'elecciones_cerradas' => $corte->elecciones_cerradas, 'empleados_por_defecto' => $corte->empleados_por_defecto,
            'ordenes_generadas' => $corte->ordenes_generadas, 'entregas_consolidadas' => $corte->entregas_consolidadas, 'rutas_creadas' => $corte->rutas_creadas,
        ]);
        $this->info($resumen);

        return self::SUCCESS;
    }
}
