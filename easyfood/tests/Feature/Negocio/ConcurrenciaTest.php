<?php

namespace Tests\Feature\Negocio;

use App\Models\Address;
use App\Models\Corte;
use App\Models\Paquete;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Concurrencia de verdad: varios procesos de PHP compiten a la vez por la misma base. Estas pruebas NO usan la
 * transacción que envuelve a las demás (otros procesos no la verían): confirman sus datos y al terminar rehacen la
 * base de pruebas con migrate:fresh. Solo corren contra «easylunch_pruebas».
 */
class ConcurrenciaTest extends TestCase
{
    use ArmaEscenarios;
    use VerificaRestricciones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exigirBaseDePruebas();
        $this->artisan('migrate:fresh')->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        $this->exigirBaseDePruebas();
        $this->artisan('migrate:fresh')->assertExitCode(0);
        parent::tearDown();
    }

    private function exigirBaseDePruebas(): void
    {
        $base = DB::connection()->getDatabaseName();
        if ($base !== 'easylunch_pruebas') {
            $this->fail("Estas pruebas rehacen la base y solo pueden correr contra easylunch_pruebas (es «{$base}»).");
        }
    }

    /**
     * Lanza un proceso hijo por cada trabajo y los hace arrancar en el mismo instante.
     *
     * @param  array<int, array<string, mixed>>  $trabajos
     * @return array<int, array<string, mixed>> la salida JSON de cada uno
     */
    private function enParalelo(array $trabajos, string $ahora): array
    {
        $inicio = microtime(true) + 3.0;
        $procesos = [];
        foreach ($trabajos as $trabajo) {
            $proceso = new Process(
                [PHP_BINARY, base_path('tests/Support/proceso_concurrente.php'), json_encode($trabajo + ['inicio' => $inicio, 'ahora' => $ahora])],
                base_path(),
                ['DB_DATABASE' => 'easylunch_pruebas', 'APP_ENV' => 'testing'],
            );
            $proceso->setTimeout(60)->start();
            $procesos[] = $proceso;
        }

        $salidas = [];
        foreach ($procesos as $proceso) {
            $proceso->wait();
            $this->assertSame(0, $proceso->getExitCode(), "Un proceso hijo falló: {$proceso->getErrorOutput()}{$proceso->getOutput()}");
            $salidas[] = json_decode($proceso->getOutput(), true);
        }

        return $salidas;
    }

    public function test_cuatro_cortes_a_la_vez_del_mismo_dia_cierran_una_sola_vez(): void
    {
        $menuA = $this->platillo();
        $menuB = $this->platillo();
        $this->disponible($menuA, '2026-11-12');
        $this->disponible($menuB, '2026-11-12');
        foreach (range(1, 3) as $i) {
            $empresa = $this->empresaConContrato('Zapopan');
            foreach ([$menuA, $menuA, $menuB] as $menu) {
                DB::table('elecciones_empleado')->insert([
                    'empleado_id' => $this->empleadoDe($empresa)->id, 'fecha' => '2026-11-12', 'tiempo_comida' => 'comida', 'menu_id' => $menu->id,
                    'precio_congelado' => 130, 'porcentaje_subsidio_congelado' => 60, 'origen' => 'manual',
                ]);
            }
        }
        $this->repartidorEn('Zapopan');
        $usuario = $this->usuario();
        $trabajo = ['accion' => 'corte', 'fecha' => '2026-11-12'];

        $salidas = $this->enParalelo([$trabajo, $trabajo + ['usuario' => $usuario], $trabajo, $trabajo + ['usuario' => $usuario]], '2026-11-11 18:30');

        $this->assertCount(4, $salidas);
        $this->assertSame(0, Corte::where('estado', 'fallido')->count(), 'ninguno falló: '.json_encode(Corte::where('estado', 'fallido')->pluck('mensaje')));
        $this->assertSame(1, Corte::where('sin_cambios', false)->count(), 'solo uno cierra el día de verdad');
        $this->assertSame(3, Corte::where('sin_cambios', true)->count(), 'los demás quedan como «sin cambios»');
        $this->assertSame(1, collect($salidas)->where('sin_cambios', false)->count());

        $this->assertSame(0, DB::table('elecciones_empleado')->whereNull('cerrada_en')->count());
        $this->assertSame(9, DB::table('elecciones_empleado')->count(), 'nadie duplicó elecciones');
        $this->assertSame([6, 3], DB::table('ordenes_produccion')->orderBy('menu_id')->pluck('porciones_totales')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(3, DB::table('entregas_empresa')->count());
        $this->assertSame(1, DB::table('rutas')->count());
        $this->assertSame(3, DB::table('paradas')->count());
    }

    public function test_pedidos_simultaneos_nunca_repiten_el_consecutivo_del_folio(): void
    {
        $this->zonaDePruebas();
        $cliente = User::findOrFail($this->usuario());
        $direccion = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. 1', 'latitude' => 20.67, 'longitude' => -103.35]);
        $paquete = Paquete::create(['nombre' => 'Un día', 'dias' => 1]);
        // Cada proceso pide un platillo distinto: así no se turnan por la fila de porciones y lo único que comparten es el folio.
        $trabajos = [];
        foreach (range(1, 3) as $i) {
            $menu = $this->platillo();
            $this->disponible($menu, '2026-11-10', 100);
            $trabajos[] = ['accion' => 'pedidos', 'cliente_id' => $cliente->id, 'direccion_id' => $direccion->id, 'paquete_id' => $paquete->id, 'menu_id' => $menu->id, 'cantidad' => 6];
        }

        $salidas = $this->enParalelo($trabajos, '2026-11-09 10:00');

        $folios = collect($salidas)->pluck('folios')->flatten()->sort()->values()->all();
        $esperados = array_map(fn ($n) => sprintf('GDL-20261109-%04d', $n), range(1, 18));
        $this->assertSame($esperados, $folios, 'los 18 folios son únicos y consecutivos, sin huecos');
        $this->assertSame(18, DB::table('pedidos')->count());
        $this->assertSame(18, (int) DB::table('disponibilidad_dia')->sum('porciones_tomadas'));
    }

    public function test_facturas_simultaneas_nunca_repiten_el_consecutivo_ni_duplican_una_factura(): void
    {
        $empresas = [];
        foreach (range(1, 5) as $i) {
            $empresas[] = $this->empresaConContrato()->id;
        }
        [$e1, $e2, $e3, $e4, $e5] = $empresas;
        // Tres procesos; la empresa 3 la piden dos a la vez, y la 1 y la 5 también se repiten.
        $trabajos = [
            ['accion' => 'facturas', 'mes' => '2026-10-01', 'empresas' => [$e1, $e2, $e3]],
            ['accion' => 'facturas', 'mes' => '2026-10-01', 'empresas' => [$e3, $e4, $e5]],
            ['accion' => 'facturas', 'mes' => '2026-10-01', 'empresas' => [$e5, $e1]],
        ];

        $salidas = $this->enParalelo($trabajos, '2026-11-05 09:00');

        $this->assertSame(5, DB::table('facturas_mensuales')->count(), 'una factura por empresa y mes');
        $folios = DB::table('facturas_mensuales')->orderBy('folio')->pluck('folio')->all();
        $this->assertSame(['EL-202610-001', 'EL-202610-002', 'EL-202610-003', 'EL-202610-004', 'EL-202610-005'], $folios, 'consecutivo sin repetirse y sin huecos');
        // Quien la pidió dos veces recibió el mismo folio las dos veces.
        $porEmpresa = [];
        foreach ($salidas as $salida) {
            foreach ($salida['folios'] as $empresaId => $folio) {
                $porEmpresa[$empresaId][] = $folio;
            }
        }
        foreach ($porEmpresa as $foliosDeLaEmpresa) {
            $this->assertCount(1, array_unique($foliosDeLaEmpresa));
        }
    }
}
