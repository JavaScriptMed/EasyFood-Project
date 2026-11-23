<?php

namespace App\Http\Controllers\Api\V1\Cuentas;

use App\Http\Controllers\Controller;
use App\Services\RestablecimientoDeContrasena;
use App\Support\Api\ReglasDeContrasena;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Restablecer la contraseña (todo público). */
class RestablecimientoController extends Controller
{
    /** Siempre responde lo mismo, exista o no el correo, para no revelar qué cuentas existen. */
    public function solicitar(Request $request, RestablecimientoDeContrasena $restablecimiento): JsonResponse
    {
        $d = $request->validate(['correo' => 'required|email|max:190']);
        $restablecimiento->solicitar($d['correo']);

        return response()->json(['enviado' => true, 'enlace_simulado' => null]);
    }

    public function validar(string $token, RestablecimientoDeContrasena $restablecimiento): JsonResponse
    {
        return response()->json($restablecimiento->validar($token));
    }

    public function aplicar(Request $request, string $token, RestablecimientoDeContrasena $restablecimiento): JsonResponse
    {
        $d = $request->validate(['contrasena' => ReglasDeContrasena::REGLAS], ReglasDeContrasena::MENSAJES);
        $restablecimiento->aplicar($token, $d['contrasena']);

        return response()->json(['mensaje' => 'Listo: ya puedes entrar con tu contraseña nueva.']);
    }
}
