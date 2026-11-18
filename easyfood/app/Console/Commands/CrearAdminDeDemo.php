<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Console\Command;

class CrearAdminDeDemo extends Command
{
    protected $signature = 'easylunch:admin-demo {correo=demo@easylunch.test} {contrasena=Admin1234}';

    protected $description = 'Crea (o reactiva) un administrador con contraseña conocida para probar rápido. Solo para desarrollo y demo: no corre en producción';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Este comando es solo para desarrollo y demo: no se ejecuta en producción.');

            return self::FAILURE;
        }

        $rol = Role::query()->where('name', 'admin')->first();
        if (! $rol) {
            $this->error('Todavía no hay roles: corre primero php artisan migrate.');

            return self::FAILURE;
        }

        $correo = mb_strtolower(trim($this->argument('correo')));
        $usuario = User::query()->whereRaw('lower(email) = ?', [$correo])->first();
        $datos = ['role_id' => $rol->id, 'name' => 'Admin Rápido', 'email' => $correo, 'password' => $this->argument('contrasena'), 'status' => 'active'];
        $usuario ? $usuario->update($datos) : $usuario = User::create($datos);
        UserProfile::query()->firstOrCreate(['user_id' => $usuario->id], ['first_name' => 'Admin', 'last_name' => 'Rápido']);

        $this->info("Administrador listo en la base «{$usuario->getConnection()->getDatabaseName()}»: {$correo}");

        return self::SUCCESS;
    }
}
