<?php

namespace App\Support\Api;

use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\Invitacion;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * La organización de quien hace la petición: cada portal solo ve lo de su empresa, su comedor o su propio registro.
 */
final class ContextoDelUsuario
{
    public static function empresa(Request $request): Empresa
    {
        return self::empresaDe($request->user()) ?? throw new HttpException(403, 'Tu cuenta no está asociada a ninguna empresa.');
    }

    public static function comedor(Request $request): Provider
    {
        return self::comedorDe($request->user()) ?? throw new HttpException(403, 'Tu cuenta no está asociada a ningún comedor.');
    }

    /** La empresa de una persona: es la titular, o aceptó la invitación de alguien de RR. HH. de esa empresa. */
    public static function empresaDe(User $usuario): ?Empresa
    {
        return Empresa::query()->where('usuario_id', $usuario->id)->first()
            ?? Empresa::query()->find(self::invitacionAceptada($usuario, 'empresa_id'));
    }

    /** El comedor de una persona: es la titular, o aceptó la invitación de alguien de ese comedor. */
    public static function comedorDe(User $usuario): ?Provider
    {
        return Provider::query()->where('user_id', $usuario->id)->first()
            ?? Provider::query()->find(self::invitacionAceptada($usuario, 'comedor_id'));
    }

    private static function invitacionAceptada(User $usuario, string $columna): ?int
    {
        return Invitacion::query()->where('usuario_id', $usuario->id)->whereNotNull('aceptada_en')->whereNotNull($columna)->orderByDesc('id')->value($columna);
    }

    public static function empleado(Request $request): Empleado
    {
        return Empleado::query()->where('usuario_id', $request->user()->id)->first()
            ?? throw new HttpException(403, 'Tu cuenta no está asociada a ninguna empresa como empleado.');
    }
}
