<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\CatalogCategoryController;

Route::middleware('auth:api')->group(function () {

    // Rutas de consulta (Clientes, Repartidores, Proveedores y Admin)
    Route::get('/catalog-categories', [CatalogCategoryController::class, 'index']);
    Route::get('/catalog-categories/{id}', [CatalogCategoryController::class, 'show']);

    // Rutas de administración (Crear, Editar, Eliminar)
    Route::middleware('role:admin')->group(function () {
        Route::post('/catalog-categories', [CatalogCategoryController::class, 'store']);
        Route::put('/catalog-categories/{id}', [CatalogCategoryController::class, 'update']);
        Route::delete('/catalog-categories/{id}', [CatalogCategoryController::class, 'destroy']);
    });
});

Route::middleware('auth:api')->group(function () {

    // Rutas públicas/clientes para explorar comida
    Route::get('/menus', [MenuController::class, 'index']);
    Route::get('/menus/{id}', [MenuController::class, 'show']);

    // Rutas protegidas para administración de platillos (Admin y Proveedor)
    Route::middleware('role:admin,provider')->group(function () {
        Route::post('/menus', [MenuController::class, 'store']);
        Route::put('/menus/{id}', [MenuController::class, 'update']);
        Route::delete('/menus/{id}', [MenuController::class, 'destroy']);
    });
});

Route::middleware(['auth:api', 'role:admin'])->prefix('admin')->group(function () {
    // CRUD administrativo de usuarios
    Route::apiResource('users', AdminUserController::class);
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

    Route::middleware('auth:api')->group(function () {

        // Rutas operativas de proveedores (Administración)
        Route::middleware('role:admin')->prefix('admin')->group(function () {
            Route::post('/providers/{id}/restore', [ProviderController::class, 'restore']);
            Route::apiResource('providers', ProviderController::class);
        });

        // Consulta de proveedores para clientes o usuarios autenticados
        Route::get('/providers', [ProviderController::class, 'index']);
        Route::get('/providers/{id}', [ProviderController::class, 'show']);
    });

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