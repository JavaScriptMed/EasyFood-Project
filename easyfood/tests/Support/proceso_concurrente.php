<?php

/**
 * Proceso hijo de las pruebas de concurrencia (tests/Feature/Negocio/ConcurrenciaTest.php). No es una prueba: lo lanza
 * la prueba varias veces a la vez para que compitan de verdad por la misma base de datos.
 *
 * Uso: php tests/Support/proceso_concurrente.php '{"accion": "...", "inicio": 1700000000.5, "ahora": "2026-11-11 18:30", ...}'
 * Imprime un JSON con el resultado. Se niega a correr contra cualquier base que no sea «easylunch_pruebas».
 */

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$args = json_decode($argv[1] ?? '{}', true);

if (DB::connection()->getDatabaseName() !== 'easylunch_pruebas') {
    fwrite(STDERR, "Base de datos equivocada: solo corre contra easylunch_pruebas.\n");
    exit(2);
}

$ahora = Carbon::parse($args['ahora'], 'America/Mexico_City');
Carbon::setTestNow($ahora);
CarbonImmutable::setTestNow($ahora);

// Todos los procesos esperan al mismo instante para arrancar juntos.
time_sleep_until((float) $args['inicio']);

try {
    $salida = match ($args['accion']) {
        'corte' => (function () use ($args) {
            $corte = app(App\Services\EjecutorDelCorte::class)->ejecutar($args['fecha'], $args['usuario'] ?? null);

            return ['id' => $corte->id, 'estado' => $corte->estado, 'sin_cambios' => $corte->sin_cambios, 'mensaje' => $corte->mensaje];
        })(),
        'pedidos' => (function () use ($args) {
            $cliente = App\Models\User::findOrFail($args['cliente_id']);
            $folios = [];
            for ($i = 0; $i < $args['cantidad']; $i++) {
                $folios[] = app(App\Services\ContratacionDePaquetes::class)->pedir(
                    $cliente, App\Models\Paquete::findOrFail($args['paquete_id']), App\Models\Address::findOrFail($args['direccion_id']), [$args['menu_id']],
                )->folio;
            }

            return ['folios' => $folios];
        })(),
        'facturas' => (function () use ($args) {
            $folios = [];
            foreach ($args['empresas'] as $empresaId) {
                $folios[$empresaId] = app(App\Services\GeneradorDeFacturas::class)->generar($empresaId, $args['mes'])->folio;
            }

            return ['folios' => $folios];
        })(),
    };
    echo json_encode($salida);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
