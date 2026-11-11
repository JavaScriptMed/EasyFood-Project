<?php

namespace Tests\Feature\Negocio;

use App\Models\Address;
use App\Models\Incidencia;
use App\Models\Menu;
use App\Models\Paquete;
use App\Models\Parada;
use App\Models\PedidoDia;
use App\Models\Ruta;
use App\Models\User;
use App\Services\ContratacionDePaquetes;
use App\Services\EjecutorDelCorte;
use App\Services\EleccionDePlatillos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Los pedidos individuales entran a las rutas del corte según la zona de su dirección, junto con las entregas a empresas.
 * «Hoy» es el miércoles 11 de nov de 2026; se corta la entrega del jueves 12 a las 18:30.
 */
class RutasConClientesIndividualesTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private const ENTREGA = '2026-11-12';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-11 10:00');
        // Dos zonas: Centro (por defecto, ±0.1° de -103.35, 20.67) y Zapopan, más al poniente.
        $this->zonaDePruebas('Centro');
        $this->zonaDePruebas('Zapopan', -103.55, 20.72, 0.05);
    }

    /** Un cliente con una dirección en la zona indicada, que pide 1 día pagado para el jueves 12. */
    private function pedidoPagado(Menu $menu, float $lon = -103.35, float $lat = 20.67, bool $pagar = true): PedidoDia
    {
        $cliente = User::findOrFail($this->usuario());
        $direccion = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. Vallarta 100', 'internal_number' => '4B', 'latitude' => $lat, 'longitude' => $lon]);
        $paquete = Paquete::firstOrCreate(['nombre' => 'Un día'], ['dias' => 1, 'descuento_porcentaje' => 0]);
        $servicio = app(ContratacionDePaquetes::class);
        $pedido = $servicio->pedir($cliente, $paquete, $direccion, [$menu]);
        if ($pagar) {
            $servicio->pagar($pedido, 'tarjeta', '4821');
        }

        return PedidoDia::where('pedido_id', $pedido->id)->sole();
    }

    private function corte()
    {
        $this->ahoraEs('2026-11-11 18:30');

        return app(EjecutorDelCorte::class)->ejecutar(self::ENTREGA);
    }

    private function menuDisponible(int $porciones = 50): Menu
    {
        $menu = $this->platillo();
        $this->disponible($menu, self::ENTREGA, $porciones);

        return $menu;
    }

    public function test_un_pedido_individual_pagado_entra_a_la_ruta_de_su_zona_con_su_punto_en_el_mapa(): void
    {
        $menu = $this->menuDisponible();
        $dia = $this->pedidoPagado($menu);
        $repartidor = $this->repartidorEn('Centro');

        $corte = $this->corte();

        $ruta = Ruta::sole();
        $this->assertSame($repartidor->id, $ruta->repartidor_id);
        $this->assertSame([1, 1], [$ruta->entregas_asignadas, $ruta->platillos_asignados]);
        $parada = Parada::sole();
        $this->assertSame([$ruta->id, $dia->id, null], [$parada->ruta_id, $parada->pedido_dia_id, $parada->entrega_empresa_id]);
        $this->assertSame($dia->pedido->cliente->name, $parada->nombre_destinatario);
        $this->assertSame('Av. Vallarta 100 int. 4B', $parada->direccion);
        $punto = DB::selectOne('select ST_X(ubicacion::geometry) as lon, ST_Y(ubicacion::geometry) as lat from paradas where id = ?', [$parada->id]);
        $this->assertEqualsWithDelta(-103.35, $punto->lon, 0.000001);
        $this->assertEqualsWithDelta(20.67, $punto->lat, 0.000001);
        $this->assertSame('correcto', $corte->estado, 'ya no hay advertencia diaria por clientes individuales');
        $this->assertNull($corte->mensaje);
    }

    public function test_un_pedido_por_pagar_no_entra_a_la_ruta(): void
    {
        $menu = $this->menuDisponible();
        $this->pedidoPagado($menu, pagar: false);
        $this->repartidorEn('Centro');

        $this->corte();

        $this->assertSame(0, Ruta::count());
        $this->assertSame(0, Parada::count());
    }

    public function test_las_entregas_a_empresas_van_primero_y_los_clientes_despues_en_la_misma_ruta(): void
    {
        $menu = $this->menuDisponible();
        $empresa = $this->empresaConContrato('Centro', ['ventana_entrega_inicio' => '12:30', 'ventana_entrega_fin' => '12:50', 'hora_entrega' => '12:40']);
        $empleado = $this->empleadoDe($empresa);
        app(EleccionDePlatillos::class)->elegir($empleado, self::ENTREGA, 'comida', $menu);
        $a = $this->pedidoPagado($menu);
        $b = $this->pedidoPagado($menu);
        $this->repartidorEn('Centro');

        $this->corte();

        $ruta = Ruta::sole();
        $this->assertSame([3, 3], [$ruta->entregas_asignadas, $ruta->platillos_asignados]);
        $paradas = Parada::where('ruta_id', $ruta->id)->orderBy('orden')->get();
        $this->assertSame([$empresa->nombre], [$paradas[0]->nombre_destinatario]);
        $this->assertSame([$a->id, $b->id], [$paradas[1]->pedido_dia_id, $paradas[2]->pedido_dia_id]);
        $this->assertSame('12:30:00', $ruta->ventana_inicio);
        $this->assertSame(['12:30:00', '13:00:00', '13:10:00'], $paradas->pluck('hora_estimada')->all(), 'cada cliente se estima 10 min después del anterior, tras las empresas');
        $this->assertSame('13:10:00', $ruta->ventana_fin);
    }

    public function test_una_ruta_solo_de_clientes_arranca_a_las_12(): void
    {
        $menu = $this->menuDisponible();
        $this->pedidoPagado($menu);
        $this->repartidorEn('Centro');

        $this->corte();

        $ruta = Ruta::sole();
        $this->assertSame(['12:00:00', '12:10:00'], [$ruta->ventana_inicio, $ruta->ventana_fin]);
        $this->assertSame('12:10:00', Parada::sole()->hora_estimada);
    }

    public function test_cada_zona_usa_su_propio_repartidor(): void
    {
        $menu = $this->menuDisponible();
        $centro = $this->pedidoPagado($menu);
        $zapopan = $this->pedidoPagado($menu, -103.55, 20.72);
        $repartidorCentro = $this->repartidorEn('Centro');
        $repartidorZapopan = $this->repartidorEn('Zapopan');

        $this->corte();

        $this->assertSame($repartidorCentro->id, Parada::where('pedido_dia_id', $centro->id)->first()->ruta->repartidor_id);
        $this->assertSame($repartidorZapopan->id, Parada::where('pedido_dia_id', $zapopan->id)->first()->ruta->repartidor_id);
        $this->assertSame(['Centro', 'Zapopan'], Ruta::orderBy('id')->pluck('zona')->all());
    }

    public function test_las_entregas_individuales_cuentan_para_la_capacidad_del_auto(): void
    {
        $this->ajuste('capacidad_auto_entregas', '2');
        $menu = $this->menuDisponible();
        $dias = [$this->pedidoPagado($menu), $this->pedidoPagado($menu), $this->pedidoPagado($menu)];
        $this->repartidorEn('Centro');
        $this->repartidorEn('Centro');

        $this->corte();

        $this->assertSame([2, 1], Ruta::orderBy('id')->pluck('entregas_asignadas')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(3, Parada::count());
        $this->assertNotNull($dias);
    }

    public function test_sin_repartidor_en_la_zona_el_pedido_queda_sin_ruta_con_una_incidencia(): void
    {
        $menu = $this->menuDisponible();
        $dia = $this->pedidoPagado($menu, -103.55, 20.72); // Zapopan, pero solo hay repartidor en Centro
        $this->repartidorEn('Centro');

        $corte = $this->corte();

        $this->assertSame(0, Parada::count());
        $incidencia = Incidencia::sole();
        $this->assertSame(['falta_repartidores', 'inmediata', 'abierta', $dia->id], [$incidencia->tipo, $incidencia->prioridad, $incidencia->estado, $incidencia->pedido_dia_id]);
        $this->assertStringContainsString($dia->pedido->folio, $incidencia->descripcion);
        $this->assertSame('con_advertencias', $corte->estado);
    }

    public function test_una_direccion_que_perdio_cobertura_despues_de_pedir_queda_sin_ruta_con_incidencia(): void
    {
        $menu = $this->menuDisponible();
        $dia = $this->pedidoPagado($menu);
        $this->repartidorEn('Centro');
        DB::table('zonas')->where('nombre', 'Centro')->update(['activo' => false, 'desactivado_en' => now()]);

        $this->corte();

        $this->assertSame(0, Parada::count());
        $incidencia = Incidencia::sole();
        $this->assertSame(['otro', $dia->id], [$incidencia->tipo, $incidencia->pedido_dia_id]);
        $this->assertStringContainsString('fuera de cobertura', $incidencia->descripcion);
    }

    public function test_repetir_el_corte_no_duplica_las_paradas_de_clientes(): void
    {
        $menu = $this->menuDisponible();
        $this->pedidoPagado($menu);
        $this->pedidoPagado($menu);
        $this->repartidorEn('Centro');
        $this->corte();
        $antes = [Ruta::count(), Parada::count(), Incidencia::count()];

        $segundo = app(EjecutorDelCorte::class)->ejecutar(self::ENTREGA);

        $this->assertTrue($segundo->sin_cambios);
        $this->assertSame($antes, [Ruta::count(), Parada::count(), Incidencia::count()]);
    }

    public function test_el_nombre_de_la_zona_de_la_empresa_y_el_de_la_direccion_se_comparan_sin_mayusculas_ni_espacios(): void
    {
        $menu = $this->menuDisponible();
        $this->zonaDePruebas('Tlaquepaque', -103.30, 20.64, 0.02);
        $empresa = $this->empresaConContrato('  tlaquepaque ');
        app(EleccionDePlatillos::class)->elegir($this->empleadoDe($empresa), self::ENTREGA, 'comida', $menu);
        $this->pedidoPagado($menu, -103.30, 20.64);
        $this->repartidorEn('TLAQUEPAQUE');

        $this->corte();

        $this->assertSame(2, Ruta::sole()->entregas_asignadas, 'la empresa y el cliente comparten ruta');
    }
}
