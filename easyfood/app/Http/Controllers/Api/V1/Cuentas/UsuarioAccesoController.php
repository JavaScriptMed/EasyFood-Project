<?php

namespace App\Http\Controllers\Api\V1\Cuentas;

use App\Http\Controllers\Controller;
use App\Services\EdicionDeRegistros;
use App\Services\Invitaciones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsuarioAccesoController extends Controller
{
    /** Quién tiene acceso a una organización: su titular y las personas invitadas, con el estado de cada invitación. */
    public function index(Request $request, Invitaciones $invitaciones): JsonResponse
    {
        $d = $request->validate(['tipo' => 'required|in:comedor,empresa,plataforma', 'id' => 'nullable|integer|required_unless:tipo,plataforma']);

        return response()->json($invitaciones->accesoDe($request->user(), $d['tipo'], $d['tipo'] === 'plataforma' ? null : (int) $d['id']));
    }

    /** Cambia el nombre y el rol de una persona con acceso (el titular no cambia de rol; nadie cambia el suyo). */
    public function update(Request $request, int $id, EdicionDeRegistros $ediciones): JsonResponse
    {
        $d = $request->validate(['nombre' => 'required|string|min:2|max:120', 'rol' => 'required|in:administrador,soporte,empresa,comedor', 'correo' => 'nullable|email|max:190']);

        return response()->json($ediciones->editarUsuarioDeAcceso($request->user(), $id, $d['nombre'], $d['rol'], $d['correo'] ?? null));
    }

    /** Desactiva o reactiva a una persona con acceso. Al desactivarla se cierran sus sesiones. */
    public function estado(Request $request, int $id, EdicionDeRegistros $ediciones): JsonResponse
    {
        $activo = $request->validate(['activo' => 'required|boolean'])['activo'];

        return response()->json($ediciones->cambiarEstadoDeUsuarioDeAcceso($request->user(), $id, $activo));
    }
}
