<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Exceptions\ReglaDeNegocioException;
use App\Http\Middleware\CheckRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Detrás del proxy del servidor (Nginx/Caddy con HTTPS) la IP del cliente viene en X-Forwarded-For: sin esto, las limitaciones de
        // intentos contarían a TODOS los usuarios como una sola IP. TRUSTED_PROXIES = «*» o una lista de IP separadas por coma.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
        $middleware->alias([
            'role' => CheckRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Respuestas de error de la API v1 en español y con la misma forma: { mensaje, codigo?, errores? }.
        // Solo /api/v1/*: las rutas anteriores (/api/auth, /api/menus…) conservan el formato de Laravel que ya usa su front.
        $esApi = fn (Request $request) => $request->is('api/v1/*');

        $exceptions->render(function (ReglaDeNegocioException $e, Request $request) use ($esApi) {
            return $esApi($request) ? response()->json(['mensaje' => $e->getMessage(), 'codigo' => $e->codigo], 422) : null;
        });
        $exceptions->render(function (ValidationException $e, Request $request) use ($esApi) {
            return $esApi($request) ? response()->json(['mensaje' => $e->getMessage(), 'errores' => $e->errors()], 422) : null;
        });
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($esApi) {
            return $esApi($request) ? response()->json(['mensaje' => 'Necesitas iniciar sesión para continuar.', 'codigo' => 'no_autenticado'], 401) : null;
        });
        $exceptions->render(function (AuthorizationException $e, Request $request) use ($esApi) {
            return $esApi($request) ? response()->json(['mensaje' => 'No tienes permiso para hacer esto.', 'codigo' => 'sin_permiso'], 403) : null;
        });
        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($esApi) {
            return $esApi($request) ? response()->json(['mensaje' => 'No encontramos lo que buscas.', 'codigo' => 'no_encontrado'], 404) : null;
        });
        $exceptions->render(function (HttpException $e, Request $request) use ($esApi) {
            return $esApi($request) && $e->getStatusCode() === 403 && $e->getMessage() !== ''
                ? response()->json(['mensaje' => $e->getMessage(), 'codigo' => 'sin_permiso'], 403) : null;
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($esApi) {
            $mensaje = 'Demasiados intentos: espera un momento y vuelve a intentarlo.';
            if ($esApi($request)) {
                return response()->json(['mensaje' => $mensaje, 'codigo' => 'demasiados_intentos'], 429, $e->getHeaders());
            }

            // El login y el registro (ruta del equipo) responden con la forma de siempre, con su mensaje en español.
            return $request->is('api/auth/*') ? response()->json(['message' => $mensaje, 'mensaje' => $mensaje, 'codigo' => 'demasiados_intentos'], 429, $e->getHeaders()) : null;
        });
    })->create();