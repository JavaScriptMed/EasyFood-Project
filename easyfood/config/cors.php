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

    // Necesario si el front usa Sanctum con cookies (modo SPA); con tokens Bearer no estorba.
    'supports_credentials' => true,

];
