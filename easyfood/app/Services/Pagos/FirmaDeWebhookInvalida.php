<?php

namespace App\Services\Pagos;

use RuntimeException;

/** La firma del webhook no es válida (o falta): el aviso no viene de la pasarela. */
class FirmaDeWebhookInvalida extends RuntimeException {}
