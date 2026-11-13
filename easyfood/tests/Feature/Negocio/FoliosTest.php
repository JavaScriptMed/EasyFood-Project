<?php

namespace Tests\Feature\Negocio;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Address;
use App\Models\Paquete;
use App\Models\User;
use App\Services\ContratacionDePaquetes;
use App\Services\Folios;
use App\Services\GeneradorDeFacturas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** Folios: EL-AAAAMM-NNN para facturas y GDL-AAAAMMDD-NNNN para pedidos, con consecutivos que no se repiten. */
class FoliosTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    public function test_el_consecutivo_avanza_de_uno_en_uno_por_clave(): void
    {
        $this->assertSame([1, 2, 3], [Folios::siguiente('x:a'), Folios::siguiente('x:a'), Folios::siguiente('x:a')]);
        $this->assertSame(1, Folios::siguiente('x:b'), 'cada clave lleva su propia cuenta');
    }

    public function test_las_facturas_de_un_mes_llevan_consecutivo_y_el_mes_siguiente_reinicia(): void
    {
        $this->ahoraEs('2026-12-03 09:00');
        $facturas = app(GeneradorDeFacturas::class);
        $folios = [];
        foreach (range(1, 3) as $i) {
            $folios[] = $facturas->generar($this->empresaConContrato(), '2026-10-01')->folio;
        }
        $noviembre = $facturas->generar($this->empresaConContrato(), '2026-11-01')->folio;

        $this->assertSame(['EL-202610-001', 'EL-202610-002', 'EL-202610-003'], $folios);
        $this->assertSame('EL-202611-001', $noviembre);
    }

    public function test_regenerar_un_borrador_conserva_su_folio_y_no_gasta_consecutivos(): void
    {
        $this->ahoraEs('2026-11-05 09:00');
        $facturas = app(GeneradorDeFacturas::class);
        $empresa = $this->empresaConContrato();

        $primero = $facturas->generar($empresa, '2026-10-01')->folio;
        $facturas->generar($empresa, '2026-10-01');
        $facturas->generar($empresa, '2026-10-01');
        $otra = $facturas->generar($this->empresaConContrato(), '2026-10-01')->folio;

        $this->assertSame(['EL-202610-001', 'EL-202610-002'], [$primero, $otra]);
    }

    public function test_los_pedidos_llevan_consecutivo_por_dia_y_reinician_cada_dia(): void
    {
        $this->zonaDePruebas();
        $cliente = User::findOrFail($this->usuario());
        $direccion = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. 1', 'latitude' => 20.67, 'longitude' => -103.35]);
        $menu = $this->platillo();
        foreach (['2026-11-10', '2026-11-11'] as $f) {
            $this->disponible($menu, $f);
        }
        $paquete = Paquete::create(['nombre' => 'Un día', 'dias' => 1]);
        $pedidos = app(ContratacionDePaquetes::class);

        $this->ahoraEs('2026-11-09 10:00');
        $a = $pedidos->pedir($cliente, $paquete, $direccion, [$menu])->folio;
        $b = $pedidos->pedir($cliente, $paquete, $direccion, [$menu])->folio;
        $this->ahoraEs('2026-11-10 10:00');
        $c = $pedidos->pedir($cliente, $paquete, $direccion, [$menu])->folio;

        $this->assertSame(['GDL-20261109-0001', 'GDL-20261109-0002', 'GDL-20261110-0001'], [$a, $b, $c]);
    }

    public function test_un_pedido_que_falla_no_gasta_folio(): void
    {
        $this->zonaDePruebas();
        $cliente = User::findOrFail($this->usuario());
        $direccion = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. 1', 'latitude' => 20.67, 'longitude' => -103.35]);
        $menu = $this->platillo();
        $this->disponible($menu, '2026-11-10', 0);
        $paquete = Paquete::create(['nombre' => 'Un día', 'dias' => 1]);
        $this->ahoraEs('2026-11-09 10:00');

        try {
            app(ContratacionDePaquetes::class)->pedir($cliente, $paquete, $direccion, [$menu]);
            $this->fail('Debía fallar por falta de porciones.');
        } catch (ReglaDeNegocioException) {
        }
        DB::table('disponibilidad_dia')->update(['porciones_maximas' => 5]);

        $this->assertSame('GDL-20261109-0001', app(ContratacionDePaquetes::class)->pedir($cliente, $paquete, $direccion, [$menu])->folio, 'el intento fallido no dejó hueco');
    }

    public function test_la_base_impide_folios_repetidos(): void
    {
        $this->ahoraEs('2026-11-05 09:00');
        $factura = app(GeneradorDeFacturas::class)->generar($this->empresaConContrato(), '2026-10-01');

        $this->rechaza('facturas_mensuales_folio_unique', fn () => DB::table('facturas_mensuales')->insert([
            'folio' => $factura->folio, 'empresa_id' => $this->empresaConContrato()->id, 'mes' => '2026-10-01', 'platillos' => 0,
            'parte_empresa' => 0, 'parte_empleados' => 0, 'total' => 0,
        ]));
    }
}
