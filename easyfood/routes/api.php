<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\V1\SaludController;

// -----------------------------------------------------------------
// API v1 (nombres de rutas en español, ver CLAUDE.md)
// -----------------------------------------------------------------
Route::prefix('v1')->group(function () {
    Route::get('/salud', SaludController::class)->name('v1.salud');
});

// -----------------------------------------------------------------
// Rutas Públicas (Sin Token)
// -----------------------------------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// -----------------------------------------------------------------
// Rutas Protegidas (Requieren Header: Authorization: Bearer <token>)
// -----------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {

    // Gestión de sesión propia
    Route::get('/auth/profile', [AuthController::class, 'profile']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // -------------------------------------------------------------
    // Rutas protegidas por Rol (Middleware: role:nombre_del_rol)
    // -------------------------------------------------------------

    // Solo Administradores
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/dashboard', function () {
            return response()->json(['message' => 'Bienvenido al panel web administrativo de EasyFood.']);
        });
    });

    // Solo Repartidores
    Route::middleware('role:driver')->prefix('driver')->group(function () {
        Route::get('/routes', function () {
            return response()->json(['message' => 'Listado de entregas y coordenadas de entrega asignadas.']);
        });
    });

    // Solo Proveedores (Comedores / Cocinas centrales)
    Route::middleware('role:provider')->prefix('provider')->group(function () {
        Route::get('/orders-summary', function () {
            return response()->json(['message' => 'Consolidado de porciones a cocinar.']);
        });
    });
});