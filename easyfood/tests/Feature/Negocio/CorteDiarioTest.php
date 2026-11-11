<?php

namespace Tests\Feature\Negocio;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Corte;
use App\Models\DiaSinServicio;
use App\Models\DisponibilidadDia;
use App\Models\EleccionEmpleado;
use App\Models\Empresa;
use App\Models\EntregaEmpresa;
use App\Models\Incidencia;
use App\Models\Menu;
use App\Models\OrdenProduccion;
use App\Models\Parada;
use App\Models\Paquete;
use App\Models\Ruta;
use App\Services\ContratacionDePaquetes;
use App\Services\EjecutorDelCorte;
use App\Services\EleccionDePlatillos;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Corte diario: cierra elecciones, aplica la regla a quien no eligió, genera órdenes por comedor, consolida una
 * entrega por empresa y crea rutas por capacidad del auto; ejecutarlo dos veces no cambia nada.
 * «Hoy» es el miércoles 11 de nov de 2026 a las 18:30 y se corta la entrega del jueves 12.
 */
class CorteDiarioTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private const ENTREGA = '2026-11-12';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-11 18:30');
    }

    private function corte(?int $usuario = null): Corte
    {
        return app(EjecutorDelCorte::class)->ejecutar(self::ENTREGA, $usuario);
    }

    /** Hace que el empleado haya elegido $menu en una fecha ya cerrada (historial para «último platillo»). */
    private function historial($empleado, Menu $menu, string $fecha = '2026-11-10'): void
    {
        DB::table('elecciones_empleado')->insert([
            'empleado_id' => $empleado->id, 'fecha' => $fecha, 'tiempo_comida' => $menu->tiempo_comida, 'menu_id' => $menu->id,
            'precio_congelado' => 130, 'porcentaje_subsidio_congelado' => 60, 'origen' => 'manual', 'cerrada_en' => '2026-11-09 18:00:00',
        ]);
    }

    private function eligeManual($empleado, Menu $menu): void
    {
        $this->ahoraEs('2026-11-11 10:00');
        app(EleccionDePlatillos::class)->elegir($empleado, self::ENTREGA, $menu->tiempo_comida, $menu);
        $this->ahoraEs('2026-11-11 18:30');
    }

    /** @return array{0: Empresa, 1: Empresa, 2: Menu, 3: Menu} dos empresas de Zapopan con elecciones de dos comedores */
    private function escenarioBase(): array
    {
        $a = $this->empresaConContrato('Zapopan', ['ventana_entrega_inicio' => '12:30', 'ventana_entrega_fin' => '12:50']);
        $b = $this->empresaConContrato('Zapopan', ['ventana_entrega_inicio' => '13:00', 'ventana_entrega_fin' => '13:20', 'hora_entrega' => '13:10']);
        $menuNorte = $this->platillo($this->comedorNuevo());
        $menuSur = $this->platillo($this->comedorNuevo());
        $this->disponible($menuNorte, self::ENTREGA);
        $this->disponible($menuSur, self::ENTREGA);

        foreach ([[$a, $menuNorte], [$a, $menuNorte], [$a, $menuSur], [$b, $menuNorte], [$b, $menuSur]] as [$empresa, $menu]) {
            $this->eligeManual($this->empleadoDe($empresa), $menu);
        }

        return [$a, $b, $menuNorte, $menuSur];
    }

    // ------------------------------------------------------------------ cierra elecciones

    public function test_cierra_las_elecciones_y_despues_ya_nadie_cambia_ni_agrega(): void
    {
        [$a, , $menuNorte, $menuSur] = $this->escenarioBase();

        $corte = $this->corte();

        $this->assertSame(5, $corte->elecciones_cerradas);
        $this->assertSame(0, EleccionEmpleado::whereNull('cerrada_en')->count());
        $this->assertSame('correcto' === $corte->estado || 'con_advertencias' === $corte->estado, true);

        $nuevo = $this->empleadoDe($a);
        $this->expectException(ReglaDeNegocioException::class);
        $this->ahoraEs('2026-11-11 18:31');
        app(EleccionDePlatillos::class)->elegir($nuevo, self::ENTREGA, 'comida', $menuSur);
    }

    public function test_la_base_rechaza_cambiar_una_eleccion_cerrada(): void
    {
        $this->escenarioBase();
        $this->corte();

        $this->rechaza('ya fue cerrada por el corte', fn () => DB::table('elecciones_empleado')->update(['menu_id' => Menu::first()->id]));
    }

    // ------------------------------------------------------------------ órdenes y entregas

    public function test_genera_una_orden_por_comedor_y_platillo_con_sus_porciones(): void
    {
        [, , $menuNorte, $menuSur] = $this->escenarioBase();

        $corte = $this->corte();

        $this->assertSame(2, $corte->ordenes_generadas);
        $norte = OrdenProduccion::where('menu_id', $menuNorte->id)->first();
        $this->assertSame($menuNorte->provider_id, $norte->comedor_id);
        $this->assertSame([3, 3, 0], [$norte->porciones_totales, $norte->porciones_empresas, $norte->porciones_individuales]);
        $this->assertSame('pendiente', $norte->estado);
        $this->assertSame('11:00:00', $norte->hora_recoleccion, '90 minutos antes de la primera ventana (12:30) que atiende');
        $this->assertSame(2, OrdenProduccion::where('menu_id', $menuSur->id)->first()->porciones_totales);
        $this->assertSame($corte->id, $norte->corte_id);
    }

    public function test_la_orden_suma_los_pedidos_pagados_de_clientes_individuales(): void
    {
        $this->empresaConContrato();
        $menu = $this->platillo();
        $this->disponible($menu, self::ENTREGA);
        $this->zonaDePruebas();
        $cliente = \App\Models\User::findOrFail($this->usuario());
        $direccion = \App\Models\Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. Vallarta 100', 'latitude' => 20.67, 'longitude' => -103.35]);
        $paquete = Paquete::create(['nombre' => 'Un día', 'dias' => 1, 'descuento_porcentaje' => 0]);

        $this->ahoraEs('2026-11-11 10:00');
        $servicio = app(ContratacionDePaquetes::class);
        $pagado = $servicio->pagar($servicio->pedir($cliente, $paquete, $direccion, [$menu]), 'tarjeta', '4821');
        $servicio->pedir($cliente, $paquete, $direccion, [$menu]); // queda pendiente de pago: no entra al corte
        $this->ahoraEs('2026-11-11 18:30');

        $corte = $this->corte();

        $orden = OrdenProduccion::first();
        $this->assertSame([1, 0, 1], [$orden->porciones_totales, $orden->porciones_empresas, $orden->porciones_individuales]);
        $this->assertSame('recibido', $pagado->estado);
        $this->assertSame('con_advertencias', $corte->estado, 'sin repartidor en la zona queda sin ruta');
        $this->assertStringContainsString('sin ruta', $corte->mensaje);
        $this->assertStringNotContainsString('aún falta definir', $corte->mensaje);
    }

    public function test_consolida_una_sola_entrega_por_empresa_con_la_ventana_de_su_contrato(): void
    {
        [$a, $b] = $this->escenarioBase();

        $corte = $this->corte();

        $this->assertSame(2, $corte->entregas_consolidadas);
        $entregaA = EntregaEmpresa::where('empresa_id', $a->id)->sole();
        $this->assertSame(3, $entregaA->platillos);
        $this->assertSame(['12:30:00', '12:50:00', 'programada'], [$entregaA->ventana_inicio, $entregaA->ventana_fin, $entregaA->estado]);
        $this->assertSame(2, EntregaEmpresa::where('empresa_id', $b->id)->sole()->platillos);
    }

    public function test_una_empresa_con_cierre_ese_dia_no_recibe_platillos_por_defecto(): void
    {
        $empresa = $this->empresaConContrato();
        $empleado = $this->empleadoDe($empresa);
        $menu = $this->platillo();
        $this->disponible($menu, self::ENTREGA);
        $this->historial($empleado, $menu);
        DiaSinServicio::create(['fecha' => self::ENTREGA, 'tipo' => 'cierre_empresa', 'motivo' => 'Inventario', 'empresa_id' => $empresa->id]);

        $corte = $this->corte();

        $this->assertSame(0, $corte->empleados_por_defecto);
        $this->assertSame(0, EntregaEmpresa::count());
    }

    // ------------------------------------------------------------------ quien no eligió

    public function test_regla_ultimo_platillo_repite_el_ultimo_y_congela_precio_y_subsidio_del_contrato(): void
    {
        $this->ajuste('regla_sin_eleccion', 'ultimo_platillo');
        $empresa = $this->empresaConContrato('Zapopan', ['porcentaje_subsidio' => 75, 'precio_por_platillo' => 135]);
        $empleado = $this->empleadoDe($empresa);
        $anterior = $this->platillo();
        $ultimo = $this->platillo();
        $this->disponible($ultimo, self::ENTREGA);
        $this->historial($empleado, $anterior, '2026-11-09');
        $this->historial($empleado, $ultimo, '2026-11-10');
        $this->repartidorEn('Zapopan');

        $corte = $this->corte();

        $eleccion = EleccionEmpleado::whereDate('fecha', self::ENTREGA)->sole();
        $this->assertSame($ultimo->id, $eleccion->menu_id);
        $this->assertSame('por_defecto', $eleccion->origen);
        $this->assertSame(['135.00', '75.00'], [$eleccion->precio_congelado, $eleccion->porcentaje_subsidio_congelado]);
        $this->assertNotNull($eleccion->cerrada_en);
        $this->assertSame(1, $corte->empleados_por_defecto);
        $this->assertSame(1, DisponibilidadDia::where('menu_id', $ultimo->id)->first()->porciones_tomadas);
        $this->assertSame('correcto', $corte->estado);
    }

    public function test_quien_si_eligio_no_recibe_platillo_por_defecto(): void
    {
        $this->ajuste('regla_sin_eleccion', 'ultimo_platillo');
        $empleado = $this->empleadoDe($this->empresaConContrato());
        $viejo = $this->platillo();
        $elegido = $this->platillo();
        $this->disponible($viejo, self::ENTREGA);
        $this->disponible($elegido, self::ENTREGA);
        $this->historial($empleado, $viejo);
        $this->eligeManual($empleado, $elegido);

        $corte = $this->corte();

        $this->assertSame($elegido->id, EleccionEmpleado::whereDate('fecha', self::ENTREGA)->sole()->menu_id);
        $this->assertSame(0, $corte->empleados_por_defecto);
    }

    public function test_si_el_ultimo_platillo_no_esta_disponible_queda_sin_entrega_y_con_advertencia(): void
    {
        $this->ajuste('regla_sin_eleccion', 'ultimo_platillo');
        $empleado = $this->empleadoDe($this->empresaConContrato());
        $sinDisponibilidad = $this->platillo();
        $agotado = $this->platillo();
        $this->disponible($agotado, self::ENTREGA, 0);
        $nuncaElegio = $this->empleadoDe($this->empresaConContrato());
        $otro = $this->empleadoDe($this->empresaConContrato());
        $this->historial($empleado, $sinDisponibilidad);
        $this->historial($otro, $agotado);

        $corte = $this->corte();

        $this->assertSame(0, EleccionEmpleado::whereDate('fecha', self::ENTREGA)->count());
        $this->assertSame('con_advertencias', $corte->estado);
        $this->assertStringContainsString('3 elección(es) por defecto quedaron sin entrega', $corte->mensaje);
        $this->assertNotNull($nuncaElegio->id);
    }

    public function test_regla_platillo_del_dia_usa_el_que_marco_el_comedor_para_ese_tiempo_de_comida(): void
    {
        $this->ajuste('regla_sin_eleccion', 'platillo_del_dia');
        $empleado = $this->empleadoDe($this->empresaConContrato());
        $comedor = $this->comedorConContrato();
        $normal = $this->platillo($comedor);
        $delDia = $this->platillo($comedor);
        $cena = $this->platillo($comedor, 'cena');
        $this->disponible($normal, self::ENTREGA);
        $this->disponible($delDia, self::ENTREGA, 50, true);
        $this->disponible($cena, self::ENTREGA, 50, true);
        $this->historial($empleado, $normal);

        $this->corte();

        $eleccion = EleccionEmpleado::whereDate('fecha', self::ENTREGA)->sole();
        $this->assertSame([$delDia->id, 'por_defecto', 'comida'], [$eleccion->menu_id, $eleccion->origen, $eleccion->tiempo_comida]);
    }

    public function test_un_comedor_solo_puede_marcar_un_platillo_del_dia_por_fecha_y_tiempo_de_comida(): void
    {
        $comedor = $this->comedorNuevo();
        $uno = $this->platillo($comedor);
        $dos = $this->platillo($comedor);
        $this->disponible($uno, self::ENTREGA, 50, true);

        $this->rechaza('ya marcó otro platillo del día', fn () => $this->disponible($dos, self::ENTREGA, 50, true));
        $this->disponible($dos, '2026-11-13', 50, true); // otra fecha: sí
        $this->disponible($this->platillo($comedor, 'cena'), self::ENTREGA, 50, true); // otro tiempo de comida: sí
        $this->disponible($this->platillo(), self::ENTREGA, 50, true); // otro comedor: sí
        $this->assertSame(4, DisponibilidadDia::where('es_platillo_del_dia', true)->count());
    }

    public function test_regla_no_entregar_deja_sin_platillo_a_quien_no_eligio(): void
    {
        $this->ajuste('regla_sin_eleccion', 'no_entregar');
        $empleado = $this->empleadoDe($this->empresaConContrato());
        $menu = $this->platillo();
        $this->disponible($menu, self::ENTREGA, 50, true);
        $this->historial($empleado, $menu);

        $corte = $this->corte();

        $this->assertSame(0, EleccionEmpleado::whereDate('fecha', self::ENTREGA)->count());
        $this->assertSame(0, $corte->empleados_por_defecto);
        $this->assertSame('correcto', $corte->estado);
    }

    public function test_las_porciones_por_defecto_respetan_el_maximo(): void
    {
        $this->ajuste('regla_sin_eleccion', 'ultimo_platillo');
        $menu = $this->platillo();
        $disp = $this->disponible($menu, self::ENTREGA, 2);
        foreach (range(1, 3) as $i) {
            $this->historial($this->empleadoDe($this->empresaConContrato()), $menu);
        }

        $corte = $this->corte();

        $this->assertSame(2, $corte->empleados_por_defecto);
        $this->assertSame(2, $disp->fresh()->porciones_tomadas);
        $this->assertSame('con_advertencias', $corte->estado);
    }

    // ------------------------------------------------------------------ rutas por capacidad

    public function test_las_rutas_respetan_la_capacidad_de_entregas_del_auto(): void
    {
        $this->ajuste('capacidad_auto_entregas', '2');
        $menu = $this->platillo();
        $this->disponible($menu, self::ENTREGA);
        foreach (range(1, 3) as $i) {
            $this->eligeManual($this->empleadoDe($this->empresaConContrato('Zapopan')), $menu);
        }
        $this->repartidorEn('Zapopan');
        $this->repartidorEn('Zapopan');

        $corte = $this->corte();

        $this->assertSame(2, $corte->rutas_creadas);
        $this->assertSame([2, 1], Ruta::orderBy('id')->pluck('entregas_asignadas')->map(fn ($n) => (int) $n)->all());
        foreach (Ruta::all() as $ruta) {
            $this->assertLessThanOrEqual($ruta->capacidad_entregas, $ruta->entregas_asignadas);
            $this->assertSame($ruta->entregas_asignadas, Parada::where('ruta_id', $ruta->id)->count());
        }
        $this->assertSame(0, EntregaEmpresa::whereNull('ruta_id')->count());
        $this->assertSame('correcto', $corte->estado);
    }

    public function test_las_rutas_respetan_la_capacidad_de_platillos_del_auto(): void
    {
        $this->ajuste('capacidad_auto_platillos', '5');
        $menu = $this->platillo();
        $this->disponible($menu, self::ENTREGA);
        $grande = $this->empresaConContrato('Zapopan');
        $chica = $this->empresaConContrato('Zapopan');
        foreach (range(1, 4) as $i) {
            $this->eligeManual($this->empleadoDe($grande), $menu);
        }
        foreach (range(1, 3) as $i) {
            $this->eligeManual($this->empleadoDe($chica), $menu);
        }
        $this->repartidorEn('Zapopan');
        $this->repartidorEn('Zapopan');

        $this->corte();

        $this->assertSame([4, 3], Ruta::orderBy('id')->pluck('platillos_asignados')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(2, Ruta::count());
    }

    public function test_agrupa_por_zona_y_deja_sin_ruta_lo_que_no_cabe_con_una_incidencia(): void
    {
        $menu = $this->platillo();
        $this->disponible($menu, self::ENTREGA);
        $zapopan = $this->empresaConContrato('Zapopan');
        $tlaquepaque = $this->empresaConContrato('Tlaquepaque');
        $this->eligeManual($this->empleadoDe($zapopan), $menu);
        $this->eligeManual($this->empleadoDe($tlaquepaque), $menu);
        $repartidorZapopan = $this->repartidorEn('Zapopan');

        $corte = $this->corte();

        $this->assertSame(1, $corte->rutas_creadas);
        $this->assertSame($repartidorZapopan->id, Ruta::sole()->repartidor_id);
        $this->assertSame('Zapopan', Ruta::sole()->zona);
        $this->assertNull(EntregaEmpresa::where('empresa_id', $tlaquepaque->id)->sole()->ruta_id);

        $incidencia = Incidencia::sole();
        $this->assertSame(['falta_repartidores', 'inmediata', 'abierta'], [$incidencia->tipo, $incidencia->prioridad, $incidencia->estado]);
        $this->assertSame('con_advertencias', $corte->estado);
        $this->assertStringContainsString('sin ruta', $corte->mensaje);
    }

    public function test_un_repartidor_no_hace_dos_rutas_el_mismo_dia_ni_si_esta_inactivo(): void
    {
        $this->ajuste('capacidad_auto_entregas', '1');
        $menu = $this->platillo();
        $this->disponible($menu, self::ENTREGA);
        foreach (range(1, 2) as $i) {
            $this->eligeManual($this->empleadoDe($this->empresaConContrato('Zapopan')), $menu);
        }
        $this->repartidorEn('Zapopan');
        $this->repartidorEn('Zapopan')->desactivar();

        $this->corte();

        $this->assertSame(1, Ruta::count());
        $this->assertSame(1, Incidencia::count());
    }

    public function test_el_orden_de_las_paradas_sigue_la_ventana_de_entrega(): void
    {
        $menu = $this->platillo();
        $this->disponible($menu, self::ENTREGA);
        $tarde = $this->empresaConContrato('Zapopan', ['ventana_entrega_inicio' => '13:30', 'ventana_entrega_fin' => '13:50', 'hora_entrega' => '13:40']);
        $temprano = $this->empresaConContrato('Zapopan', ['ventana_entrega_inicio' => '12:00', 'ventana_entrega_fin' => '12:20', 'hora_entrega' => '12:10']);
        $this->eligeManual($this->empleadoDe($tarde), $menu);
        $this->eligeManual($this->empleadoDe($temprano), $menu);
        $this->repartidorEn('Zapopan');

        $this->corte();

        $ruta = Ruta::sole();
        $this->assertSame(['12:00:00', '13:50:00'], [$ruta->ventana_inicio, $ruta->ventana_fin]);
        $paradas = Parada::where('ruta_id', $ruta->id)->orderBy('orden')->get();
        $this->assertSame([$temprano->nombre, $tarde->nombre], $paradas->pluck('nombre_destinatario')->all());
        $this->assertSame('Av. Industria 1450', $paradas[0]->direccion);
    }

    // ------------------------------------------------------------------ idempotencia, momento y fallos

    public function test_ejecutarlo_dos_veces_no_cambia_nada(): void
    {
        $this->ajuste('regla_sin_eleccion', 'ultimo_platillo');
        $this->escenarioBase();
        $this->repartidorEn('Zapopan');
        $sinElegir = $this->empleadoDe($this->empresaConContrato('Zapopan'));
        $this->historial($sinElegir, Menu::first());
        $primero = $this->corte();

        $antes = $this->fotoDelEstado();
        $segundo = $this->corte();
        $tercero = $this->corte($this->usuario());

        $this->assertSame($antes, $this->fotoDelEstado(), 'ni elecciones, ni órdenes, ni entregas, ni rutas, ni paradas, ni porciones cambian');
        $this->assertFalse($primero->sin_cambios);
        $this->assertTrue($segundo->sin_cambios);
        $this->assertTrue($tercero->sin_cambios);
        $this->assertSame('correcto', $segundo->estado);
        $this->assertSame(0, $segundo->elecciones_cerradas + $segundo->ordenes_generadas + $segundo->entregas_consolidadas + $segundo->rutas_creadas);
        $this->assertSame(1, Corte::where('sin_cambios', false)->count());
        $this->assertSame(3, Corte::count(), 'las repeticiones quedan en el historial como «sin cambios»');
    }

    private function fotoDelEstado(): array
    {
        return [
            DB::table('elecciones_empleado')->orderBy('id')->get()->map(fn ($f) => (array) $f)->all(),
            DB::table('ordenes_produccion')->orderBy('id')->get()->map(fn ($f) => (array) $f)->all(),
            DB::table('entregas_empresa')->orderBy('id')->get()->map(fn ($f) => (array) $f)->all(),
            DB::table('rutas')->orderBy('id')->get()->map(fn ($f) => (array) $f)->all(),
            DB::table('paradas')->orderBy('id')->get(['id', 'ruta_id', 'orden', 'entrega_empresa_id'])->map(fn ($f) => (array) $f)->all(),
            DB::table('disponibilidad_dia')->orderBy('id')->pluck('porciones_tomadas', 'id')->all(),
            DB::table('incidencias')->count(),
        ];
    }

    public function test_el_corte_automatico_no_corre_antes_de_la_hora_pero_el_manual_si(): void
    {
        $menu = $this->platillo();
        $this->disponible($menu, self::ENTREGA);
        $this->eligeManual($this->empleadoDe($this->empresaConContrato()), $menu);

        $this->ahoraEs('2026-11-11 17:59:59');
        try {
            $this->corte();
            $this->fail('El corte automático no debe correr antes de las 18:00.');
        } catch (ReglaDeNegocioException $e) {
            $this->assertSame('corte_prematuro', $e->codigo);
        }
        $this->assertSame(0, Corte::count());

        $manual = $this->corte($this->usuario());
        $this->assertFalse($manual->sin_cambios);
        $this->assertNotNull($manual->ejecutado_por);
        $this->assertSame(1, $manual->elecciones_cerradas);

        $this->ahoraEs('2026-11-11 18:00:00');
        $this->assertTrue($this->corte()->sin_cambios, 'a las 18:00 el automático ya corre, y como el manual ya cerró, no cambia nada');
    }

    public function test_no_hay_corte_para_el_domingo_ni_para_una_fecha_pasada(): void
    {
        foreach ([['2026-11-15', 'dia_sin_servicio'], ['2026-11-11', 'fecha_pasada'], ['2026-11-10', 'fecha_pasada']] as [$fecha, $codigo]) {
            try {
                app(EjecutorDelCorte::class)->ejecutar($fecha, $this->usuario());
                $this->fail("Debía fallar con {$codigo}.");
            } catch (ReglaDeNegocioException $e) {
                $this->assertSame($codigo, $e->codigo);
            }
        }
        $this->assertSame(0, Corte::count());
    }

    public function test_si_algo_falla_no_se_guarda_nada_y_se_puede_reintentar(): void
    {
        [, , $menuNorte] = $this->escenarioBase();
        $this->repartidorEn('Zapopan');

        OrdenProduccion::creating(fn () => throw new \RuntimeException('Falla simulada'));
        $fallido = $this->corte();
        OrdenProduccion::flushEventListeners();

        $this->assertSame('fallido', $fallido->estado);
        $this->assertStringContainsString('Falla simulada', $fallido->mensaje);
        $this->assertSame(0, EleccionEmpleado::whereNotNull('cerrada_en')->count(), 'las elecciones siguen abiertas');
        $this->assertSame(0, OrdenProduccion::count() + EntregaEmpresa::count() + Ruta::count());
        $this->assertSame(0, Corte::where('estado', '<>', 'fallido')->count());

        // Se puede volver a ejecutar y esta vez sí cierra el día.
        $this->eligeManual($this->empleadoDe(Empresa::first()), $menuNorte);
        $bien = $this->corte();
        $this->assertContains($bien->estado, ['correcto', 'con_advertencias']);
        $this->assertFalse($bien->sin_cambios);
        $this->assertSame(6, $bien->elecciones_cerradas);
    }

    public function test_la_base_impide_dos_cierres_reales_del_mismo_dia(): void
    {
        $this->escenarioBase();
        $this->corte();

        $this->rechaza('cortes_un_cierre_por_dia', fn () => Corte::create(['fecha_entrega' => self::ENTREGA, 'estado' => 'correcto', 'sin_cambios' => false]));
    }

    public function test_el_corte_registra_quien_lo_lanzo(): void
    {
        $this->escenarioBase();
        $usuario = $this->usuario();

        $this->assertSame($usuario, $this->corte($usuario)->ejecutado_por);
    }
}
