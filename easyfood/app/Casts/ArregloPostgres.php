<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Convierte columnas text[] de PostgreSQL ('{a,"b c"}') en arreglos de PHP y de regreso.
 * Solo soporta arreglos de una dimensión de texto, que es lo que usan alérgenos y etiquetas.
 */
class ArregloPostgres implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }

        $texto = trim($value);
        if ($texto === '{}' || $texto === '') {
            return [];
        }
        if ($texto[0] !== '{' || substr($texto, -1) !== '}') {
            throw new InvalidArgumentException("Valor de arreglo de PostgreSQL no válido: {$value}");
        }

        $elementos = [];
        $actual = '';
        $entreComillas = false;
        $conComillas = false;
        $contenido = substr($texto, 1, -1);

        for ($i = 0, $largo = strlen($contenido); $i < $largo; $i++) {
            $caracter = $contenido[$i];
            if ($entreComillas) {
                if ($caracter === '\\') {
                    $actual .= $contenido[++$i] ?? '';
                } elseif ($caracter === '"') {
                    $entreComillas = false;
                } else {
                    $actual .= $caracter;
                }
            } elseif ($caracter === '"') {
                $entreComillas = true;
                $conComillas = true;
            } elseif ($caracter === ',') {
                $elementos[] = $this->cerrar($actual, $conComillas);
                $actual = '';
                $conComillas = false;
            } else {
                $actual .= $caracter;
            }
        }
        $elementos[] = $this->cerrar($actual, $conComillas);

        return $elementos;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException("{$key} debe ser un arreglo.");
        }

        $elementos = array_map(
            fn ($elemento) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $elemento).'"',
            array_values($value),
        );

        return '{'.implode(',', $elementos).'}';
    }

    private function cerrar(string $valor, bool $conComillas): ?string
    {
        // Un NULL sin comillas dentro del arreglo es nulo; entre comillas es el texto "NULL".
        return (! $conComillas && strtoupper($valor) === 'NULL') ? null : $valor;
    }
}
