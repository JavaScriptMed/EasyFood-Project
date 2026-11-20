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
use App\Services\CorteDeIndividuales;
use App\Services\EjecutorDelCorte;
use App\Services\EleccionDePlatillos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Los pedidos individuales llegan en UNA entrega el sábado, con su propio corte (el sábado a la hora de corte) y rutas SEPARADAS
 * de las de empresas, según la zona de su dirección. «Hoy» es el miércoles 11 de nov de 2026: el pedido llega el sábado 14 y se
 * come desde el lunes 16.
 */
class RutasConClientesIndividualesTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private const ENTREGA = '2026-11-14';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-11 10:00');
        // Dos zonas: Centro (por defecto, ±0.1° de -103.35, 20.67) y Zapopan, más al poniente.
        $this->zonaDePruebas('Centro');
        $this->zonaDePruebas('Zapopan', -103.55, 20.72, 0.05);
    }

    /** Un cliente con una dirección en la zona indicada, que pide 1 día pagado para el sábado 14. */
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

    /** El corte de individuales del sábado 14, a las 18:30. */
    private function corte()
    {
        $this->ahoraEs('2026-11-14 18:30');

        return app(CorteDeIndividuales::class)->ejecutar(self::ENTREGA);
    }

    /** El corte general del sábado 14 (empleados y empresas): el viernes a las 18:30. */
    private function corteDeEmpresas()
    {
        $this->ahoraEs('2026-11-13 18:30');

        return app(EjecutorDelCorte::class)->ejecutar(self::ENTREGA);
    }

    /** Un platillo con porciones el sábado 14 (para las elecciones de empleados) y el lunes 16 (el día en que se come lo de clientes). */
    private function menuDisponible(int $porciones = 50, string $tiempo = 'comida'): Menu
    {
        $menu = $this->platillo(null, $tiempo);
        $this->disponible($menu, self::ENTREGA, $porciones);
        foreach (['2026-11-16', '2026-11-17'] as $dia) {
            $this->disponible($menu, $dia, $porciones);
        }

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
        $this->assertSame($corte->id, $ruta->corte_id);
        $this->assertSame([1, 1], [$ruta->entregas_asignadas, $ruta->platillos_asignados]);
        $parada = Parada::sole();
        $this->assertSame([$ruta->id, $dia->id, null], [$parada->ruta_id, $parada->pedido_dia_id, $parada->entrega_empresa_id]);
        $this->assertSame($dia->pedido->cliente->name, $parada->nombre_destinatario);
        $this->assertSame('Av. Vallarta 100 int. 4B', $parada->direccion);
        $punto = DB::selectOne('select ST_X(ubicacion::geometry) as lon, ST_Y(ubicacion::geometry) as lat from paradas where id = ?', [$parada->id]);
        $this->assertEqualsWithDelta(-103.35, $punto->lon, 0.000001);
        $this->assertEqualsWithDelta(20.67, $punto->lat, 0.000001);
        $this->assertSame(['individuales', 'correcto'], [$corte->tipo, $corte->estado]);
        $this->assertNull($corte->mensaje);
    }

    public function test_un_pedido_de_varios_dias_y_comidas_es_una_sola_parada_con_todos_sus_platillos(): void
    {
        $desayuno = $this->menuDisponible(50, 'desayuno');
        $comida = $this->menuDisponible(50, 'comida');
        $cena = $this->menuDisponible(50, 'cena');
        $cliente = User::findOrFail($this->usuario());
        $direccion = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. Vallarta 100', 'latitude' => 20.67, 'longitude' => -103.35]);
        $paquete = Paquete::create(['nombre' => 'Dos días', 'dias' => 2, 'descuento_porcentaje' => 0]);
        $servicio = app(ContratacionDePaquetes::class);
        $dia = ['desayuno' => $desayuno, 'comida' => $comida, 'cena' => $cena];
        $pedido = $servicio->pedir($cliente, $paquete, $direccion, [$dia, $dia]);
        $servicio->pagar($pedido, 'tarjeta', '4821');
        $this->repartidorEn('Centro');

        $this->corte();

        $this->assertSame(1, Parada::count(), 'los dos días y las tres comidas viajan juntos');
        $ruta = Ruta::sole();
        $this->assertSame([1, 6], [$ruta->entregas_asignadas, $ruta->platillos_asignados], 'una entrega con 6 platillos (2 días × 3 comidas)');
        $ordenes = DB::table('ordenes_produccion')->orderBy('menu_id')->get();
        $this->assertCount(3, $ordenes, 'una orden de producción por platillo');
        $this->assertSame([2, 2, 2], $ordenes->pluck('porciones_individuales')->map(fn ($n) => (int) $n)->all(), 'cada platillo se pide dos veces: una por día');
        $this->assertSame(['2026-11-14'], $ordenes->pluck('fecha')->unique()->values()->all(), 'se produce para la entrega del sábado');
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

    public function test_el_corte_general_del_viernes_solo_arma_la_ruta_de_empresas_y_deja_a_los_clientes_para_el_sabado(): void
    {
        $menu = $this->menuDisponible();
        $empresa = $this->empresaConContrato('Centro', ['ventana_entrega_inicio' => '12:30', 'ventana_entrega_fin' => '12:50', 'hora_entrega' => '12:40']);
        app(EleccionDePlatillos::class)->elegir($this->empleadoDe($empresa), self::ENTREGA, 'comida', $menu);
        $dia = $this->pedidoPagado($menu);
        $this->repartidorEn('Centro');

        $this->corteDeEmpresas();

        $this->assertSame(1, Ruta::count(), 'el viernes solo sale la ruta de la empresa');
        $this->assertSame(0, Parada::whereNotNull('pedido_dia_id')->count());
        $this->assertSame(0, PedidoDia::where('id', $dia->id)->has('parada')->count());
        $this->assertSame(0, (int) DB::table('ordenes_produccion')->sum('porciones_individuales'), 'las porciones de clientes las suma su propio corte');
    }

    public function test_las_empresas_y_los_clientes_salen_en_rutas_separadas_aunque_las_haga_el_mismo_repartidor(): void
    {
        $menu = $this->menuDisponible();
        $empresa = $this->empresaConContrato('Centro', ['ventana_entrega_inicio' => '12:30', 'ventana_entrega_fin' => '12:50', 'hora_entrega' => '12:40']);
        app(EleccionDePlatillos::class)->elegir($this->empleadoDe($empresa), self::ENTREGA, 'comida', $menu);
        $a = $this->pedidoPagado($menu);
        $b = $this->pedidoPagado($menu);
        $repartidor = $this->repartidorEn('Centro'); // uno solo: la mañana para la empresa y el sábado después del corte para los clientes

        $this->corteDeEmpresas();
        $this->corte();

        $this->assertSame(2, Ruta::count());
        $deEmpresa = Ruta::whereHas('paradas', fn ($p) => $p->whereNotNull('entrega_empresa_id'))->sole();
        $deClientes = Ruta::whereHas('paradas', fn ($p) => $p->whereNotNull('pedido_dia_id'))->sole();
        $this->assertNotSame($deEmpresa->id, $deClientes->id);
        $this->assertSame([$repartidor->id, $repartidor->id], [$deEmpresa->repartidor_id, $deClientes->repartidor_id]);
        $this->assertSame(0, $deEmpresa->paradas()->whereNotNull('pedido_dia_id')->count(), 'la ruta de la empresa no lleva clientes');
        $this->assertSame(0, $deClientes->paradas()->whereNotNull('entrega_empresa_id')->count(), 'la de clientes no lleva empresas');
        $this->assertSame(['12:30:00', '12:50:00'], [$deEmpresa->ventana_inicio, $deEmpresa->ventana_fin]);

        $paradas = $deClientes->paradas()->orderBy('orden')->get();
        $this->assertSame([$a->id, $b->id], $paradas->pluck('pedido_dia_id')->all());
        $this->assertSame(['18:10:00', '18:20:00'], $paradas->pluck('hora_estimada')->all(), 'salen a la hora de corte y cada cliente se estima 10 min después del anterior');
        $this->assertSame(['18:00:00', '18:20:00'], [$deClientes->ventana_inicio, $deClientes->ventana_fin]);
    }

    public function test_una_ruta_de_clientes_arranca_a_la_hora_de_corte_del_sabado(): void
    {
        $this->ajuste('hora_corte', '17:00');
        $menu = $this->menuDisponible();
        $this->pedidoPagado($menu);
        $this->repartidorEn('Centro');

        $this->ahoraEs('2026-11-14 17:30');
        app(CorteDeIndividuales::class)->ejecutar(self::ENTREGA);

        $ruta = Ruta::sole();
        $this->assertSame(['17:00:00', '17:10:00'], [$ruta->ventana_inicio, $ruta->ventana_fin]);
        $this->assertSame('17:10:00', Parada::sole()->hora_estimada);
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

    public function test_los_platillos_de_un_pedido_cuentan_para_la_capacidad_en_platillos_del_auto(): void
    {
        $this->ajuste('capacidad_auto_platillos', '4');
        $menu = $this->menuDisponible();
        $cliente = User::findOrFail($this->usuario());
        $direccion = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. Vallarta 100', 'latitude' => 20.67, 'longitude' => -103.35]);
        $servicio = app(ContratacionDePaquetes::class);
        $dos = Paquete::create(['nombre' => 'Dos días', 'dias' => 2, 'descuento_porcentaje' => 0]);
        foreach (range(1, 3) as $i) {
            $servicio->pagar($servicio->pedir($cliente, $dos, $direccion, [$menu, $menu]), 'tarjeta', '4821'); // 2 platillos cada uno
        }
        $this->repartidorEn('Centro');
        $this->repartidorEn('Centro');

        $this->corte();

        $this->assertSame([2, 1], Ruta::orderBy('id')->pluck('entregas_asignadas')->map(fn ($n) => (int) $n)->all(), 'un auto de 4 platillos lleva dos pedidos de 2; el tercero va en otro');
        $this->assertSame([4, 2], Ruta::orderBy('id')->pluck('platillos_asignados')->map(fn ($n) => (int) $n)->all());
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
        $antes = [Ruta::count(), Parada::count(), Incidencia::count(), DB::table('ordenes_produccion')->sum('porciones_individuales')];

        $segundo = app(CorteDeIndividuales::class)->ejecutar(self::ENTREGA);

        $this->assertTrue($segundo->sin_cambios);
        $this->assertSame($antes, [Ruta::count(), Parada::count(), Incidencia::count(), DB::table('ordenes_produccion')->sum('porciones_individuales')]);
    }

    public function test_los_pedidos_de_lunes_a_viernes_y_del_sabado_antes_de_la_hora_de_corte_entran_al_reparto_y_el_siguiente_no(): void
    {
        $menu = $this->menuDisponible(50);
        foreach (['2026-11-23', '2026-11-24'] as $dia) {
            $this->disponible($menu, $dia, 50); // los días de comida del pedido del sábado siguiente
        }
        $this->repartidorEn('Centro');

        $this->ahoraEs('2026-11-09 08:00'); // lunes
        $lunes = $this->pedidoPagado($menu);
        $this->ahoraEs('2026-11-13 23:00'); // viernes por la noche
        $viernes = $this->pedidoPagado($menu);
        $this->ahoraEs('2026-11-14 17:59'); // sábado antes de la hora de corte
        $sabadoAntes = $this->pedidoPagado($menu);
        $this->ahoraEs('2026-11-14 18:00'); // sábado, ya cerró: pasa al siguiente
        $sabadoDespues = $this->pedidoPagado($menu);

        $this->assertSame(['2026-11-14', '2026-11-14', '2026-11-14', '2026-11-21'], array_map(fn (PedidoDia $d) => $d->fecha->toDateString(), [$lunes, $viernes, $sabadoAntes, $sabadoDespues]));

        $this->corte();

        $conParada = Parada::pluck('pedido_dia_id')->all();
        $this->assertEqualsCanonicalizing([$lunes->id, $viernes->id, $sabadoAntes->id], $conParada);
        $this->assertNotContains($sabadoDespues->id, $conParada, 'el pedido del sábado después del corte espera al sábado siguiente');
        $this->assertSame('programada', $sabadoDespues->fresh()->estado);
        $this->assertSame('recibido', $sabadoDespues->pedido->fresh()->estado);
    }

    public function test_el_corte_manual_se_puede_adelantar_pero_solo_para_sabados_de_servicio(): void
    {
        $menu = $this->menuDisponible();
        $this->pedidoPagado($menu);
        $this->repartidorEn('Centro');

        $corte = app(CorteDeIndividuales::class)->ejecutar(self::ENTREGA, $this->usuario()); // miércoles 10:00, a mano

        $this->assertSame([false, 1], [$corte->sin_cambios, Ruta::count()]);
        foreach (['2026-11-13' => 'viernes', '2026-11-15' => 'domingo'] as $fecha => $dia) {
            try {
                app(CorteDeIndividuales::class)->ejecutar($fecha, $this->usuario());
                $this->fail("Un {$dia} no es sábado de entrega.");
            } catch (\App\Exceptions\ReglaDeNegocioException $e) {
                $this->assertSame('dia_sin_entrega_de_individuales', $e->codigo);
            }
        }
        $this->ahoraEs('2026-11-14 17:00');
        try {
            app(CorteDeIndividuales::class)->ejecutar('2026-11-21'); // el del sistema no corre antes de su hora
            $this->fail('El corte automático no corre antes de la hora de corte.');
        } catch (\App\Exceptions\ReglaDeNegocioException $e) {
            $this->assertSame('corte_prematuro', $e->codigo);
        }
    }

    public function test_el_corte_de_individuales_suma_sus_porciones_a_la_orden_que_ya_hicieron_las_empresas(): void
    {
        $menu = $this->menuDisponible();
        $empresa = $this->empresaConContrato('Centro', ['ventana_entrega_inicio' => '12:30', 'ventana_entrega_fin' => '12:50', 'hora_entrega' => '12:40']);
        app(EleccionDePlatillos::class)->elegir($this->empleadoDe($empresa), self::ENTREGA, 'comida', $menu);
        $this->pedidoPagado($menu);
        $this->pedidoPagado($menu);
        $this->repartidorEn('Centro');
        $this->corteDeEmpresas();
        DB::table('ordenes_produccion')->update(['estado' => 'lista']); // el comedor ya había marcado la orden de las empresas como lista

        $this->corte();

        $orden = DB::table('ordenes_produccion')->sole();
        $this->assertSame([3, 1, 2, '11:00:00', 'pendiente'], [(int) $orden->porciones_totales, (int) $orden->porciones_empresas, (int) $orden->porciones_individuales, $orden->hora_recoleccion, $orden->estado], 'conserva la recolección de las empresas y vuelve a pendiente');
    }

    public function test_un_corte_general_posterior_no_borra_las_porciones_de_individuales(): void
    {
        $menu = $this->menuDisponible();
        $empresa = $this->empresaConContrato('Centro');
        app(EleccionDePlatillos::class)->elegir($this->empleadoDe($empresa), self::ENTREGA, 'comida', $menu);
        $this->pedidoPagado($menu);
        $this->repartidorEn('Centro');
        app(CorteDeIndividuales::class)->ejecutar(self::ENTREGA, $this->usuario()); // se adelantó a mano, antes que el de empresas

        $this->corteDeEmpresas();

        $orden = DB::table('ordenes_produccion')->sole();
        $this->assertSame([2, 1, 1], [(int) $orden->porciones_totales, (int) $orden->porciones_empresas, (int) $orden->porciones_individuales]);
    }

    public function test_el_nombre_de_la_zona_de_la_empresa_y_el_de_la_direccion_se_comparan_sin_mayusculas_ni_espacios(): void
    {
        $menu = $this->menuDisponible();
        $this->zonaDePruebas('Tlaquepaque', -103.30, 20.64, 0.02);
        $empresa = $this->empresaConContrato('  tlaquepaque ');
        app(EleccionDePlatillos::class)->elegir($this->empleadoDe($empresa), self::ENTREGA, 'comida', $menu);
        $this->pedidoPagado($menu, -103.30, 20.64);
        $this->repartidorEn('TLAQUEPAQUE');

        $this->corteDeEmpresas();
        $this->corte();

        $this->assertSame(2, Ruta::count(), 'la ruta de la empresa y la de los clientes, cada una en su zona');
        $this->assertSame([1, 1], Ruta::orderBy('id')->pluck('entregas_asignadas')->map(fn ($n) => (int) $n)->all());
    }
}
