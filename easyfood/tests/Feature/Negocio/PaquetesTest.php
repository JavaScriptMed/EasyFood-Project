<?php

namespace Tests\Feature\Negocio;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Address;
use App\Models\DisponibilidadDia;
use App\Models\Menu;
use App\Models\Paquete;
use App\Models\Pedido;
use App\Models\PedidoDia;
use App\Models\User;
use App\Services\ContratacionDePaquetes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** Paquetes de 1, 2 o 6 días para clientes individuales; el pago es simulado. */
class PaquetesTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private User $cliente;

    private Address $direccion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-09 10:00'); // lunes
        $this->cliente = User::findOrFail($this->usuario());
        $this->direccion = Address::create(['user_id' => $this->cliente->id, 'street_address' => 'Av. Vallarta 100', 'latitude' => 20.67, 'longitude' => -103.35]);
    }

    private function paquetes(): ContratacionDePaquetes
    {
        return app(ContratacionDePaquetes::class);
    }

    private function paquete(int $dias, float $descuento = 0): Paquete
    {
        return Paquete::create(['nombre' => "Paquete {$dias} días", 'dias' => $dias, 'descuento_porcentaje' => $descuento]);
    }

    /** Un platillo con disponibilidad en todas las fechas dadas. */
    private function platilloDisponible(array $fechas, float $precio = 100, int $maximo = 50): Menu
    {
        $menu = $this->platillo(null, 'comida', ['price' => $precio]);
        foreach ($fechas as $fecha) {
            $this->disponible($menu, $fecha, $maximo);
        }

        return $menu;
    }

    public function test_los_paquetes_son_de_1_2_o_6_dias_y_cada_uno_toma_los_siguientes_dias_de_servicio(): void
    {
        $semana = ['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-16'];
        $menu = $this->platilloDisponible($semana);

        $uno = $this->paquetes()->pedir($this->cliente, $this->paquete(1), $this->direccion, [$menu]);
        $this->assertSame(['2026-11-10', '2026-11-10'], [$uno->fecha_inicio->toDateString(), $uno->fecha_fin->toDateString()]);

        $seis = $this->paquetes()->pedir($this->cliente, $this->paquete(6), $this->direccion, array_fill(0, 6, $menu));
        $this->assertSame($semana, $seis->dias()->orderBy('fecha')->get()->map(fn ($d) => $d->fecha->toDateString())->all());
        $this->assertSame('2026-11-16', $seis->fecha_fin->toDateString(), 'el domingo no cuenta');
    }

    public function test_un_pedido_hecho_despues_de_las_6_pm_entra_al_siguiente_ciclo(): void
    {
        $menu = $this->platilloDisponible(['2026-11-10', '2026-11-11']);

        $this->ahoraEs('2026-11-09 17:00');
        $antes = $this->paquetes()->pedir($this->cliente, $this->paquete(1), $this->direccion, [$menu]);
        $this->ahoraEs('2026-11-09 19:00');
        $despues = $this->paquetes()->pedir($this->cliente, Paquete::first(), $this->direccion, [$menu]);

        $this->assertSame('2026-11-10', $antes->fecha_inicio->toDateString());
        $this->assertSame('2026-11-11', $despues->fecha_inicio->toDateString());
    }

    public function test_el_total_es_la_suma_de_los_precios_congelados_menos_el_descuento_del_paquete(): void
    {
        $caro = $this->platilloDisponible(['2026-11-10', '2026-11-11'], 150);
        $barato = $this->platilloDisponible(['2026-11-10', '2026-11-11'], 100);

        $pedido = $this->paquetes()->pedir($this->cliente, $this->paquete(2, 5), $this->direccion, [$caro, $barato]);

        $this->assertSame('237.50', $pedido->total, '(150 + 100) × 95 %');
        $this->assertSame(['150.00', '100.00'], $pedido->dias()->orderBy('fecha')->pluck('precio_congelado')->all());
    }

    public function test_el_descuento_lo_define_cada_paquete_y_sin_descuento_se_paga_completo(): void
    {
        $menu = $this->platilloDisponible(['2026-11-10', '2026-11-11'], 80);

        $sin = $this->paquetes()->pedir($this->cliente, $this->paquete(1, 0), $this->direccion, [$menu]);
        $doce = $this->paquetes()->pedir($this->cliente, $this->paquete(2, 12), $this->direccion, [$menu, $menu]);

        $this->assertSame('80.00', $sin->total);
        $this->assertSame('140.80', $doce->total, '160 × 88 %');
    }

    public function test_un_cambio_de_precio_posterior_no_toca_lo_ya_pedido(): void
    {
        $menu = $this->platilloDisponible(['2026-11-10'], 100);
        $pedido = $this->paquetes()->pedir($this->cliente, $this->paquete(1), $this->direccion, [$menu]);

        $menu->update(['price' => 300]);

        $this->assertSame('100.00', PedidoDia::where('pedido_id', $pedido->id)->value('precio_congelado'));
        $this->assertSame('100.00', $pedido->fresh()->total);
    }

    public function test_pedir_consume_porciones_y_si_un_dia_no_tiene_no_se_crea_nada(): void
    {
        $lleno = $this->platillo();
        $this->disponible($lleno, '2026-11-10');
        $this->disponible($lleno, '2026-11-11', 0);

        try {
            $this->paquetes()->pedir($this->cliente, $this->paquete(2), $this->direccion, [$lleno, $lleno]);
            $this->fail('Debía fallar por falta de porciones.');
        } catch (ReglaDeNegocioException $e) {
            $this->assertSame('sin_porciones', $e->codigo);
        }

        $this->assertSame(0, Pedido::count());
        $this->assertSame(0, DisponibilidadDia::whereDate('fecha', '2026-11-10')->first()->porciones_tomadas, 'el primer día no queda apartado');

        $this->paquetes()->pedir($this->cliente, Paquete::first(), $this->direccion, [$this->platilloDisponible(['2026-11-10', '2026-11-11']), $this->platilloDisponible(['2026-11-10', '2026-11-11'])]);
        $this->assertSame(2, DisponibilidadDia::where('porciones_tomadas', 1)->count());
    }

    public function test_exige_un_platillo_por_dia_un_paquete_activo_y_una_direccion_propia(): void
    {
        $menu = $this->platilloDisponible(['2026-11-10', '2026-11-11']);
        $dos = $this->paquete(2);
        $ajena = Address::create(['user_id' => $this->usuario(), 'street_address' => 'Otra', 'latitude' => 20.6, 'longitude' => -103.3]);

        foreach ([
            'platillos_incompletos' => fn () => $this->paquetes()->pedir($this->cliente, $dos, $this->direccion, [$menu]),
            'direccion_ajena' => fn () => $this->paquetes()->pedir($this->cliente, $dos, $ajena, [$menu, $menu]),
        ] as $codigo => $accion) {
            try {
                $accion();
                $this->fail("Debía fallar con {$codigo}.");
            } catch (ReglaDeNegocioException $e) {
                $this->assertSame($codigo, $e->codigo);
            }
        }

        $dos->desactivar();
        $this->expectException(ReglaDeNegocioException::class);
        $this->expectExceptionMessage('ya no está disponible');
        $this->paquetes()->pedir($this->cliente, $dos, $this->direccion, [$menu, $menu]);
    }

    public function test_el_platillo_debe_estar_publicado(): void
    {
        $borrador = $this->platillo(null, 'comida', ['estado_revision' => 'borrador']);
        $this->disponible($borrador, '2026-11-10');

        $this->expectException(ReglaDeNegocioException::class);
        $this->paquetes()->pedir($this->cliente, $this->paquete(1), $this->direccion, [$borrador]);
    }

    public function test_el_pago_es_simulado_no_cobra_y_deja_el_pedido_recibido(): void
    {
        $menu = $this->platilloDisponible(['2026-11-10']);
        $pedido = $this->paquetes()->pedir($this->cliente, $this->paquete(1), $this->direccion, [$menu]);
        $this->assertSame('pendiente_pago', $pedido->estado);
        $this->assertNull($pedido->pagado_en);

        $pagado = $this->paquetes()->pagar($pedido, 'tarjeta', '4821');

        $this->assertSame(['recibido', 'tarjeta', '4821'], [$pagado->estado, $pagado->metodo_pago, $pagado->ultimos_digitos]);
        $this->assertNotNull($pagado->pagado_en);

        $this->expectException(ReglaDeNegocioException::class);
        $this->paquetes()->pagar($pagado, 'tarjeta', '4821');
    }

    public function test_no_se_paga_un_pedido_cuyo_corte_ya_paso(): void
    {
        $menu = $this->platilloDisponible(['2026-11-10']);
        $this->ahoraEs('2026-11-09 17:00');
        $pedido = $this->paquetes()->pedir($this->cliente, $this->paquete(1), $this->direccion, [$menu]);

        $this->ahoraEs('2026-11-09 18:00');
        $this->expectException(ReglaDeNegocioException::class);
        $this->expectExceptionMessage('ya pasó');
        $this->paquetes()->pagar($pedido, 'tarjeta', '4821');
    }

    public function test_los_folios_son_unicos_y_consecutivos_por_dia(): void
    {
        $menu = $this->platilloDisponible(['2026-11-10']);
        $paquete = $this->paquete(1);

        $a = $this->paquetes()->pedir($this->cliente, $paquete, $this->direccion, [$menu]);
        $b = $this->paquetes()->pedir($this->cliente, $paquete, $this->direccion, [$menu]);

        $this->assertSame(['GDL-20261109-0001', 'GDL-20261109-0002'], [$a->folio, $b->folio]);
    }
}
