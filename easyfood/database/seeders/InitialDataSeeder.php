<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InitialDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('roles')->insertOrIgnore([
            ['id' => 1, 'name' => 'customer', 'display_name' => 'Cliente', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'admin', 'display_name' => 'Administrador', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'driver', 'display_name' => 'Repartidor', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'provider', 'display_name' => 'Comedor (proveedor)', 'created_at' => now(), 'updated_at' => now()],
            // Roles que pide CLAUDE.md y no existían. 'provider' es el comedor (decisión del equipo: los comedores son la tabla providers).
            ['id' => 5, 'name' => 'support', 'display_name' => 'Soporte', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'name' => 'company', 'display_name' => 'Empresa (RR. HH.)', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 7, 'name' => 'employee', 'display_name' => 'Empleado', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}