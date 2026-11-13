<?php

namespace Tests\Feature\Negocio;

use App\Models\Address;
use App\Models\ContratoComedor;
use App\Models\EleccionEmpleado;
use App\Models\Menu;
use App\Models\Paquete;
use App\Models\Provider;
use App\Services\ContratacionDePaquetes;
use App\Services\EjecutorDelCorte;
use App\Services\EleccionDePlatillos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Regla «platillo_del_dia» con varios comedores: se asigna al comedor que vaya más atrás en su compra mínima del mes
 * (avance = platillos del mes / mínimo); empate: el de menos platillos; si no tiene porciones libres, pasa al siguiente.
 * «Hoy» es el miércoles 11 de nov de 2026 a las 18:30 y se corta el jueves 12.
 */
class PlatilloDelDiaPorRezagoTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private const ENTREGA = '2026-11-12';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ajuste('regla_sin_eleccion', 'platillo_del_dia');
        $this->ahoraEs('2026-11-11 18:30');
    }

    /** @return array{0: Provider, 1: Menu} un comedor con su platillo del día para el jueves 12 */
    private function comedorConPlatilloDelDia(int $minimo, int $porciones = 50, string $tiempo = 'comida'): array
    {
        $comedor = $this->comedorConContrato($minimo);
        $menu = $this->platillo($comedor, $tiempo);
        $this->disponible($menu, self::ENTREGA, $porciones, true);

        return [$comedor, $menu];
    }

    /** Platillos que el comedor ya tiene programados en un día anterior del mes (orden de producción de un corte pasado). */
    private function yaProgramados(Provider $comedor, int $porciones, string $fecha = '2026-11-05'): void
    {
        $menu = $this->platillo($comedor);
        DB::table('ordenes_produccion')->insert([
            'comedor_id' => $comedor->id, 'menu_id' => $menu->id, 'fecha' => $fecha, 'porciones_totales' => $porciones,
            'porciones_empresas' => $porciones, 'porciones_individuales' => 0, 'hora_recoleccion' => '11:00',
        ]);
    }

    /** Empleados que no eligieron y a quienes se les aplicará la regla (tienen historial en la categoría «comida»). */
    private function empleadosSinElegir(int $cantidad): array
    {
        $empresa = $this->empresaConContrato();
        $viejo = $this->platillo();
        $empleados = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $empleado = $this->empleadoDe($empresa);
            DB::table('elecciones_empleado')->insert([
                'empleado_id' => $empleado->id, 'fecha' => '2026-11-10', 'tiempo_comida' => 'comida', 'menu_id' => $viejo->id,
                'precio_congelado' => 130, 'porcentaje_subsidio_congelado' => 60, 'origen' => 'manual', 'cerrada_en' => '2026-11-09 18:00:00',
            ]);
            $empleados[] = $empleado;
        }

        return $empleados;
    }

    private function asignadosPorComedor(): array
    {
        return EleccionEmpleado::query()->whereDate('fecha', self::ENTREGA)->where('origen', 'por_defecto')
            ->join('menus', 'menus.id', '=', 'elecciones_empleado.menu_id')
            ->groupBy('menus.provider_id')->selectRaw('menus.provider_id, count(*) as total')->pluck('total', 'provider_id')
            ->map(fn ($n) => (int) $n)->all();
    }

    private function corte(): void
    {
        app(EjecutorDelCorte::class)->ejecutar(self::ENTREGA);
    }

    public function test_va_al_comedor_con_menor_avance_en_su_minimo(): void
    {
        [$adelantado] = $this->comedorConPlatilloDelDia(100);
        [$atrasado] = $this->comedorConPlatilloDelDia(100);
        [$medio] = $this->comedorConPlatilloDelDia(100);
        $this->yaProgramados($adelantado, 80);
        $this->yaProgramados($medio, 40);
        $this->yaProgramados($atrasado, 10);
        $this->empleadosSinElegir(3);

        $this->corte();

        $this->assertSame([$atrasado->id => 3], $this->asignadosPorComedor());
    }

    public function test_el_avance_es_relativo_al_minimo_de_cada_comedor_no_a_los_platillos_absolutos(): void
    {
        [$grande] = $this->comedorConPlatilloDelDia(400); // 100 de 400 = 25 %
        [$chico] = $this->comedorConPlatilloDelDia(100);   // 50 de 100 = 50 %
        $this->yaProgramados($grande, 100);
        $this->yaProgramados($chico, 50);
        $this->empleadosSinElegir(1);

        $this->corte();

        $this->assertSame([$grande->id => 1], $this->asignadosPorComedor(), 'el grande tiene más platillos pero va más atrás en proporción');
    }

    public function test_si_empatan_en_avance_gana_el_de_menos_platillos_asignados(): void
    {
        [$grande] = $this->comedorConPlatilloDelDia(200); // 40 de 200 = 20 %
        [$chico] = $this->comedorConPlatilloDelDia(100);   // 20 de 100 = 20 %
        $this->yaProgramados($grande, 40);
        $this->yaProgramados($chico, 20);
        $this->empleadosSinElegir(1);

        $this->corte();

        $this->assertSame([$chico->id => 1], $this->asignadosPorComedor());
    }

    public function test_si_empatan_en_todo_gana_el_comedor_de_menor_id(): void
    {
        [$primero] = $this->comedorConPlatilloDelDia(100);
        $this->comedorConPlatilloDelDia(100);
        $this->empleadosSinElegir(1);

        $this->corte();

        $this->assertSame([$primero->id => 1], $this->asignadosPorComedor());
    }

    public function test_si_el_elegido_se_queda_sin_porciones_pasa_al_siguiente_mas_atras(): void
    {
        [$ultimoLugar] = $this->comedorConPlatilloDelDia(100, 2); // más atrás pero solo 2 porciones
        [$segundo] = $this->comedorConPlatilloDelDia(100, 50);
        [$adelantado] = $this->comedorConPlatilloDelDia(100, 50);
        $this->yaProgramados($segundo, 30);
        $this->yaProgramados($adelantado, 70);
        $this->empleadosSinElegir(5);

        $this->corte();

        $this->assertSame([$ultimoLugar->id => 2, $segundo->id => 3], $this->asignadosPorComedor());
    }

    public function test_cuentan_las_porciones_ya_elegidas_para_ese_dia_y_los_pedidos_individuales_pagados(): void
    {
        [$a] = $this->comedorConPlatilloDelDia(100);
        [$b] = $this->comedorConPlatilloDelDia(100);
        // A ya tiene 5 elecciones manuales del jueves; B tiene 2 pedidos individuales pagados: B va más atrás.
        $empresa = $this->empresaConContrato();
        $menuA = Menu::where('provider_id', $a->id)->first();
        $this->ahoraEs('2026-11-11 10:00');
        foreach (range(1, 5) as $i) {
            app(EleccionDePlatillos::class)->elegir($this->empleadoDe($empresa), self::ENTREGA, 'comida', $menuA);
        }
        $this->zonaDePruebas();
        $cliente = \App\Models\User::findOrFail($this->usuario());
        $direccion = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. 1', 'latitude' => 20.67, 'longitude' => -103.35]);
        $paquete = Paquete::create(['nombre' => 'Un día', 'dias' => 1]);
        $servicio = app(ContratacionDePaquetes::class);
        foreach (range(1, 2) as $i) {
            $servicio->pagar($servicio->pedir($cliente, $paquete, $direccion, [Menu::where('provider_id', $b->id)->first()]), 'tarjeta', '4821');
        }
        $this->ahoraEs('2026-11-11 18:30');
        $this->empleadosSinElegir(1);

        $this->corte();

        $this->assertSame([$b->id => 1], $this->asignadosPorComedor());
    }

    public function test_solo_cuentan_los_platillos_del_mes_en_curso(): void
    {
        [$conMesPasado] = $this->comedorConPlatilloDelDia(100);
        [$conMesActual] = $this->comedorConPlatilloDelDia(100);
        $this->yaProgramados($conMesPasado, 500, '2026-10-20'); // octubre no cuenta
        $this->yaProgramados($conMesActual, 20, '2026-11-03');
        $this->empleadosSinElegir(1);

        $this->corte();

        $this->assertSame([$conMesPasado->id => 1], $this->asignadosPorComedor());
    }

    public function test_un_comedor_sin_contrato_vigente_no_participa(): void
    {
        $sinContrato = $this->comedorNuevo();
        $menu = $this->platillo($sinContrato);
        $this->disponible($menu, self::ENTREGA, 50, true);
        [$conContrato] = $this->comedorConPlatilloDelDia(100);
        $this->yaProgramados($conContrato, 90);
        $this->empleadosSinElegir(1);

        $this->corte();

        $this->assertSame([$conContrato->id => 1], $this->asignadosPorComedor());
    }

    public function test_cada_tiempo_de_comida_tiene_su_propio_orden(): void
    {
        $uno = $this->comedorConContrato(100);
        $dos = $this->comedorConContrato(100);
        foreach ([$uno, $dos] as $comedor) {
            $this->disponible($this->platillo($comedor, 'comida'), self::ENTREGA, 50, true);
            $this->disponible($this->platillo($comedor, 'cena'), self::ENTREGA, 50, true);
        }
        $this->yaProgramados($uno, 60); // el comedor «dos» va más atrás en ambos tiempos
        $empresa = $this->empresaConContrato();
        $empleado = $this->empleadoDe($empresa);
        foreach (['comida', 'cena'] as $tiempo) {
            DB::table('elecciones_empleado')->insert([
                'empleado_id' => $empleado->id, 'fecha' => '2026-11-10', 'tiempo_comida' => $tiempo, 'menu_id' => $this->platillo(null, $tiempo)->id,
                'precio_congelado' => 130, 'porcentaje_subsidio_congelado' => 60, 'origen' => 'manual', 'cerrada_en' => '2026-11-09 18:00:00',
            ]);
        }

        $this->corte();

        $this->assertSame([$dos->id => 2], $this->asignadosPorComedor());
    }

    public function test_con_un_solo_comedor_marcado_funciona_como_antes(): void
    {
        [$unico] = $this->comedorConPlatilloDelDia(100);
        $this->empleadosSinElegir(2);

        $this->corte();

        $this->assertSame([$unico->id => 2], $this->asignadosPorComedor());
    }

    public function test_sin_porciones_en_ninguno_queda_sin_entrega_con_advertencia(): void
    {
        $this->comedorConPlatilloDelDia(100, 0);
        $this->comedorConPlatilloDelDia(100, 0);
        $this->empleadosSinElegir(1);

        $corte = app(EjecutorDelCorte::class)->ejecutar(self::ENTREGA);

        $this->assertSame([], $this->asignadosPorComedor());
        $this->assertSame('con_advertencias', $corte->estado);
        $this->assertNotNull(ContratoComedor::first());
    }
}
