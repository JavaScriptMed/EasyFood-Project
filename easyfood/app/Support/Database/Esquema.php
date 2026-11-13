<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Piezas repetidas de las migraciones de EasyLunch (PostgreSQL): marcas de tiempo, desactivación lógica,
 * triggers y restricciones CHECK con nombre. Mantenerlo pequeño y estable: las migraciones antiguas lo usan.
 */
final class Esquema
{
    /** creado_en / actualizado_en como timestamptz con valor por defecto now(). */
    public static function marcasDeTiempo(Blueprint $tabla): void
    {
        $tabla->timestampTz('creado_en')->useCurrent();
        $tabla->timestampTz('actualizado_en')->useCurrent();
    }

    /** Columnas de desactivación lógica (activo + desactivado_en). Después llama a desactivacionLogica(). */
    public static function columnasDeDesactivacion(Blueprint $tabla): void
    {
        $tabla->boolean('activo')->default(true);
        $tabla->timestampTz('desactivado_en')->nullable();
    }

    /** CHECK "inactivo siempre trae su fecha" y trigger que impide el DELETE. Requiere la función impedir_borrado(). */
    public static function desactivacionLogica(string $tabla): void
    {
        self::check($tabla, 'desactivacion_coherente', '(activo AND desactivado_en IS NULL) OR (NOT activo AND desactivado_en IS NOT NULL)');
        self::sinBorrado($tabla);
    }

    public static function sinBorrado(string $tabla): void
    {
        DB::unprepared("CREATE TRIGGER {$tabla}_sin_borrado BEFORE DELETE ON {$tabla} FOR EACH ROW EXECUTE FUNCTION impedir_borrado()");
    }

    /** ALTER TABLE ... ADD CONSTRAINT {tabla}_{nombre} CHECK (...). */
    public static function check(string $tabla, string $nombre, string $expresion): void
    {
        DB::unprepared("ALTER TABLE {$tabla} ADD CONSTRAINT {$tabla}_{$nombre} CHECK ({$expresion})");
    }

    /** CHECK (columna IN (...)). El nombre queda {tabla}_{columna}_valido. */
    public static function enumerado(string $tabla, string $columna, array $valores): void
    {
        $lista = implode(', ', array_map(fn ($v) => "'{$v}'", $valores));
        self::check($tabla, "{$columna}_valido", "{$columna} IN ({$lista})");
    }

    /** El valor es un primer día de mes (para columnas "mes"). */
    public static function primerDiaDeMes(string $tabla, string $columna = 'mes'): void
    {
        self::check($tabla, "{$columna}_primer_dia", "EXTRACT(DAY FROM {$columna}) = 1");
    }
}
