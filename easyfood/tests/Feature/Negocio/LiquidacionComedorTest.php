<?php

namespace Tests\Feature\Negocio;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Address;
use App\Models\ContratoComedor;
use App\Models\LiquidacionComedor;
use App\Models\Menu;
use App\Models\Provider;
use App\Services\GeneradorDeLiquidaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** Liquidación a comedores con su diferencia por mínimo. Se liquida octubre de 2026 desde el 5 de noviembre. */
class LiquidacionComedorTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-05 09:00');
    }

    private function liquidaciones(): GeneradorDeLiquidaciones
    {
        return app(GeneradorDeLiquidaciones::class);
    }

    private function comedorConContrato(int $minimo = 5, float $precio = 49): Provider
    {
        $comedor = $this->comedorNuevo();
        ContratoComedor::create(['comedor_id' => $comedor->id, 'precio_por_platillo' => $precio, 'compra_minima_mensual' => $minimo, 'vigencia_inicio' => '2026-07-01', 'vigencia_fin' => '2027-01-31']);

        return $comedor;
    }

    /** Platillos del comedor entregados a una empresa, uno por fecha. */
    private function entregadosAEmpresa(Menu $menu, array $fechas, string $estado = 'entregada'): void
    {
        $empresa = $this->empresaConContrato();
        foreach ($fechas as $fecha) {
            DB::table('elecciones_empleado')->insert([
                'empleado_id' => $this->empleadoDe($empresa)->id, 'fecha' => $fecha, 'tiempo_comida' => 'comida', 'menu_id' => $menu->id,
                'precio_congelado' => 130, 'porcentaje_subsidio_congelado' => 60, 'origen' => 'manual', 'cerrada_en' => "{$fecha} 00:00:00",
            ]);
            DB::table('entregas_empresa')->insertOrIgnore(['empresa_id' => $empresa->id, 'fecha' => $fecha, 'platillos' => 1, 'ventana_inicio' => '12:30', 'ventana_fin' => '12:50', 'estado' => $estado]);
        }
    }

    private function entregadosAIndividuales(Menu $menu, int $cantidad, string $estado = 'entregada'): void
    {
        $cliente = DB::table('users')->where('id', $this->usuario())->first();
        $direccion = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. 1', 'latitude' => 20.67, 'longitude' => -103.35]);
        $paquete = \App\Models\Paquete::firstOrCreate(['nombre' => 'Un día'], ['dias' => 1, 'descuento_porcentaje' => 0]);
        for ($i = 0; $i < $cantidad; $i++) {
            $pedido = \App\Models\Pedido::create([
                'folio' => 'PED-'.uniqid(), 'cliente_id' => $cliente->id, 'paquete_id' => $paquete->id, 'direccion_id' => $direccion->id,
                'fecha_inicio' => '2026-10-12', 'fecha_fin' => '2026-10-12', 'total' => 49, 'estado' => 'cancelado',
            ]);
            DB::table('pedido_dias')->insert([
                'pedido_id' => $pedido->id, 'fecha' => '2026-10-12', 'menu_id' => $menu->id, 'precio_congelado' => 49, 'estado' => $estado,
                'entregada_en' => $estado === 'entregada' ? '2026-10-12 13:00:00' : null,
            ]);
        }
    }

    public function test_si_se_compro_menos_del_minimo_se_paga_la_diferencia(): void
    {
        $comedor = $this->comedorConContrato(5, 49);
        $menu = $this->platillo($comedor);
        $this->entregadosAEmpresa($menu, ['2026-10-05', '2026-10-06', '2026-10-07']);

        $l = $this->liquidaciones()->generar($comedor, '2026-10-20');

        $this->assertSame('2026-10-01', $l->mes->toDateString());
        $this->assertSame(3, $l->platillos_entregados);
        $this->assertSame('49.00', $l->precio_por_platillo);
        $this->assertSame(5, $l->platillos_minimos);
        $this->assertSame(2, $l->platillos_faltantes);
        $this->assertSame('98.00', $l->diferencia_minimo, '2 × 49');
        $this->assertSame('245.00', $l->monto, '(3 + 2) × 49: lo entregado más lo que faltó para el mínimo');
        $this->assertSame('pendiente', $l->estado);
    }

    public function test_si_cumple_el_minimo_se_paga_solo_lo_entregado(): void
    {
        $comedor = $this->comedorConContrato(5, 49);
        $menu = $this->platillo($comedor);
        $this->entregadosAEmpresa($menu, ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10']);

        $l = $this->liquidaciones()->generar($comedor, '2026-10-01');

        $this->assertSame([0, '0.00', '294.00'], [$l->platillos_faltantes, $l->diferencia_minimo, $l->monto]);
    }

    public function test_suma_empresas_e_individuales_pero_solo_lo_entregado_y_de_sus_platillos(): void
    {
        $comedor = $this->comedorConContrato(5, 49);
        $menu = $this->platillo($comedor);
        $ajeno = $this->platillo();
        $this->entregadosAEmpresa($menu, ['2026-10-05', '2026-10-06']);
        $this->entregadosAEmpresa($menu, ['2026-10-07'], 'no_localizado');
        $this->entregadosAEmpresa($ajeno, ['2026-10-08']);
        $this->entregadosAEmpresa($menu, ['2026-09-30', '2026-11-02']);
        $this->entregadosAIndividuales($menu, 2);
        $this->entregadosAIndividuales($menu, 1, 'no_localizado');

        $this->assertSame(4, $this->liquidaciones()->generar($comedor, '2026-10-01')->platillos_entregados);
    }

    public function test_con_cero_entregados_se_paga_el_minimo_completo(): void
    {
        $comedor = $this->comedorConContrato(200, 49);

        $l = $this->liquidaciones()->generar($comedor, '2026-10-01');

        $this->assertSame([200, '9800.00', '9800.00'], [$l->platillos_faltantes, $l->diferencia_minimo, $l->monto]);
    }

    public function test_el_mes_debe_haber_terminado_y_el_comedor_debe_tener_contrato(): void
    {
        $comedor = $this->comedorConContrato();
        $this->ahoraEs('2026-10-31 23:00');
        try {
            $this->liquidaciones()->generar($comedor, '2026-10-01');
            $this->fail('Debía fallar: el mes no terminó.');
        } catch (ReglaDeNegocioException $e) {
            $this->assertSame('mes_sin_terminar', $e->codigo);
        }

        $this->ahoraEs('2026-11-05 09:00');
        $this->expectException(ReglaDeNegocioException::class);
        $this->liquidaciones()->generar($this->comedorNuevo(), '2026-10-01');
    }

    public function test_pendiente_se_recalcula_sin_duplicarse_y_pagada_ya_no_cambia(): void
    {
        $comedor = $this->comedorConContrato(5, 49);
        $menu = $this->platillo($comedor);
        $this->entregadosAEmpresa($menu, ['2026-10-05']);
        $primera = $this->liquidaciones()->generar($comedor, '2026-10-01');

        $this->entregadosAEmpresa($menu, ['2026-10-06']);
        $segunda = $this->liquidaciones()->generar($comedor, '2026-10-01');
        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, LiquidacionComedor::count());
        $this->assertSame(2, $segunda->platillos_entregados);

        $pagada = $this->liquidaciones()->marcarPagada($segunda);
        $this->assertSame('pagado', $pagada->estado);
        $this->assertNotNull($pagada->pagada_en);

        $this->entregadosAEmpresa($menu, ['2026-10-07']);
        $this->assertSame(2, $this->liquidaciones()->generar($comedor, '2026-10-01')->platillos_entregados, 'una pagada ya no cambia');

        $this->expectException(ReglaDeNegocioException::class);
        $this->liquidaciones()->marcarPagada($pagada);
    }
}
