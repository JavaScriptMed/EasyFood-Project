<?php

namespace App\Http\Controllers\Api\V1\Empresa;

use App\Http\Controllers\Controller;
use App\Services\AltaDeCuentas;
use App\Support\Api\ContextoDelUsuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmpleadoAltaController extends Controller
{
    /**
     * Agrega empleados a la propia empresa (uno solo o desde un CSV). Cada uno recibe una invitación para crear su contraseña.
     * Un correo que ya tiene cuenta, o repetido en la lista, se omite. Sin correo se arma con el nombre y el dominio de la empresa.
     */
    public function store(Request $request, AltaDeCuentas $altas): JsonResponse
    {
        $d = $request->validate(['personas' => 'required|array|min:1|max:500', 'personas.*.nombre' => 'required|string|min:2|max:120', 'personas.*.correo' => 'nullable|email|max:190']);

        return response()->json($altas->agregarEmpleados($request->user(), ContextoDelUsuario::empresa($request), $d['personas']), 201);
    }
}
