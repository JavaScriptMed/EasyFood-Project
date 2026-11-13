<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:120',
            'email'      => 'required|string|email|max:150|unique:users,email',
            'password'   => 'required|string|min:8',
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'phone'      => 'nullable|string|max:20',
        ]);

        return DB::transaction(function () use ($validated) {
            $user = User::create([
                'role_id'  => 1,
                'name'     => $validated['name'],
                'email'    => $validated['email'],
                'password' => Hash::make($validated['password']),
                'status'   => 'active',
            ]);

            UserProfile::create([
                'user_id'    => $user->id,
                'first_name' => $validated['first_name'],
                'last_name'  => $validated['last_name'],
                'phone'      => $validated['phone'] ?? null,
            ]);

            $user->load(['role', 'profile']);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'message' => 'Usuario registrado exitosamente.',
                'token'   => $token,
                'user'    => [
                    'id'      => $user->id,
                    'name'    => $user->name,
                    'email'   => $user->email,
                    'role'    => $user->role->name,
                    'status'  => $user->status,
                    'profile' => $user->profile,
                ],
            ], 201);
        });
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::with(['role', 'profile'])->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'message' => 'Esta cuenta se encuentra inactiva o suspendida.',
            ], 403);
        }

        // Token único para el cliente
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Inicio de sesión exitoso.',
            'token'   => $token,
            'user'    => [
                'id'      => $user->id,
                'name'    => $user->name,
                'email'   => $user->email,
                'role'    => $user->role->name,
                'status'  => $user->status,
                'profile' => $user->profile,
            ],
        ], 200);
    }

    public function profile(Request $request)
    {
        $user = $request->user()->load(['role', 'profile']);

        return response()->json([
            'user' => [
                'id'      => $user->id,
                'name'    => $user->name,
                'email'   => $user->email,
                'role'    => $user->role->name,
                'status'  => $user->status,
                'profile' => $user->profile,
            ],
        ], 200);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Sesión cerrada exitosamente.',
        ], 200);
    }

    /**
     * Actualiza los datos del usuario y su perfil asociado.
     */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        // Validar datos recibidos
        $validated = $request->validate([
            'name'       => 'sometimes|required|string|max:120',
            'first_name' => 'sometimes|required|string|max:100',
            'last_name'  => 'sometimes|required|string|max:100',
            'phone'      => 'nullable|string|max:20',
            'avatar_url' => 'nullable|url|max:255',
            'bio'        => 'nullable|string',
            'birth_date' => 'nullable|date',
        ]);

        // Si se envía el campo 'name', se actualiza en la tabla 'users'
        if (isset($validated['name'])) {
            $user->update(['name' => $validated['name']]);
        }

        // Extraer campos pertenecientes a la tabla 'user_profiles'
        $profileData = collect($validated)->only([
            'first_name',
            'last_name',
            'phone',
            'avatar_url',
            'bio',
            'birth_date',
        ])->toArray();

        // Actualizar o crear la información extendida en 'user_profiles'
        if (!empty($profileData)) {
            UserProfile::updateOrCreate(
                ['user_id' => $user->id],
                $profileData
            );
        }

        return response()->json([
            'message' => 'Perfil actualizado exitosamente.',
            'user'    => [
                'id'      => $user->id,
                'name'    => $user->name,
                'email'   => $user->email,
                'role'    => $user->role->name,
                'status'  => $user->status,
                'profile' => $user->fresh()->profile,
            ],
        ], 200);
    }
}