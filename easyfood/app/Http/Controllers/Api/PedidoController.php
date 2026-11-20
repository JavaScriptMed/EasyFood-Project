<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Paquete;
use App\Models\Pedido;
use App\Models\Address;
use Illuminate\Http\Request;
use Carbon\Carbon;

class PedidoController extends Controller
{
    // 1. Obtener listado de paquetes activos
    public function indexPaquetes()
    {
        return response()->json(Paquete::where('activo', true)->get(), 200);
    }

    // 2. Obtener los pedidos del usuario autenticado
    public function index(Request $request)
    {
        $pedidos = Pedido::with(['paquete', 'direccion', 'dias.menu'])
            ->where('cliente_id', $request->user()->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($pedidos, 200);
    }

    // 3. Crear un pedido seleccionando paquete, dirección y platillos por tiempo de comida
    public function store(Request $request)
    {
        $validated = $request->validate([
            'paquete_id' => 'required|exists:paquetes,id',
            'direccion_id' => 'required|exists:addresses,id',
            'menu_ids' => 'required|array',
            'menu_ids.*' => 'exists:menus,id',
        ]);

        $paquete = Paquete::findOrFail($validated['paquete_id']);
        
        // Validación: cada día del paquete requiere 3 tiempos (Desayuno, Comida, Cena)
        $platillosEsperados = $paquete->dias * 3;
        if (count($validated['menu_ids']) !== $platillosEsperados) {
            return response()->json([
                'message' => "El paquete '{$paquete->nombre}' requiere exactamente {$platillosEsperados} platillos ({$paquete->dias} días × 3 tiempos)."
            ], 422);
        }

        $direccion = Address::where('id', $validated['direccion_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        // Cálculo financiero
        $precioBasePorPlatillo = 49.00; 
        $subtotal = $precioBasePorPlatillo * count($validated['menu_ids']);
        $descuento = $subtotal * ((float)$paquete->descuento_porcentaje / 100);
        $total = max(0, $subtotal - $descuento);

        $fechaInicio = Carbon::tomorrow();
        $fechaFin = (clone $fechaInicio)->addDays($paquete->dias - 1);

        // Crear el pedido principal
        $pedido = Pedido::create([
            'folio' => 'ORD-' . strtoupper(uniqid()),
            'cliente_id' => $request->user()->id,
            'paquete_id' => $paquete->id,
            'direccion_id' => $direccion->id,
            'fecha_inicio' => $fechaInicio->toDateString(),
            'fecha_fin' => $fechaFin->toDateString(),
            'total' => $total,
            'estado' => 'pendiente_pago',
            'vence_pago_en' => Carbon::now()->addMinutes(15),
        ]);

        // Asociar los platillos seleccionados a los días del pedido
        $menuIndex = 0;
        for ($d = 0; $d < $paquete->dias; $d++) {
            $fechaActual = (clone $fechaInicio)->addDays($d);
            foreach (['desayuno', 'comida', 'cena'] as $tiempo) {
                $pedido->dias()->create([
                    'fecha' => $fechaActual->toDateString(),
                    'tiempo_comida' => $tiempo,
                    'menu_id' => $validated['menu_ids'][$menuIndex++] ?? null,
                ]);
            }
        }

        return response()->json($pedido->load(['paquete', 'direccion', 'dias.menu']), 201);
    }

    // 4. Pagar un pedido pendiente
    public function pagar(Request $request, $id)
    {
        $pedido = Pedido::where('id', $id)
            ->where('cliente_id', $request->user()->id)
            ->firstOrFail();

        if ($pedido->estado !== 'pendiente_pago') {
            return response()->json(['message' => 'Este pedido ya no está pendiente de pago.'], 400);
        }

        $validated = $request->validate([
            'metodo_pago' => 'required|string',
            'ultimos_digitos' => 'required|string|max:4',
        ]);

        $pedido->update([
            'estado' => 'pagado',
            'metodo_pago' => $validated['metodo_pago'],
            'ultimos_digitos' => $validated['ultimos_digitos'],
            'pagado_en' => Carbon::now(),
        ]);

        return response()->json($pedido->load(['paquete', 'direccion']), 200);
    }
}