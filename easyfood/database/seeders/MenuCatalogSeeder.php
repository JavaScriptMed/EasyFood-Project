<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Provider;
use App\Models\CatalogCategory;
use App\Models\Menu;
use App\Models\User;

class MenuCatalogSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Crear un usuario proveedor si no existe
        $providerUser = User::firstOrCreate(
            ['email' => 'comedor.central@easylunch.com'],
            [
                'name' => 'Comedor Central',
                'password' => bcrypt('12345678'),
                'role_id' => 4, // ID del rol provider
                'status' => 'active',
            ]
        );

        // 2. Crear el Proveedor / Comedor
        $provider = Provider::firstOrCreate(
            ['email' => 'contacto@comedorcentral.com'],
            [
                'user_id' => $providerUser->id,
                'business_name' => 'Comedor Industrial Central S.A. de C.V.',
                'commercial_name' => 'Comedor Central UTJ',
                'phone' => '3312345678',
                'address' => 'Av. Tecnológico #123, Col. Centro',
                'postal_code' => '44100',
                'latitude' => 20.676667,
                'longitude' => -103.347222,
                'daily_capacity' => 150,
                'is_active' => true,
            ]
        );

        // 3. Crear Categorías de Catálogo
        $catDesayuno = CatalogCategory::firstOrCreate(
            ['code' => 'DESAYUNO'],
            ['name' => 'Desayunos', 'is_active' => true]
        );

        $catComida = CatalogCategory::firstOrCreate(
            ['code' => 'COMIDA'],
            ['name' => 'Comidas Corridas', 'is_active' => true]
        );

        $catFit = CatalogCategory::firstOrCreate(
            ['code' => 'FIT'],
            ['name' => 'Línea Fit / Saludable', 'is_active' => true]
        );

        // 4. Crear Platillos / Menús de Prueba
        Menu::create([
            'provider_id' => $provider->id,
            'category_id' => $catDesayuno->id,
            'name' => 'Chilaquiles Verdes con Pollo',
            'description' => 'Acompañados de frijoles refritos, crema, queso fresco y pechuga deshebrada.',
            'price' => 65.00,
            'image_url' => 'https://via.placeholder.com/150',
            'daily_stock' => 30,
            'is_available' => true,
            'available_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ]);

        Menu::create([
            'provider_id' => $provider->id,
            'category_id' => $catComida->id,
            'name' => 'Pechuga Cordon Bleu',
            'description' => 'Rellena de jamón y queso, acompañada de ensalada rusa y arroz blanco.',
            'price' => 85.00,
            'image_url' => 'https://via.placeholder.com/150',
            'daily_stock' => 50,
            'is_available' => true,
            'available_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ]);

        Menu::create([
            'provider_id' => $provider->id,
            'category_id' => $catFit->id,
            'name' => 'Ensalada César con Pollo A La Plancha',
            'description' => 'Mezcla de lechugas, crutones integrales, aderezo césar light y 150g de pechuga.',
            'price' => 75.50,
            'image_url' => 'https://via.placeholder.com/150',
            'daily_stock' => 20,
            'is_available' => true,
            'available_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
        ]);
    }
}