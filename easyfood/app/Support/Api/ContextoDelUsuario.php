<?php

namespace App\Support\Api;

use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\Provider;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * La organización de quien hace la petición: cada portal solo ve lo de su empresa, su comedor o su propio registro.
 */
final class ContextoDelUsuario
{
    public static function empresa(Request $request): Empresa
    {
        return Empresa::query()->where('usuario_id', $request->user()->id)->first()
            ?? throw new HttpException(403, 'Tu cuenta no está asociada a ninguna empresa.');
    }

    public static function comedor(Request $request): Provider
    {
        return Provider::query()->where('user_id', $request->user()->id)->first()
            ?? throw new HttpException(403, 'Tu cuenta no está asociada a ningún comedor.');
    }

    public static function empleado(Request $request): Empleado
    {
        return Empleado::query()->where('usuario_id', $request->user()->id)->first()
            ?? throw new HttpException(403, 'Tu cuenta no está asociada a ninguna empresa como empleado.');
    }
}
