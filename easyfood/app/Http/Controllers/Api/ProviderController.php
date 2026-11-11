<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProviderController extends Controller
{
    /**
     * Listado paginado de comedores  con filtros .
     */
    public function index(Request $request)
    {
        $query = Provider::with('user:id,name,email');

        // Filtro por término de búsqueda (nombre, RFC)
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('business_name', 'ilike', "%{$search}%")
                  ->orWhere('commercial_name', 'ilike', "%{$search}%")
                  ->orWhere('rfc', 'ilike', "%{$search}%");
            });
        }

        // Filtro por estatus operativo
        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        // Si se solicita incluir elementos eliminados lógicamente (solo para admin)
        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        $providers = $query->orderBy('id', 'desc')->paginate(15);

        return response()->json($providers, 200);
    }

    /**
     * Registro de un nuevo comedor/proveedor.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id'         => 'nullable|exists:users,id',
            'business_name'   => 'required|string|max:150',
            'commercial_name' => 'nullable|string|max:150',
            'rfc'             => 'nullable|string|max:13|unique:providers,rfc',
            'phone'           => 'required|string|max:20',
            'email'           => 'nullable|email|max:150',
            'address'         => 'required|string',
            'postal_code'     => 'nullable|string|max:10',
            'zona'            => 'nullable|string|max:100',
            'latitude'        => 'nullable|numeric|between:-90,90',
            'longitude'       => 'nullable|numeric|between:-180,180',
            'daily_capacity'  => 'nullable|integer|min:1',
            'is_active'       => 'nullable|boolean',
        ]);

        $provider = Provider::create($validated);

        return response()->json([
            'message'  => 'Proveedor registrado con éxito.',
            'provider' => $provider->load('user:id,name,email'),
        ], 201);
    }

    /**
     * Consulta individual de un comedor por ID.
     */
    public function show(string $id)
    {
        $provider = Provider::with('user:id,name,email')->find($id);

        if (! $provider) {
            return response()->json([
                'message' => 'Proveedor no encontrado.',
            ], 404);
        }

        return response()->json($provider, 200);
    }

    /**
     * Modificación parcial o total de datos del comedor.
     */
    public function update(Request $request, string $id)
    {
        $provider = Provider::find($id);

        if (! $provider) {
            return response()->json([
                'message' => 'Proveedor no encontrado.',
            ], 404);
        }

        $validated = $request->validate([
            'user_id'         => 'nullable|exists:users,id',
            'business_name'   => 'sometimes|required|string|max:150',
            'commercial_name' => 'nullable|string|max:150',
            'rfc'             => ['nullable', 'string', 'max:13', Rule::unique('providers', 'rfc')->ignore($provider->id)],
            'phone'           => 'sometimes|required|string|max:20',
            'email'           => 'nullable|email|max:150',
            'address'         => 'sometimes|required|string',
            'postal_code'     => 'nullable|string|max:10',
            'zona'            => 'nullable|string|max:100',
            'latitude'        => 'nullable|numeric|between:-90,90',
            'longitude'       => 'nullable|numeric|between:-180,180',
            'daily_capacity'  => 'sometimes|integer|min:1',
            'is_active'       => 'sometimes|boolean',
        ]);

        $provider->update($validated);

        return response()->json([
            'message'  => 'Datos del proveedor actualizados.',
            'provider' => $provider->fresh()->load('user:id,name,email'),
        ], 200);
    }

    /**
     * Borrado lógico (Soft Delete) del comedor.
     */
    public function destroy(string $id)
    {
        $provider = Provider::find($id);

        if (! $provider) {
            return response()->json([
                'message' => 'Proveedor no encontrado o ya eliminado.',
            ], 404);
        }

        $provider->delete();

        return response()->json([
            'message' => 'Proveedor desactivado y marcado como eliminado lógicamente.',
        ], 200);
    }

    /**
     * Restauración de un comedor eliminado lógicamente.
     */
    public function restore(string $id)
    {
        $provider = Provider::withTrashed()->find($id);

        if (! $provider || ! $provider->trashed()) {
            return response()->json([
                'message' => 'El proveedor no está eliminado o no existe.',
            ], 404);
        }

        $provider->restore();

        return response()->json([
            'message'  => 'Proveedor restaurado exitosamente.',
            'provider' => $provider,
        ], 200);
    }
}