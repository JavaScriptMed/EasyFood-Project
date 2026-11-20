<?php

namespace Tests\Feature;

use App\Models\ContratoEmpresa;
use App\Models\EleccionEmpleado;
use App\Models\Pedido;
use App\Models\User;
use Database\Seeders\DatosDeEjemploSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** Los datos de ejemplo sirven para probar la API a mano (colección de Bruno): deben cargarse completos y ser coherentes. */
class DatosDeEjemploTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRASENA = 'ContrasenaDeEjemplo1';

    private function cargar(): void
    {
        config(['easylunch.contrasena_de_ejemplo' => self::CONTRASENA]);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-11 10:00', 'America/Mexico_City'));
        $this->seed(DatosDeEjemploSeeder::class);
    }

    private function entrar(string $correo): string
    {
        return $this->postJson('/api/auth/login', ['email' => $correo, 'password' => self::CONTRASENA])->assertOk()->json('token');
    }

    public function test_carga_las_cuentas_de_cada_rol_y_todas_entran_con_la_misma_contrasena(): void
    {
        $this->cargar();

        foreach (['admin@easylunch.test', 'soporte@easylunch.test', 'comedor@easylunch.test', 'comedor.sur@easylunch.test', 'rrhh@nexo.test', 'rrhh@pacifico.test',
            'ana@nexo.test', 'cliente@easylunch.test', 'cliente2@easylunch.test', 'repartidor@easylunch.test'] as $correo) {
            $this->assertNotEmpty($this->entrar($correo), $correo);
        }
    }

    public function test_deja_datos_en_todas_las_areas(): void
    {
        $this->cargar();

        $this->assertSame([2, 3, 2, 6, 2, 2], [DB::table('providers')->count(), DB::table('empresas')->count(), DB::table('repartidores')->count(),
            DB::table('empleados')->count(), DB::table('users')->where('role_id', 1)->count(), DB::table('contratos_comedor')->count()]);
        $this->assertGreaterThan(5, DB::table('menus')->where('estado_revision', 'publicado')->count());
        foreach (['en_revision', 'borrador', 'rechazado'] as $estado) {
            $this->assertSame(1, DB::table('menus')->where('estado_revision', $estado)->count(), $estado);
        }
        $this->assertSame(3, DB::table('paquetes')->count());
        $this->assertGreaterThan(60, DB::table('elecciones_empleado')->count(), 'historial del mes pasado');
        $this->assertGreaterThan(0, DB::table('entregas_empresa')->where('estado', 'entregada')->count());
        $this->assertGreaterThan(2, DB::table('incidencias')->count(), 'las 2 de soporte y las quejas del dashboard');
        $this->assertSame(1, DB::table('tickets')->count());
        $this->assertSame(3, DB::table('zonas')->count());
        $this->assertGreaterThan(0, DB::table('disponibilidad_dia')->where('es_platillo_del_dia', true)->count());
    }

    public function test_hay_elecciones_para_la_proxima_entrega_y_un_pedido_pagado_y_otro_por_pagar(): void
    {
        $this->cargar();

        $this->assertSame(3, EleccionEmpleado::whereDate('fecha', '2026-11-12')->where('origen', 'manual')->count());
        $this->assertSame(1, Pedido::where('estado', 'pendiente_pago')->whereNotNull('vence_pago_en')->count(), 'uno por pagar, con su plazo');
        $this->assertGreaterThanOrEqual(1, Pedido::where('estado', 'recibido')->count(), 'y pagados');
        $this->assertGreaterThanOrEqual(1, Pedido::where('estado', 'completado')->count(), 'y entregados, de las últimas semanas');
    }

    public function test_las_direcciones_de_ejemplo_calculan_su_zona(): void
    {
        $this->cargar();

        $zonas = DB::table('addresses')->orderBy('id')->pluck('zona', 'label')->all();
        $this->assertSame(['Centro', null, 'Zapopan'], array_values($zonas));
        $this->assertSame(1, DB::table('addresses')->where('sin_cobertura', true)->count());
    }

    public function test_hay_contratos_por_vencer_y_avisos_posibles(): void
    {
        $this->cargar();

        $porVencer = ContratoEmpresa::query()->whereDate('vigencia_fin', '<=', '2026-11-30')->count();
        $this->assertSame(1, $porVencer, 'Logística Pacífico vence pronto');
        $this->assertSame(1, DB::table('contratos_comedor')->whereDate('vigencia_fin', '<=', '2026-12-05')->count());
    }

    public function test_con_los_datos_de_ejemplo_se_puede_facturar_liquidar_y_ejecutar_el_corte(): void
    {
        $this->cargar();
        $admin = $this->entrar('admin@easylunch.test');
        $con = fn () => $this->withToken($admin);

        $facturas = $con()->postJson('/api/v1/admin/facturas/generar', ['mes' => '2026-10'])->assertOk()->json();
        $this->assertCount(2, $facturas);
        $this->assertGreaterThan(0, $facturas[0]['platillos']);
        $this->assertSame(2, count($con()->postJson('/api/v1/admin/liquidaciones/generar', ['mes' => '2026-10'])->assertOk()->json()));

        $corte = $con()->postJson('/api/v1/admin/cortes/ejecutar')->assertOk()->json();
        $this->assertSame('2026-11-12', $corte['fecha_entrega']);
        $this->assertGreaterThan(0, $corte['elecciones_cerradas']);
        $this->assertGreaterThan(0, count($corte['empleados_por_defecto']), 'los que no eligieron reciben la regla sin elección');
        $this->assertSame($corte['corte']['empleados_por_defecto'], count($corte['empleados_por_defecto']));
        $this->assertGreaterThan(0, $corte['entregas_consolidadas']);
    }

    public function test_cada_platillo_tiene_su_categoria_del_catalogo_y_los_catalogos_traen_sus_items(): void
    {
        $this->cargar();

        $this->assertSame(0, DB::table('menus')->whereNull('category_id')->count(), 'todos los platillos tienen categoría');
        $this->assertGreaterThanOrEqual(3, DB::table('menus')->distinct()->count('category_id'), 'hay variedad de categorías');
        foreach (['TIEMPOS_COMIDA' => 3, 'ALERGENOS' => 5, 'DIETAS' => 4] as $codigo => $items) {
            $categoria = DB::table('catalog_categories')->where('code', $codigo)->first();
            $this->assertNotNull($categoria, $codigo);
            $this->assertSame($items, DB::table('catalog_items')->where('category_id', $categoria->id)->count(), $codigo);
        }
    }

    public function test_los_pedidos_de_ejemplo_llevan_2_y_3_comidas_por_dia(): void
    {
        $this->cargar();

        $cecilia = Pedido::where('cliente_id', User::where('email', 'cliente@easylunch.test')->value('id'))->orderBy('id')->first();
        $daniel = Pedido::where('cliente_id', User::where('email', 'cliente2@easylunch.test')->value('id'))->orderBy('id')->first();
        $this->assertSame([2, false, 3, true], [$cecilia->comidas_por_dia, (bool) $cecilia->con_extra, $daniel->comidas_por_dia, (bool) $daniel->con_extra], 'Cecilia 2 comidas; Daniel 3 y un extra');
        foreach (Pedido::with('dias.platillos')->get() as $pedido) {
            foreach ($pedido->dias as $dia) {
                // Cada día de comida lleva sus comidas (los pedidos nuevos traen todos sus días en la entrega del sábado).
                foreach ($dia->platillos->groupBy(fn ($c) => $c->fecha->toDateString()) as $fecha => $comidas) {
                    $this->assertCount($pedido->comidas_por_dia + (int) $pedido->con_extra, $comidas, "{$pedido->folio} · {$fecha}");
                }
            }
        }
    }

    public function test_los_datos_de_ejemplo_llenan_los_10_indicadores_del_dashboard(): void
    {
        $this->cargar();
        $admin = $this->entrar('admin@easylunch.test');

        $i = $this->withToken($admin)->getJson('/api/v1/admin/dashboard')->assertOk()->json('indicadores');

        $this->assertGreaterThan(0, $i['platillos_entregados']['semana'] + array_sum(array_column($i['platillos_entregados']['serie'], 'platillos')), '1 platillos entregados');
        $this->assertGreaterThan(0, $i['calificacion_entrega']['calificaciones'] + array_sum(array_column($i['calificacion_entrega']['serie'], 'calificaciones')), '2 calificación de la entrega');
        $this->assertGreaterThan(0, array_sum(array_column($i['calificacion_menu']['serie'], 'calificaciones')), '3 calificación del menú');
        $this->assertSame('Dos días', $i['paquete_popular']['paquete']['nombre'], '4 paquete más popular');
        $this->assertGreaterThan(0, $i['ahorro_estimado']['por_empleado_promedio'], '5 ahorro por empleado');
        $this->assertGreaterThan(0, array_sum(array_column($i['incidencias']['serie'], 'total')), '6 quejas');
        $this->assertSame('Grupo Alameda', $i['contratos_nuevos']['contratos'][0]['empresa'], '7 contratos nuevos');
        $this->assertCount(6, $i['balance']['serie'], '8 balance');
        $this->assertTrue($i['costo_maquila']['en_limite'], '9 maquila dentro de $49');
        $this->assertSame([1, 1], [$i['facturacion_b2b']['pagadas'], $i['facturacion_b2b']['pendientes']], '10 semáforo: una verde y una roja');
    }

    public function test_cargarlo_dos_veces_no_duplica_nada(): void
    {
        $this->cargar();
        $usuarios = DB::table('users')->count();

        $this->seed(DatosDeEjemploSeeder::class);

        $this->assertSame($usuarios, DB::table('users')->count());
    }

    public function test_exige_la_contrasena_y_se_niega_en_produccion(): void
    {
        config(['easylunch.contrasena_de_ejemplo' => null]);
        try {
            $this->correrSeeder();
            $this->fail('Debía pedir SEED_PASSWORD.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('SEED_PASSWORD', $e->getMessage());
        }

        config(['easylunch.contrasena_de_ejemplo' => self::CONTRASENA]);
        $this->app['env'] = 'production';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('producción');
        $this->correrSeeder();
    }

    /** Directo, sin el comando db:seed (que en producción pregunta antes por su cuenta). */
    private function correrSeeder(): void
    {
        (new DatosDeEjemploSeeder)->setContainer($this->app)->__invoke();
    }
}
