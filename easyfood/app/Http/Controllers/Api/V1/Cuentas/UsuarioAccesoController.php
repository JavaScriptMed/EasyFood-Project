<?php

namespace App\Http\Controllers\Api\V1\Cuentas;

use App\Http\Controllers\Controller;
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
}
