<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\AltaDeCuentas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmpresaAltaController extends Controller
{
    /**
     * Da de alta una empresa: su contacto de RR. HH. (que recibe la invitación), la empresa y su contrato de 6 meses.
     * Responde la empresa ya armada, como `GET /admin/empresas/{id}`.
     */
    public function store(Request $request, AltaDeCuentas $altas): JsonResponse
    {
        $d = $request->validate([
            'nombre' => 'required|string|min:2|max:150', 'contacto_nombre' => 'required|string|min:2|max:120', 'contacto_correo' => 'required|email|max:190',
            'contacto_telefono' => 'nullable|string|max:20', 'direccion_entrega' => 'required|string|min:3|max:255', 'zona' => 'required|string|max:100',
            'vigencia_inicio' => 'required|date_format:Y-m-d', 'compra_minima_mensual' => 'required|integer|min:1|max:1000000', 'porcentaje_subsidio' => 'required|numeric|between:0,100',
            'precio_por_platillo' => 'nullable|numeric|min:1|max:100000', 'ventana_entrega_inicio' => 'required|date_format:H:i', 'ventana_entrega_fin' => 'required|date_format:H:i|after:ventana_entrega_inicio',
            'hora_entrega' => 'nullable|date_format:H:i',
        ]);

        $empresa = $altas->crearEmpresa($request->user(), $d);

        return response()->json(EmpresaController::detallePorId($empresa->id), 201);
    }

    /**
     * El administrador agrega empleados a una empresa (uno por uno o desde un CSV), igual que lo hace la propia empresa desde su portal.
     * Cada uno recibe una invitación; un correo que ya tiene cuenta o repetido en la lista se omite y se dice cuál y por qué.
     */
    public function empleados(Request $request, int $id, AltaDeCuentas $altas): JsonResponse
    {
        $empresa = Empresa::query()->findOrFail($id);
        $d = $request->validate(['personas' => 'required|array|min:1|max:500', 'personas.*.nombre' => 'required|string|min:2|max:120', 'personas.*.correo' => 'nullable|email|max:190']);

        return response()->json($altas->agregarEmpleados($request->user(), $empresa, $d['personas']), 201);
    }
}
