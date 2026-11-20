<?php

namespace Tests\Feature\Negocio;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Address;
use App\Models\DisponibilidadDia;
use App\Models\Menu;
use App\Models\Paquete;
use App\Models\Pedido;
use App\Models\PedidoDia;
use App\Models\PedidoDiaPlatillo;
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
        $this->zonaDePruebas();
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
        $this->assertSame(['150.00', '100.00'], PedidoDiaPlatillo::whereIn('pedido_dia_id', $pedido->dias()->pluck('id'))->orderBy('id')->pluck('precio_congelado')->all());
    }

    /** Un platillo de un tiempo de comida con disponibilidad en las fechas dadas. */
    private function platilloDe(string $tiempo, array $fechas, float $precio = 100, int $maximo = 50): Menu
    {
        $menu = $this->platillo(null, $tiempo, ['price' => $precio]);
        foreach ($fechas as $fecha) {
            $this->disponible($menu, $fecha, $maximo);
        }

        return $menu;
    }

    private function assertRechazo(string $codigo, callable $accion): void
    {
        try {
            $accion();
        } catch (ReglaDeNegocioException $e) {
            $this->assertSame($codigo, $e->codigo);

            return;
        }

        $this->fail("Debía rechazarse con «{$codigo}».");
    }

    public function test_el_cliente_elige_1_2_o_3_comidas_por_dia_y_cada_una_lleva_su_platillo_y_su_precio(): void
    {
        $fechas = ['2026-11-10', '2026-11-11'];
        $desayuno = $this->platilloDe('desayuno', $fechas, 60);
        $comida = $this->platilloDe('comida', $fechas, 100);
        $cena = $this->platilloDe('cena', $fechas, 80);

        $una = $this->paquetes()->pedir($this->cliente, $this->paquete(1), $this->direccion, [[ 'comida' => $comida ]]);
        $dos = $this->paquetes()->pedir($this->cliente, Paquete::first(), $this->direccion, [['desayuno' => $desayuno, 'comida' => $comida]]);
        $tres = $this->paquetes()->pedir($this->cliente, Paquete::first(), $this->direccion, [['cena' => $cena, 'desayuno' => $desayuno, 'comida' => $comida]]);

        $this->assertSame([1, 2, 3], [$una->comidas_por_dia, $dos->comidas_por_dia, $tres->comidas_por_dia]);
        $this->assertSame(['100.00', '160.00', '240.00'], [$una->total, $dos->total, $tres->total]);
        $this->assertSame(1, $tres->dias()->count(), 'una sola entrega por día, aunque lleve tres comidas');
        $this->assertSame(['desayuno', 'comida', 'cena'], $tres->dias()->first()->platillos()->orderBy('id')->pluck('tiempo_comida')->sort()->values()->sortBy(fn ($t) => ['desayuno' => 0, 'comida' => 1, 'cena' => 2][$t])->values()->all());
    }

    public function test_un_paquete_de_varios_dias_con_varias_comidas_pide_un_platillo_por_comida_y_por_dia(): void
    {
        $semana = ['2026-11-10', '2026-11-11'];
        $desayuno = $this->platilloDe('desayuno', $semana, 50);
        $cena = $this->platilloDe('cena', $semana, 70);

        $pedido = $this->paquetes()->pedir($this->cliente, $this->paquete(2, 10), $this->direccion, array_fill(0, 2, ['desayuno' => $desayuno, 'cena' => $cena]));

        $this->assertSame(2, $pedido->comidas_por_dia);
        $this->assertSame(4, PedidoDiaPlatillo::whereIn('pedido_dia_id', $pedido->dias()->pluck('id'))->count());
        $this->assertSame('216.00', $pedido->total, '(50 + 70) × 2 días × 90 %');
        $this->assertSame(2, DisponibilidadDia::where('menu_id', $desayuno->id)->sum('porciones_tomadas'));
    }

    public function test_todos_los_dias_llevan_las_mismas_comidas_y_cada_platillo_es_de_su_tiempo(): void
    {
        $fechas = ['2026-11-10', '2026-11-11'];
        $desayuno = $this->platilloDe('desayuno', $fechas);
        $comida = $this->platilloDe('comida', $fechas);

        $uno = $this->paquete(1);
        $dos = $this->paquete(2);

        $this->assertRechazo('comidas_distintas_por_dia', fn () => $this->paquetes()->pedir($this->cliente, $dos, $this->direccion, [['comida' => $comida], ['desayuno' => $desayuno, 'comida' => $comida]]));
        $this->assertRechazo('platillo_de_otro_tiempo', fn () => $this->paquetes()->pedir($this->cliente, $uno, $this->direccion, [['cena' => $comida]]));
        $this->assertRechazo('tiempo_de_comida_invalido', fn () => $this->paquetes()->pedir($this->cliente, $uno, $this->direccion, [['merienda' => $comida]]));
        $this->assertRechazo('comidas_por_dia_invalidas', fn () => $this->paquetes()->pedir($this->cliente, $uno, $this->direccion, [[]]));
        $this->assertSame(0, Pedido::count(), 'ningún intento dejó un pedido a medias');
    }

    public function test_el_extra_es_un_platillo_mas_por_dia_de_cualquier_tiempo_y_entra_al_descuento_del_paquete(): void
    {
        $fechas = ['2026-11-10', '2026-11-11'];
        $comida = $this->platilloDe('comida', $fechas, 100);
        $otraComida = $this->platilloDe('comida', $fechas, 50);
        $cena = $this->platilloDe('cena', $fechas, 80);

        $pedido = $this->paquetes()->pedir($this->cliente, $this->paquete(2, 10), $this->direccion, array_fill(0, 2, ['comida' => $comida, 'extra' => $otraComida]));
        $extras = PedidoDiaPlatillo::whereIn('pedido_dia_id', $pedido->dias()->pluck('id'))->where('es_extra', true);

        $this->assertTrue($pedido->con_extra);
        $this->assertSame(1, $pedido->comidas_por_dia, 'el extra no cuenta como comida base');
        $this->assertSame(2, (clone $extras)->count(), 'un extra por día');
        $this->assertSame('270.00', $pedido->total, '(100 + 50) × 2 días × 90 %');
        $this->assertSame(2, DisponibilidadDia::where('menu_id', $otraComida->id)->sum('porciones_tomadas'), 'el extra aparta su porción');

        $conCena = $this->paquetes()->pedir($this->cliente, $this->paquete(1), $this->direccion, [['comida' => $comida, 'extra' => $cena]]);
        $this->assertSame('180.00', $conCena->total, 'el extra puede ser de otro tiempo de comida');
    }

    public function test_un_platillo_se_puede_repetir_en_todos_los_dias_y_tambien_como_extra_el_mismo_dia(): void
    {
        $fechas = ['2026-11-10', '2026-11-11'];
        $comida = $this->platilloDe('comida', $fechas, 100);

        // El mismo platillo los dos días y, además, otra vez como extra: cada uno aparta su propia porción.
        $pedido = $this->paquetes()->pedir($this->cliente, $this->paquete(2), $this->direccion, array_fill(0, 2, ['comida' => $comida, 'extra' => $comida]));

        $this->assertSame('400.00', $pedido->total, '(100 + 100) × 2 días');
        $this->assertSame(4, PedidoDiaPlatillo::whereIn('pedido_dia_id', $pedido->dias()->pluck('id'))->where('menu_id', $comida->id)->count());
        $this->assertSame(4, (int) DisponibilidadDia::where('menu_id', $comida->id)->sum('porciones_tomadas'));
    }

    public function test_el_extra_se_pide_para_todos_los_dias_o_para_ninguno_y_cancelar_lo_libera(): void
    {
        $fechas = ['2026-11-10', '2026-11-11'];
        $comida = $this->platilloDe('comida', $fechas);
        $extra = $this->platilloDe('cena', $fechas);

        $this->assertRechazo('extra_distinto_por_dia', fn () => $this->paquetes()->pedir($this->cliente, $this->paquete(2), $this->direccion, [['comida' => $comida, 'extra' => $extra], ['comida' => $comida]]));
        $this->assertSame(0, Pedido::count());

        $pedido = $this->paquetes()->pedir($this->cliente, Paquete::first(), $this->direccion, array_fill(0, 2, ['comida' => $comida, 'extra' => $extra]));
        $this->assertSame(2, DisponibilidadDia::where('menu_id', $extra->id)->sum('porciones_tomadas'));

        $this->ahoraEs('2026-11-09 18:01');
        $this->paquetes()->cancelarVencidos();

        $this->assertSame('cancelado', $pedido->fresh()->estado);
        $this->assertSame(0, (int) DisponibilidadDia::where('menu_id', $extra->id)->sum('porciones_tomadas'), 'el extra también devuelve su porción');
    }

    public function test_la_base_rechaza_un_platillo_en_un_tiempo_de_comida_que_no_es_el_suyo_y_dos_del_mismo_tiempo_el_mismo_dia(): void
    {
        $dia = $this->pedidoDia();
        $this->assertGreaterThan(0, $this->pedidoDiaPlatillo($dia, ['tiempo_comida' => 'comida']));
        $this->rechaza('no es de cena', fn () => $this->pedidoDiaPlatillo($dia, ['tiempo_comida' => 'cena']));
        $this->rechaza('pedido_dia_platillos_un_platillo_por_tiempo', fn () => $this->pedidoDiaPlatillo($dia, ['tiempo_comida' => 'comida']));
        // El extra sí puede repetir el tiempo de una comida base, pero solo hay uno por día.
        $this->assertGreaterThan(0, $this->pedidoDiaPlatillo($dia, ['tiempo_comida' => 'comida', 'es_extra' => true]));
        $this->rechaza('pedido_dia_platillos_un_extra_por_dia', fn () => $this->pedidoDiaPlatillo($dia, ['tiempo_comida' => 'cena', 'menu_id' => $this->menu(null, ['tiempo_comida' => 'cena']), 'es_extra' => true]));
        $this->rechaza('pedido_dia_platillos_precio_positivo', fn () => $this->pedidoDiaPlatillo($this->pedidoDia(), ['precio_congelado' => 0]));
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

        $this->assertSame('100.00', PedidoDiaPlatillo::whereIn('pedido_dia_id', $pedido->dias()->pluck('id'))->value('precio_congelado'));
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
