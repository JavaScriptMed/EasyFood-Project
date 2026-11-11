<?php

namespace Tests\Feature\Negocio;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\ContratoEmpresa;
use App\Models\DiaSinServicio;
use App\Models\DisponibilidadDia;
use App\Models\EleccionEmpleado;
use App\Services\EjecutorDelCorte;
use App\Services\EleccionDePlatillos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Reglas: elegir solo antes del corte de las 18:00 (Guadalajara) y congelar precio y subsidio al elegir.
 * «Hoy» en estas pruebas es el miércoles 11 de nov de 2026; se elige para el jueves 12.
 */
class EleccionDePlatillosTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private const ENTREGA = '2026-11-12';

    private function servicio(): EleccionDePlatillos
    {
        return app(EleccionDePlatillos::class);
    }

    private function falla(string $codigo, callable $accion): void
    {
        try {
            $accion();
        } catch (ReglaDeNegocioException $e) {
            $this->assertSame($codigo, $e->codigo, $e->getMessage());

            return;
        }
        $this->fail("Debía fallar con «{$codigo}».");
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-11 10:00');
    }

    public function test_antes_del_corte_el_empleado_elige_y_se_congelan_precio_y_subsidio_del_contrato(): void
    {
        $empresa = $this->empresaConContrato('Zapopan', ['porcentaje_subsidio' => 70, 'precio_por_platillo' => 125.50]);
        $empleado = $this->empleadoDe($empresa);
        $menu = $this->platillo(null, 'comida', ['price' => 49]);
        $this->disponible($menu, self::ENTREGA);

        $eleccion = $this->servicio()->elegir($empleado, self::ENTREGA, 'comida', $menu);

        $this->assertSame('manual', $eleccion->origen);
        $this->assertSame('125.50', $eleccion->precio_congelado, 'se congela el precio del contrato de la empresa, no el de venta del platillo');
        $this->assertSame('70.00', $eleccion->porcentaje_subsidio_congelado);
        $this->assertNull($eleccion->cerrada_en);
        $this->assertSame(1, DisponibilidadDia::first()->porciones_tomadas);
    }

    public function test_si_el_contrato_cambia_despues_lo_ya_elegido_no_se_toca(): void
    {
        $empresa = $this->empresaConContrato('Zapopan', ['porcentaje_subsidio' => 60, 'precio_por_platillo' => 130]);
        $empleado = $this->empleadoDe($empresa);
        $menu = $this->platillo();
        $this->disponible($menu, self::ENTREGA);
        $eleccion = $this->servicio()->elegir($empleado, self::ENTREGA, 'comida', $menu);

        ContratoEmpresa::where('empresa_id', $empresa->id)->update(['porcentaje_subsidio' => 20, 'precio_por_platillo' => 200]);

        $this->assertSame('130.00', $eleccion->fresh()->precio_congelado);
        $this->assertSame('60.00', $eleccion->fresh()->porcentaje_subsidio_congelado);
    }

    public function test_cada_empresa_congela_su_propio_subsidio(): void
    {
        $menu = $this->platillo();
        $this->disponible($menu, self::ENTREGA);
        $a = $this->empleadoDe($this->empresaConContrato('Zapopan', ['porcentaje_subsidio' => 40]));
        $b = $this->empleadoDe($this->empresaConContrato('Zapopan', ['porcentaje_subsidio' => 80]));

        $this->assertSame('40.00', $this->servicio()->elegir($a, self::ENTREGA, 'comida', $menu)->porcentaje_subsidio_congelado);
        $this->assertSame('80.00', $this->servicio()->elegir($b, self::ENTREGA, 'comida', $menu)->porcentaje_subsidio_congelado);
    }

    public function test_a_las_17_59_59_se_puede_y_a_las_18_00_00_ya_no(): void
    {
        $empleado = $this->empleadoDe($this->empresaConContrato());
        $menu = $this->platillo();
        $this->disponible($menu, self::ENTREGA);

        $this->ahoraEs('2026-11-11 17:59:59');
        $this->servicio()->elegir($empleado, self::ENTREGA, 'comida', $menu);

        $this->ahoraEs('2026-11-11 18:00:00');
        $otro = $this->platillo();
        $this->disponible($otro, self::ENTREGA);
        $this->falla('corte_cerrado', fn () => $this->servicio()->elegir($empleado, self::ENTREGA, 'comida', $otro));
        $this->falla('corte_cerrado', fn () => $this->servicio()->quitar($empleado, self::ENTREGA, 'comida'));
        $this->assertSame($menu->id, EleccionEmpleado::first()->menu_id, 'la elección anterior se conserva');
    }

    public function test_no_se_puede_elegir_para_una_fecha_cuyo_corte_ya_paso(): void
    {
        $empleado = $this->empleadoDe($this->empresaConContrato());
        $menu = $this->platillo();
        $this->disponible($menu, '2026-11-11');

        $this->falla('corte_cerrado', fn () => $this->servicio()->elegir($empleado, '2026-11-11', 'comida', $menu));
    }

    public function test_cambiar_de_platillo_antes_del_corte_vuelve_a_congelar_y_libera_la_porcion(): void
    {
        $empresa = $this->empresaConContrato('Zapopan', ['porcentaje_subsidio' => 60, 'precio_por_platillo' => 130]);
        $empleado = $this->empleadoDe($empresa);
        $a = $this->platillo();
        $b = $this->platillo();
        $dispA = $this->disponible($a, self::ENTREGA);
        $dispB = $this->disponible($b, self::ENTREGA);
        $this->servicio()->elegir($empleado, self::ENTREGA, 'comida', $a);

        ContratoEmpresa::where('empresa_id', $empresa->id)->update(['porcentaje_subsidio' => 50, 'precio_por_platillo' => 140]);
        $cambiada = $this->servicio()->elegir($empleado, self::ENTREGA, 'comida', $b);

        $this->assertSame(1, EleccionEmpleado::count(), 'sigue siendo una sola elección por día y tiempo');
        $this->assertSame($b->id, $cambiada->menu_id);
        $this->assertSame('140.00', $cambiada->precio_congelado, 'al decidir de nuevo se congela lo vigente en ese momento');
        $this->assertSame('50.00', $cambiada->porcentaje_subsidio_congelado);
        $this->assertSame(0, $dispA->fresh()->porciones_tomadas);
        $this->assertSame(1, $dispB->fresh()->porciones_tomadas);
    }

    public function test_elegir_el_mismo_platillo_otra_vez_no_cuenta_dos_veces(): void
    {
        $empleado = $this->empleadoDe($this->empresaConContrato());
        $menu = $this->platillo();
        $disp = $this->disponible($menu, self::ENTREGA);

        $this->servicio()->elegir($empleado, self::ENTREGA, 'comida', $menu);
        $this->servicio()->elegir($empleado, self::ENTREGA, 'comida', $menu);

        $this->assertSame(1, $disp->fresh()->porciones_tomadas);
    }

    public function test_quitar_la_eleccion_antes_del_corte_libera_la_porcion(): void
    {
        $empleado = $this->empleadoDe($this->empresaConContrato());
        $menu = $this->platillo();
        $disp = $this->disponible($menu, self::ENTREGA);
        $this->servicio()->elegir($empleado, self::ENTREGA, 'comida', $menu);

        $this->servicio()->quitar($empleado, self::ENTREGA, 'comida');

        $this->assertSame(0, EleccionEmpleado::count());
        $this->assertSame(0, $disp->fresh()->porciones_tomadas);
        $this->falla('sin_eleccion', fn () => $this->servicio()->quitar($empleado, self::ENTREGA, 'comida'));
    }

    public function test_sin_porciones_no_se_puede_elegir(): void
    {
        $menu = $this->platillo();
        $this->disponible($menu, self::ENTREGA, 1);
        $primero = $this->empleadoDe($this->empresaConContrato());
        $segundo = $this->empleadoDe($this->empresaConContrato());

        $this->servicio()->elegir($primero, self::ENTREGA, 'comida', $menu);
        $this->falla('sin_porciones', fn () => $this->servicio()->elegir($segundo, self::ENTREGA, 'comida', $menu));
        $this->assertSame(1, EleccionEmpleado::count());
    }

    public function test_el_platillo_debe_estar_publicado_disponible_y_ser_del_tiempo_de_comida(): void
    {
        $empleado = $this->empleadoDe($this->empresaConContrato());
        $borrador = $this->platillo(null, 'comida', ['estado_revision' => 'borrador']);
        $apagado = $this->platillo(null, 'comida', ['is_available' => false]);
        $cena = $this->platillo(null, 'cena');
        foreach ([$borrador, $apagado, $cena] as $m) {
            $this->disponible($m, self::ENTREGA);
        }

        $this->falla('platillo_no_disponible', fn () => $this->servicio()->elegir($empleado, self::ENTREGA, 'comida', $borrador));
        $this->falla('platillo_no_disponible', fn () => $this->servicio()->elegir($empleado, self::ENTREGA, 'comida', $apagado));
        $this->falla('platillo_no_disponible', fn () => $this->servicio()->elegir($empleado, self::ENTREGA, 'comida', $cena));
        $this->falla('platillo_no_disponible', fn () => $this->servicio()->elegir($empleado, self::ENTREGA, 'comida', 999999));
    }

    public function test_sin_disponibilidad_publicada_para_ese_dia_no_hay_porciones(): void
    {
        $empleado = $this->empleadoDe($this->empresaConContrato());
        $menu = $this->platillo();

        $this->falla('sin_porciones', fn () => $this->servicio()->elegir($empleado, self::ENTREGA, 'comida', $menu));
    }

    public function test_exige_contrato_vigente_dia_de_servicio_y_empleado_activo(): void
    {
        $menu = $this->platillo();
        foreach (['2026-11-12', '2026-11-14', '2026-11-16'] as $f) {
            $this->disponible($menu, $f);
        }

        $vencido = $this->empleadoDe($this->empresaConContrato('Zapopan', ['vigencia_fin' => '2026-11-11']));
        $this->falla('sin_contrato_vigente', fn () => $this->servicio()->elegir($vencido, self::ENTREGA, 'comida', $menu));

        $inactivoContrato = $this->empresaConContrato();
        ContratoEmpresa::where('empresa_id', $inactivoContrato->id)->update(['activo' => false, 'desactivado_en' => now()]);
        $this->falla('sin_contrato_vigente', fn () => $this->servicio()->elegir($this->empleadoDe($inactivoContrato), self::ENTREGA, 'comida', $menu));

        $empresa = $this->empresaConContrato();
        $empleado = $this->empleadoDe($empresa);
        DiaSinServicio::create(['fecha' => '2026-11-16', 'tipo' => 'cierre_empresa', 'motivo' => 'Cierre', 'empresa_id' => $empresa->id]);
        $this->falla('dia_sin_servicio', fn () => $this->servicio()->elegir($empleado, '2026-11-16', 'comida', $menu));
        $this->servicio()->elegir($empleado, '2026-11-14', 'comida', $menu); // sábado: sí hay servicio

        $empleado->desactivar();
        $this->falla('empleado_inactivo', fn () => $this->servicio()->elegir($empleado, self::ENTREGA, 'comida', $menu));
    }

    public function test_tras_un_corte_manual_anticipado_ya_no_se_elige_para_esa_fecha(): void
    {
        $empleado = $this->empleadoDe($this->empresaConContrato());
        $menu = $this->platillo();
        $this->disponible($menu, self::ENTREGA);

        app(EjecutorDelCorte::class)->ejecutar(self::ENTREGA, $this->usuario());

        $this->falla('corte_cerrado', fn () => $this->servicio()->elegir($empleado, self::ENTREGA, 'comida', $menu));
    }

    public function test_un_empleado_puede_elegir_un_platillo_por_cada_tiempo_de_comida(): void
    {
        $empleado = $this->empleadoDe($this->empresaConContrato());
        $comida = $this->platillo(null, 'comida');
        $cena = $this->platillo(null, 'cena');
        $this->disponible($comida, self::ENTREGA);
        $this->disponible($cena, self::ENTREGA);

        $this->servicio()->elegir($empleado, self::ENTREGA, 'comida', $comida);
        $this->servicio()->elegir($empleado, self::ENTREGA, 'cena', $cena);

        $this->assertSame(2, EleccionEmpleado::count());
    }
}
