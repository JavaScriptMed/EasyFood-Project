<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Ejecutamos únicamente los seeders oficiales del sistema y de ejemplo
        $this->call([
            InitialDataSeeder::class,     // Inserta los roles base (customer, admin, driver, etc.)
            DatosDeEjemploSeeder::class,  // Carga todo el entorno completo de EasyLunch (zonas, comedores, empresas, etc.)
        ]);
    }
}
