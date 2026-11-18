<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\Api\ReglasDeContrasena;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CrearAdmin extends Command
{
    protected $signature = 'easylunch:crear-admin {correo : Correo con el que entrará} {--nombre=Administrador : Nombre que se muestra}';

    protected $description = 'Crea un administrador pidiendo la contraseña (no se ve en pantalla ni queda en el historial). Es el primer paso en un servidor nuevo';

    public function handle(): int
    {
        $correo = mb_strtolower(trim($this->argument('correo')));
        if (Validator::make(['correo' => $correo], ['correo' => 'required|email|max:190'])->fails()) {
            $this->error('Ese correo no es válido.');

            return self::FAILURE;
        }
        $rol = Role::query()->where('name', 'admin')->first();
        if (! $rol) {
            $this->error('Todavía no hay roles. Corre primero: php artisan db:seed --class=InitialDataSeeder --force');

            return self::FAILURE;
        }
        if (User::query()->whereRaw('lower(email) = ?', [$correo])->exists()) {
            $this->error('Ya existe una cuenta con ese correo. Si olvidó su contraseña, usa «Olvidé mi contraseña» en la pantalla de acceso.');

            return self::FAILURE;
        }

        $contrasena = (string) $this->secret('Contraseña (mínimo 8 caracteres, con letras y números)');
        $errores = Validator::make(['contrasena' => $contrasena], ['contrasena' => ReglasDeContrasena::REGLAS], ReglasDeContrasena::MENSAJES)->errors();
        if ($errores->isNotEmpty()) {
            $this->error($errores->first());

            return self::FAILURE;
        }
        if ($contrasena !== (string) $this->secret('Repite la contraseña')) {
            $this->error('Las contraseñas no coinciden: no se creó nada.');

            return self::FAILURE;
        }

        $nombre = trim((string) $this->option('nombre')) ?: 'Administrador';
        $usuario = User::create(['role_id' => $rol->id, 'name' => $nombre, 'email' => $correo, 'password' => $contrasena, 'status' => 'active']);
        [$pila, $apellidos] = array_pad(explode(' ', $nombre, 2), 2, '');
        UserProfile::create(['user_id' => $usuario->id, 'first_name' => $pila, 'last_name' => trim($apellidos) ?: $pila]);

        $this->info("Administrador creado: {$correo}. Ya puede entrar por /login.");

        return self::SUCCESS;
    }
}
