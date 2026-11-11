<?php

namespace Tests\Feature\Negocio;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Empresa;
use App\Models\FacturaMensual;
use App\Services\GeneradorDeFacturas;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Factura mensual: total, parte de la empresa, parte de los empleados y diferencia por mínimo.
 * Se factura octubre de 2026 desde el 5 de noviembre.
 */
class FacturaMensualTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-05 09:00');
    }

    private function facturas(): GeneradorDeFacturas
    {
        return app(GeneradorDeFacturas::class);
    }

    /** Una elección de cada fecha dada, con su entrega en el estado indicado. */
    private function consumo(Empresa $empresa, array $fechas, string $estadoEntrega = 'entregada', float $precio = 130, float $subsidio = 60): void
    {
        $menu = $this->platillo();
        foreach ($fechas as $fecha) {
            $empleado = $this->empleadoDe($empresa);
            DB::table('elecciones_empleado')->insert([
                'empleado_id' => $empleado->id, 'fecha' => $fecha, 'tiempo_comida' => 'comida', 'menu_id' => $menu->id,
                'precio_congelado' => $precio, 'porcentaje_subsidio_congelado' => $subsidio, 'origen' => 'manual', 'cerrada_en' => "{$fecha} 00:00:00",
            ]);
            DB::table('entregas_empresa')->insertOrIgnore([
                'empresa_id' => $empresa->id, 'fecha' => $fecha, 'platillos' => 1, 'ventana_inicio' => '12:30', 'ventana_fin' => '12:50', 'estado' => $estadoEntrega,
            ]);
        }
    }

    public function test_calcula_total_parte_de_la_empresa_parte_de_los_empleados_y_diferencia_por_minimo(): void
    {
        $empresa = $this->empresaConContrato('Zapopan', ['compra_minima_mensual' => 10, 'precio_por_platillo' => 130, 'porcentaje_subsidio' => 60]);
        $this->consumo($empresa, ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08']);

        $f = $this->facturas()->generar($empresa, '2026-10-17'); // cualquier día del mes sirve

        $this->assertSame('2026-10-01', $f->mes->toDateString());
        $this->assertSame(4, $f->platillos);
        $this->assertSame('312.00', $f->parte_empresa, '4 × 130 × 60 %');
        $this->assertSame('208.00', $f->parte_empleados, 'el resto del consumo: 520 − 312');
        $this->assertSame(6, $f->platillos_faltantes, 'mínimo 10 − 4 entregados');
        $this->assertSame('780.00', $f->diferencia_minimo, '6 × 130, el precio por platillo del contrato');
        $this->assertSame('1300.00', $f->total, '312 + 208 + 780');
        $this->assertSame('borrador', $f->estado);
        $this->assertSame('EL-202610-001', $f->folio);
    }

    public function test_si_cumple_el_minimo_no_hay_diferencia(): void
    {
        $empresa = $this->empresaConContrato('Zapopan', ['compra_minima_mensual' => 3]);
        $this->consumo($empresa, ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08']);

        $f = $this->facturas()->generar($empresa, '2026-10-01');

        $this->assertSame([0, '0.00', '520.00'], [$f->platillos_faltantes, $f->diferencia_minimo, $f->total]);
    }

    public function test_cada_platillo_usa_su_precio_y_subsidio_congelados_y_redondea_a_centavos(): void
    {
        $empresa = $this->empresaConContrato('Zapopan', ['compra_minima_mensual' => 1]);
        $this->consumo($empresa, ['2026-10-05', '2026-10-06'], 'entregada', 49.90, 33.33);
        $this->consumo($empresa, ['2026-10-07'], 'entregada', 200, 100);
        $this->consumo($empresa, ['2026-10-08'], 'entregada', 100, 0);

        $f = $this->facturas()->generar($empresa, '2026-10-01');

        // 49.90 × 33.33 % = 16.63167 → 16.63 por platillo (×2) + 200 + 0 = 233.26
        // Consumo: 49.90 × 2 + 200 + 100 = 399.80 → empleados: 399.80 − 233.26 = 166.54
        $this->assertSame('233.26', $f->parte_empresa);
        $this->assertSame('166.54', $f->parte_empleados);
        $this->assertSame($f->total, $this->suma($f->parte_empresa, $f->parte_empleados, $f->diferencia_minimo));
        $this->assertSame('399.80', $f->total);
    }

    private function suma(string ...$montos): string
    {
        return number_format(array_sum(array_map(fn ($m) => (int) round($m * 100), $montos)) / 100, 2, '.', '');
    }

    public function test_solo_cuentan_las_entregas_entregadas_de_esa_empresa_y_de_ese_mes(): void
    {
        $empresa = $this->empresaConContrato('Zapopan', ['compra_minima_mensual' => 1]);
        $otra = $this->empresaConContrato('Zapopan');
        $this->consumo($empresa, ['2026-10-05']);
        $this->consumo($empresa, ['2026-10-06'], 'no_localizado');
        $this->consumo($empresa, ['2026-10-07'], 'cancelada');
        $this->consumo($empresa, ['2026-09-30', '2026-11-02']);
        $this->consumo($otra, ['2026-10-05', '2026-10-06']);

        $this->assertSame(1, $this->facturas()->generar($empresa, '2026-10-01')->platillos);
    }

    public function test_el_mes_debe_haber_terminado(): void
    {
        $empresa = $this->empresaConContrato();

        $this->ahoraEs('2026-10-31 23:59:59');
        $this->expectException(ReglaDeNegocioException::class);
        $this->expectExceptionMessage('ya terminó');
        $this->facturas()->generar($empresa, '2026-10-01');
    }

    public function test_sin_contrato_en_ese_mes_no_se_factura(): void
    {
        $empresa = $this->empresaConContrato('Zapopan', ['vigencia_inicio' => '2026-11-01', 'vigencia_fin' => '2027-04-30']);

        $this->expectException(ReglaDeNegocioException::class);
        $this->facturas()->generar($empresa, '2026-10-01');
    }

    public function test_un_contrato_vencido_sigue_facturando_sus_meses(): void
    {
        $empresa = $this->empresaConContrato('Zapopan', ['vigencia_fin' => '2026-10-31', 'activo' => false, 'desactivado_en' => now()]);

        $this->assertSame(10, $this->facturas()->generar($empresa, '2026-10-01')->platillos_faltantes);
    }

    public function test_generarla_otra_vez_recalcula_el_borrador_sin_duplicarla(): void
    {
        $empresa = $this->empresaConContrato('Zapopan', ['compra_minima_mensual' => 10]);
        $this->consumo($empresa, ['2026-10-05']);
        $primera = $this->facturas()->generar($empresa, '2026-10-01');

        $this->consumo($empresa, ['2026-10-06']);
        $segunda = $this->facturas()->generar($empresa, '2026-10-01');

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, FacturaMensual::count());
        $this->assertSame(2, $segunda->platillos);
    }

    public function test_emitida_ya_no_cambia_aunque_se_vuelva_a_generar_y_solo_avanza_a_pagada(): void
    {
        $empresa = $this->empresaConContrato('Zapopan', ['compra_minima_mensual' => 10]);
        $this->consumo($empresa, ['2026-10-05']);
        $factura = $this->facturas()->emitir($this->facturas()->generar($empresa, '2026-10-01'));
        $this->assertNotNull($factura->emitida_en);

        $this->consumo($empresa, ['2026-10-06']);
        $this->assertSame(1, $this->facturas()->generar($empresa, '2026-10-01')->platillos, 'la emitida queda tal cual');

        $this->assertSame('emitida', $factura->estado);
        $pagada = $this->facturas()->marcarPagada($factura);
        $this->assertSame('pagada', $pagada->estado);
        $this->assertNotNull($pagada->pagada_en);

        foreach ([fn () => $this->facturas()->emitir($pagada), fn () => $this->facturas()->marcarPagada($pagada)] as $accion) {
            try {
                $accion();
                $this->fail('Debía fallar.');
            } catch (ReglaDeNegocioException $e) {
                $this->assertContains($e->codigo, ['factura_ya_emitida', 'factura_no_emitida']);
            }
        }
    }

    public function test_no_se_puede_pagar_un_borrador(): void
    {
        $empresa = $this->empresaConContrato();

        $this->expectException(ReglaDeNegocioException::class);
        $this->facturas()->marcarPagada($this->facturas()->generar($empresa, '2026-10-01'));
    }

    public function test_la_base_sigue_impidiendo_cambiar_los_montos_de_una_factura_emitida(): void
    {
        $empresa = $this->empresaConContrato();
        $factura = $this->facturas()->emitir($this->facturas()->generar($empresa, '2026-10-01'));

        $this->rechaza('sus montos no se pueden cambiar', fn () => DB::table('facturas_mensuales')->where('id', $factura->id)->update(['platillos' => 99]));
    }
}
