<?php

return [
    /*
    | Contraseña común de las cuentas de ejemplo que crea DatosDeEjemploSeeder (solo desarrollo y pruebas).
    | No se escribe en el repositorio: se define en el .env de cada quien (SEED_PASSWORD).
    */
    'contrasena_de_ejemplo' => env('SEED_PASSWORD'),

    /*
    | Cuentas: dónde vive el front (para armar los enlaces de los correos) y cuánto duran los enlaces.
    */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),
    'vigencia_invitacion_dias' => 7,
    'vigencia_restablecimiento_min' => 60,
];
