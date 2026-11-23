<?php

namespace Tests\Feature\Esquema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** La base de la entrega semanal de individuales: comidas por día de comida, fecha de entrega del pedido y un corte por tipo. */
class EntregaSemanalDeIndividualesTest extends TestCase
{
    use RefreshDatabase;
    use VerificaRestricciones;

    public function test_en_una_entrega_cada_dia_de_comida_lleva_una_comida_por_tiempo_y_un_extra(): void
    {
        $entrega = $this->pedidoDia(null, ['fecha' => '2026-11-14']);

        $this->assertGreaterThan(0, $this->pedidoDiaPlatillo($entrega, ['fecha' => '2026-11-16', 'tiempo_comida' => 'comida']));
        $this->assertGreaterThan(0, $this->pedidoDiaPlatillo($entrega, ['fecha' => '2026-11-17', 'tiempo_comida' => 'comida']), 'la misma comida otro día de la misma entrega');
        $this->rechaza('pedido_dia_platillos_un_platillo_por_tiempo', fn () => $this->pedidoDiaPlatillo($entrega, ['fecha' => '2026-11-16', 'tiempo_comida' => 'comida']));

        $this->assertGreaterThan(0, $this->pedidoDiaPlatillo($entrega, ['fecha' => '2026-11-16', 'tiempo_comida' => 'comida', 'es_extra' => true]));
        $this->assertGreaterThan(0, $this->pedidoDiaPlatillo($entrega, ['fecha' => '2026-11-17', 'tiempo_comida' => 'comida', 'es_extra' => true]), 'un extra por cada día de comida');
        $this->rechaza('pedido_dia_platillos_un_extra_por_dia', fn () => $this->pedidoDiaPlatillo($entrega, ['fecha' => '2026-11-16', 'tiempo_comida' => 'comida', 'es_extra' => true]));
    }

    public function test_si_un_insert_no_manda_la_fecha_de_comida_o_de_entrega_la_base_usa_la_del_dia_o_la_de_inicio(): void
    {
        $platillo = $this->pedidoDiaPlatillo($this->pedidoDia(null, ['fecha' => '2026-11-14']));
        $this->assertSame('2026-11-14', DB::table('pedido_dia_platillos')->where('id', $platillo)->value('fecha'));

        $pedido = $this->pedido(['fecha_inicio' => '2026-11-16', 'fecha_fin' => '2026-11-17']);
        $this->assertSame('2026-11-16', DB::table('pedidos')->where('id', $pedido)->value('fecha_entrega'));

        $this->assertSame('2026-11-20', DB::table('pedidos')->where('id', $this->pedido(['fecha_entrega' => '2026-11-20']))->value('fecha_entrega'), 'si la manda, se respeta');
    }

    public function test_hay_un_corte_de_cada_tipo_por_fecha_y_el_tipo_es_valido(): void
    {
        $corte = fn (array $extra = []) => DB::table('cortes')->insertGetId($extra + ['fecha_entrega' => '2026-11-14', 'estado' => 'correcto', 'sin_cambios' => false]);

        $this->assertGreaterThan(0, $corte());
        $this->assertGreaterThan(0, $corte(['tipo' => 'individuales']));
        $this->rechaza('cortes_un_cierre_por_dia', fn () => $corte());
        $this->rechaza('cortes_un_cierre_por_dia', fn () => $corte(['tipo' => 'individuales']));
        $this->rechaza('cortes_tipo_valido', fn () => $corte(['tipo' => 'otro', 'fecha_entrega' => '2026-11-21']));
        $this->assertGreaterThan(0, $corte(['tipo' => 'individuales', 'sin_cambios' => true]), 'los «sin cambios» no cuentan para la unicidad');
    }

    public function test_solo_el_corte_general_bloquea_las_elecciones_de_los_empleados(): void
    {
        DB::table('cortes')->insert(['fecha_entrega' => '2026-11-14', 'estado' => 'correcto', 'sin_cambios' => false, 'tipo' => 'individuales']);
        $menu = $this->menu();
        $empleado = $this->empleado();

        $this->assertGreaterThan(0, DB::table('elecciones_empleado')->insertGetId([
            'empleado_id' => $empleado, 'fecha' => '2026-11-14', 'tiempo_comida' => 'comida', 'menu_id' => $menu,
            'precio_congelado' => 130, 'porcentaje_subsidio_congelado' => 60, 'origen' => 'manual',
        ]), 'el corte de individuales no cierra las elecciones');

        DB::table('cortes')->insert(['fecha_entrega' => '2026-11-14', 'estado' => 'correcto', 'sin_cambios' => false]);
        $this->rechaza('ya se ejecutó', fn () => DB::table('elecciones_empleado')->insert([
            'empleado_id' => $this->empleado(), 'fecha' => '2026-11-14', 'tiempo_comida' => 'comida', 'menu_id' => $menu,
            'precio_congelado' => 130, 'porcentaje_subsidio_congelado' => 60, 'origen' => 'manual',
        ]));
    }
}
