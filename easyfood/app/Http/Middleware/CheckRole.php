<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{

public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Tu cuenta se encuentra inactiva o suspendida.',
            ], 403);
        }

        if (! in_array($user->role->name, $roles, true)) {
            return response()->json([
                'message' => 'Acceso denegado: No cuentas con los permisos requeridos.',
                'user_role' => $user->role->name,
                'required_roles' => $roles,
            ], 403);
        }

        return $next($request);
    }
}