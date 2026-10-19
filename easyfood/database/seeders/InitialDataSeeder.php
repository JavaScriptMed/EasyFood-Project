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
            ['id' => 4, 'name' => 'provider', 'display_name' => 'Proveedor', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}