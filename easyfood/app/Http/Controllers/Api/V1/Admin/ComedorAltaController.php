<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AltaDeCuentas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComedorAltaController extends Controller
{
    /**
     * Da de alta un comedor: su contacto (que recibe la invitación), el comedor y su contrato de 6 meses.
     * Responde el comedor ya armado, como `GET /admin/comedores/{id}`.
     */
    public function store(Request $request, AltaDeCuentas $altas): JsonResponse
    {
        $d = $request->validate([
            'nombre' => 'required|string|min:2|max:150', 'razon_social' => 'nullable|string|max:150', 'rfc' => ['nullable', 'string', 'regex:/^[A-Za-zÑñ&]{3,4}\d{6}[A-Za-z0-9]{3}$/'],
            'contacto_nombre' => 'required|string|min:2|max:120', 'contacto_correo' => 'required|email|max:190', 'telefono' => 'required|string|min:7|max:20',
            'direccion' => 'required|string|min:3|max:500', 'codigo_postal' => 'nullable|string|max:10', 'zona' => 'required|string|max:100',
            'capacidad_diaria' => 'nullable|integer|min:1|max:100000', 'vigencia_inicio' => 'required|date_format:Y-m-d',
            'precio_por_platillo' => 'required|numeric|gt:0|max:100000', 'compra_minima_mensual' => 'required|integer|min:1|max:1000000',
        ]);

        $comedor = $altas->crearComedor($request->user(), $d);

        return response()->json(ComedorController::detallePorId($comedor->id), 201);
    }
}
