<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\Provider;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    /**
     * Listado público o autenticado con filtros (por comedor, categoría o disponibilidad).
     */
    public function index(Request $request)
    {
        $query = Menu::with([
            'provider:id,business_name,commercial_name,phone',
            'category:id,name'
        ]);

        // Filtro por comedor
        if ($request->filled('provider_id')) {
            $query->where('provider_id', $request->query('provider_id'));
        }

        // Filtro por categoría
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->query('category_id'));
        }

        // Filtro por disponibilidad
        if ($request->has('is_available')) {
            $query->where('is_available', filter_var($request->query('is_available'), FILTER_VALIDATE_BOOLEAN));
        }

        // Búsqueda por nombre o descripción
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        $menus = $query->orderBy('id', 'desc')->paginate(15);

        return response()->json($menus, 200);
    }

    /**
     * Registro de un nuevo menú/platillo (Solo admin o proveedor).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'provider_id'    => 'required|exists:providers,id',
            'category_id'    => 'nullable|exists:catalog_categories,id',
            'name'           => 'required|string|max:150',
            'description'    => 'nullable|string',
            'price'          => 'required|numeric|min:0',
            'image_url'      => 'nullable|url|max:255',
            'daily_stock'    => 'nullable|integer|min:0',
            'is_available'   => 'nullable|boolean',
            'available_days' => 'nullable|array',
            'available_days.*' => 'string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
        ]);

        // Si quien crea es un proveedor, validar que el comedor le pertenezca
        if ($request->user()->role->name === 'provider') {
            $provider = Provider::where('id', $validated['provider_id'])
                                ->where('user_id', $request->user()->id)
                                ->first();

            if (! $provider) {
                return response()->json([
                    'message' => 'No tienes autorización para agregar platillos a este comedor.',
                ], 403);
            }
        }

        $menu = Menu::create($validated);

        return response()->json([
            'message' => 'Menú registrado con éxito.',
            'menu'    => $menu->load(['provider:id,commercial_name', 'category:id,name']),
        ], 201);
    }

    /**
     * Consulta individual de un platillo por ID.
     */
    public function show(string $id)
    {
        $menu = Menu::with([
            'provider:id,business_name,commercial_name,phone,address',
            'category:id,name'
        ])->find($id);

        if (! $menu) {
            return response()->json([
                'message' => 'Platillo/Menú no encontrado.',
            ], 404);
        }

        return response()->json($menu, 200);
    }

    /**
     * Modificación de platillo.
     */
    public function update(Request $request, string $id)
    {
        $menu = Menu::find($id);

        if (! $menu) {
            return response()->json([
                'message' => 'Platillo no encontrado.',
            ], 404);
        }

        // Restricción de pertenencia para rol provider
        if ($request->user()->role->name === 'provider' && $menu->provider->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'No puedes modificar platillos de otro comedor.',
            ], 403);
        }

        $validated = $request->validate([
            'category_id'    => 'nullable|exists:catalog_categories,id',
            'name'           => 'sometimes|required|string|max:150',
            'description'    => 'nullable|string',
            'price'          => 'sometimes|required|numeric|min:0',
            'image_url'      => 'nullable|url|max:255',
            'daily_stock'    => 'sometimes|integer|min:0',
            'is_available'   => 'sometimes|boolean',
            'available_days' => 'nullable|array',
            'available_days.*' => 'string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
        ]);

        $menu->update($validated);

        return response()->json([
            'message' => 'Platillo actualizado exitosamente.',
            'menu'    => $menu->fresh()->load(['provider:id,commercial_name', 'category:id,name']),
        ], 200);
    }

    /**
     * Borrado lógico (Soft delete).
     */
    public function destroy(Request $request, string $id)
    {
        $menu = Menu::find($id);

        if (! $menu) {
            return response()->json([
                'message' => 'Platillo no encontrado o ya eliminado.',
            ], 404);
        }

        if ($request->user()->role->name === 'provider' && $menu->provider->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'No tienes permisos para eliminar este platillo.',
            ], 403);
        }

        $menu->delete();

        return response()->json([
            'message' => 'Platillo eliminado correctamente (borrado lógico).',
        ], 200);
    }
}