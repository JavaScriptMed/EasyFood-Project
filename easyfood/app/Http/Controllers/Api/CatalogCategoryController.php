<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogCategory;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatalogCategoryController extends Controller
{
    /**
     * Listado de categorías (con opción de filtrar por estatus activo o búsqueda).
     */
    public function index(Request $request)
    {
        $query = CatalogCategory::query();

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('code', 'ilike', "%{$search}%");
            });
        }

        $categories = $query->orderBy('name', 'asc')->paginate(15);

        return response()->json($categories, 200);
    }

    /**
     * Registro de una nueva categoría de catálogo.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'code'      => 'required|string|max:50|unique:catalog_categories,code',
            'name'      => 'required|string|max:100',
            'is_active' => 'nullable|boolean',
        ]);

        $category = CatalogCategory::create([
            'code'      => strtoupper(trim($validated['code'])),
            'name'      => trim($validated['name']),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'message'  => 'Categoría creada exitosamente.',
            'category' => $category,
        ], 201);
    }

    /**
     * Consulta individual de una categoría por ID.
     */
    public function show(string $id)
    {
        $category = CatalogCategory::withCount('menus')->find($id);

        if (! $category) {
            return response()->json([
                'message' => 'Categoría no encontrada.',
            ], 404);
        }

        return response()->json($category, 200);
    }

    /**
     * Actualización de nombre, código o estatus.
     */
    public function update(Request $request, string $id)
    {
        $category = CatalogCategory::find($id);

        if (! $category) {
            return response()->json([
                'message' => 'Categoría no encontrada.',
            ], 404);
        }

        $validated = $request->validate([
            'code'      => ['sometimes', 'required', 'string', 'max:50', Rule::unique('catalog_categories', 'code')->ignore($category->id)],
            'name'      => 'sometimes|required|string|max:100',
            'is_active' => 'sometimes|boolean',
        ]);

        if (isset($validated['code'])) {
            $validated['code'] = strtoupper(trim($validated['code']));
        }

        $category->update($validated);

        return response()->json([
            'message'  => 'Categoría actualizada exitosamente.',
            'category' => $category,
        ], 200);
    }

    /**
     * Eliminación de categoría.
     */
    public function destroy(string $id)
    {
        $category = CatalogCategory::find($id);

        if (! $category) {
            return response()->json([
                'message' => 'Categoría no encontrada.',
            ], 404);
        }

        // Validación para evitar borrar categorías con menús asociados
        if ($category->menus()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar la categoría porque tiene platillos o menús asociados.',
            ], 422);
        }

        $category->delete();

        return response()->json([
            'message' => 'Categoría eliminada exitosamente.',
        ], 200);
    }
}