<?php

namespace App\Services\Pagos;

use RuntimeException;

/** La pasarela de pagos (Stripe) no pudo atender una petición: sin conexión, rechazo o un error de su lado. */
class ErrorDePasarela extends RuntimeException {}
