<?php

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
|
| Orígenes que pueden llamar a la API desde el navegador. En desarrollo es el
| front de Next.js (http://localhost:3000). En producción define
| CORS_ALLOWED_ORIGINS en el .env con el dominio real del front.
|
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:3000')))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 600,

    // La API autentica con tokens Bearer (Authorization), que no necesitan credenciales de navegador.
    // Actívalo solo si el front pasa a usar Sanctum con cookies (CORS_SUPPORTS_CREDENTIALS=true).
    'supports_credentials' => filter_var(env('CORS_SUPPORTS_CREDENTIALS', false), FILTER_VALIDATE_BOOL),

];
