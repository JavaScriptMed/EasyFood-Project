<?php

namespace App\Http\Controllers\Api\V1\Cuentas;

use App\Http\Controllers\Controller;
use App\Services\Invitaciones;
use App\Support\Api\ReglasDeContrasena;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvitacionController extends Controller
{
    /**
     * Invita a una persona por correo a una organización (administración a cualquiera; una empresa o un comedor, a la suya).
     * Crea su cuenta inactiva y le manda el enlace; devuelve la fila de «usuarios con acceso» (sin el enlace).
     */
    public function store(Request $request, Invitaciones $invitaciones): JsonResponse
    {
        $d = $request->validate([
            'organizacion_tipo' => 'required|in:comedor,empresa,plataforma', 'organizacion_id' => 'nullable|integer|required_unless:organizacion_tipo,plataforma',
            'nombre' => 'required|string|min:2|max:120', 'correo' => 'required|email|max:190', 'rol' => 'required|string|max:20',
        ]);

        [$invitacion] = $invitaciones->invitar($request->user(), $d['organizacion_tipo'], $d['organizacion_tipo'] === 'plataforma' ? null : (int) $d['organizacion_id'], $d['nombre'], $d['correo'], $d['rol']);

        return response()->json($invitaciones->fila($invitacion->usuario->load('role'), $invitacion, false, null), 201);
    }

    /** Renueva el enlace y la vigencia de una invitación que no se ha aceptado, y vuelve a mandar el correo. */
    public function reenviar(Request $request, int $id, Invitaciones $invitaciones): JsonResponse
    {
        [$invitacion] = $invitaciones->reenviar($request->user(), $id);

        return response()->json($invitaciones->vista($invitacion));
    }

    /** Público: lo que se ve al abrir el enlace del correo. Un enlace desconocido responde `estado: invalida` (no 404). */
    public function mostrar(string $token, Invitaciones $invitaciones): JsonResponse
    {
        return response()->json($invitaciones->mostrar($token));
    }

    /** Público: la persona elige su contraseña, la cuenta se activa y entra por el portal de su rol. */
    public function aceptar(Request $request, string $token, Invitaciones $invitaciones): JsonResponse
    {
        $d = $request->validate(['contrasena' => ReglasDeContrasena::REGLAS], ReglasDeContrasena::MENSAJES);

        return response()->json($invitaciones->aceptar($token, $d['contrasena']));
    }
}
