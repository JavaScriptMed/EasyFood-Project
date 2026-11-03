<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * Listado paginado de usuarios con rol y perfil, con filtros opcionales.
     */
    public function index(Request $request)
    {
        $query = User::with(['role:id,name,display_name', 'profile']);

        // Filtro por rol (ej. ?role=customer, ?role=driver)
        if ($request->filled('role')) {
            $roleName = $request->query('role');
            $query->whereHas('role', function ($q) use ($roleName) {
                $q->where('name', $roleName);
            });
        }

        // Filtro por estatus (?status=active, ?status=suspended)
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        // Búsqueda por nombre o correo
        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('email', 'ilike', "%{$search}%");
            });
        }

        $users = $query->orderBy('id', 'desc')->paginate(15);

        return response()->json($users, 200);
    }

    /**
     * Creación de un usuario desde el panel de admin con asignación explícita de rol.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'role_id'    => 'required|exists:roles,id',
            'name'       => 'required|string|max:120',
            'email'      => 'required|email|max:150|unique:users,email',
            'password'   => 'required|string|min:8',
            'status'     => 'nullable|string|in:active,suspended,pending',
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'phone'      => 'nullable|string|max:20',
        ]);

        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'role_id'  => $validated['role_id'],
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'password' => Hash::make($validated['password']),
                'status'   => $validated['status'] ?? 'active',
            ]);

            UserProfile::create([
                'user_id'    => $user->id,
                'first_name' => $validated['first_name'],
                'last_name'  => $validated['last_name'],
                'phone'      => $validated['phone'] ?? null,
            ]);

            return $user;
        });

        return response()->json([
            'message' => 'Usuario creado exitosamente.',
            'user'    => $user->load(['role:id,name,display_name', 'profile']),
        ], 201);
    }

    /**
     * Consulta individual de cualquier usuario por su ID.
     */
    public function show(string $id)
    {
        $user = User::with(['role:id,name,display_name', 'profile'])->find($id);

        if (! $user) {
            return response()->json([
                'message' => 'Usuario no encontrado.',
            ], 404);
        }

        return response()->json($user, 200);
    }

    /**
     * Actualización de datos de cuenta y perfil.
     */
    public function update(Request $request, string $id)
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'message' => 'Usuario no encontrado.',
            ], 404);
        }

        $validated = $request->validate([
            'role_id'    => 'sometimes|required|exists:roles,id',
            'name'       => 'sometimes|required|string|max:120',
            'email'      => ['sometimes', 'required', 'email', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'password'   => 'nullable|string|min:8',
            'status'     => 'sometimes|required|string|in:active,suspended,pending',
            'first_name' => 'sometimes|required|string|max:100',
            'last_name'  => 'sometimes|required|string|max:100',
            'phone'      => 'nullable|string|max:20',
        ]);

        DB::transaction(function () use ($user, $validated) {
            // Actualizar credenciales / cuenta
            $userData = [];
            if (isset($validated['role_id']))  $userData['role_id'] = $validated['role_id'];
            if (isset($validated['name']))     $userData['name'] = $validated['name'];
            if (isset($validated['email']))    $userData['email'] = $validated['email'];
            if (!empty($validated['password'])) $userData['password'] = Hash::make($validated['password']);
            if (isset($validated['status']))   $userData['status'] = $validated['status'];

            if (!empty($userData)) {
                $user->update($userData);
            }

            // Actualizar o crear perfil asociado
            $profileData = [];
            if (isset($validated['first_name'])) $profileData['first_name'] = $validated['first_name'];
            if (isset($validated['last_name']))  $profileData['last_name'] = $validated['last_name'];
            if (array_key_exists('phone', $validated)) $profileData['phone'] = $validated['phone'];

            if (!empty($profileData)) {
                $user->profile()->updateOrCreate(['user_id' => $user->id], $profileData);
            }
        });

        return response()->json([
            'message' => 'Usuario actualizado correctamente.',
            'user'    => $user->fresh()->load(['role:id,name,display_name', 'profile']),
        ], 200);
    }

    /**
     * Eliminación de la cuenta de usuario.
     */
    public function destroy(Request $request, string $id)
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'message' => 'Usuario no encontrado.',
            ], 404);
        }

        // Impedir que el administrador se borre a sí mismo por accidente
        if ($user->id === $request->user()->id) {
            return response()->json([
                'message' => 'No puedes eliminar tu propia cuenta en sesión.',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'message' => 'Usuario eliminado exitosamente.',
        ], 200);
    }
}