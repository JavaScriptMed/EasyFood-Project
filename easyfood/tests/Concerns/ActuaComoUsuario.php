<?php

namespace Tests\Concerns;

use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/** Usuarios de cada rol y de cada portal para probar la API v1 con token de Sanctum. Requiere ArmaEscenarios y VerificaRestricciones. */
trait ActuaComoUsuario
{
    private const ROLES_API = ['customer' => 1, 'admin' => 2, 'driver' => 3, 'provider' => 4, 'support' => 5, 'company' => 6, 'employee' => 7];

    protected function usuarioDeRol(string $rol, array $extra = []): User
    {
        foreach (self::ROLES_API as $nombre => $id) {
            DB::table('roles')->insertOrIgnore(['id' => $id, 'name' => $nombre, 'display_name' => ucfirst($nombre)]);
        }
        $id = DB::table('users')->insertGetId($extra + ['name' => ucfirst($rol).' '.uniqid(), 'email' => uniqid($rol, true).'@ejemplo.mx', 'password' => 'x', 'role_id' => self::ROLES_API[$rol]]);

        return User::findOrFail($id);
    }

    /** Inicia sesión (token de Sanctum) como un usuario nuevo de ese rol. */
    protected function comoRol(string $rol): User
    {
        $usuario = $this->usuarioDeRol($rol);
        Sanctum::actingAs($usuario);

        return $usuario;
    }

    /** Un contacto de RR. HH. con su empresa (y contrato) ya con sesión iniciada. */
    protected function comoEmpresa(string $zona = 'Zapopan', array $contrato = []): Empresa
    {
        $usuario = $this->usuarioDeRol('company');
        $empresa = $this->empresaConContrato($zona, $contrato);
        $empresa->update(['usuario_id' => $usuario->id]);
        Sanctum::actingAs($usuario);

        return $empresa->refresh();
    }

    protected function comoComedor(): Provider
    {
        $usuario = $this->usuarioDeRol('provider');
        $comedor = $this->comedorConContrato();
        $comedor->update(['user_id' => $usuario->id]);
        Sanctum::actingAs($usuario);

        return $comedor->refresh();
    }

    protected function comoEmpleado(?Empresa $empresa = null): Empleado
    {
        $usuario = $this->usuarioDeRol('employee');
        $empleado = Empleado::create(['usuario_id' => $usuario->id, 'empresa_id' => ($empresa ?? $this->empresaConContrato())->id]);
        Sanctum::actingAs($usuario);

        return $empleado;
    }
}
