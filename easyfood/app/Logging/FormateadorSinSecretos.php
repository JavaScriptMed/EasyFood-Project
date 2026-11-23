<?php

namespace App\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/** Da el formato de siempre y después tacha los secretos de la línea resultante. */
class FormateadorSinSecretos implements FormatterInterface
{
    public function __construct(private readonly FormatterInterface $interno) {}

    public function format(LogRecord $record): mixed
    {
        $linea = $this->interno->format($record);

        return is_string($linea) ? OcultarSecretos::limpiar($linea) : $linea;
    }

    public function formatBatch(array $records): mixed
    {
        return array_map(fn ($r) => $this->format($r), $records);
    }
}
