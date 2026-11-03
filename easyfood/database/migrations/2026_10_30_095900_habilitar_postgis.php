<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Las migraciones de EasyLunch usan CHECK, índices parciales, triggers y PostGIS: solo existen en PostgreSQL.
        // Falla aquí, de primera, con un mensaje claro, en lugar de un error confuso a la mitad.
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Las migraciones de EasyLunch requieren PostgreSQL con PostGIS. Levanta el entorno con Docker Compose (ver README, sección "Entorno con Docker").');
        }

        // Garantiza PostGIS en cualquier base (desarrollo, pruebas, producción), no solo en la que crea el script de arranque de Docker.
        DB::unprepared('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function down(): void
    {
        // No se elimina la extensión: otras bases u objetos pueden depender de ella.
    }
};
