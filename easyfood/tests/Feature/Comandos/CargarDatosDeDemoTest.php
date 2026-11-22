<?php

namespace Tests\Feature\Comandos;

use App\Models\Corte;
use App\Models\DisponibilidadDia;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\EntregaEmpresa;
use App\Models\Incidencia;
use App\Models\LiquidacionComedor;
use App\Models\Menu;
use App\Models\Parada;
use App\Models\Pedido;
use App\Models\Provider;
use App\Models\Ruta;
use App\Models\User;
use Database\Seeders\DatosDeDemoParaProduccion;
use Database\Seeders\DatosDeEjemploSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\TestCase;

/**
 * `easylunch:cargar-demo`: datos de demo que sí corren en producción, con --confirmo. «Hoy» es el sábado 21 de nov de 2026 por la
 * noche; la ventana por defecto es del 24 de nov al 6 de dic.
 */
class CargarDatosDeDemoTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-21 21:00');
        $this->usuarioDeRol('admin', ['email' => 'admin@real.mx']);
    }

    private function cargar(array $extra = [])
    {
        return $this->artisan('easylunch:cargar-demo', ['--confirmo' => true, '--clave' => 'Demo1234'] + $extra);
    }

    private function enProduccion(): void
    {
        $this->app['env'] = 'production';
    }

    /** @return array<string, int> */
    private function fotoDeLasTablas(): array
    {
        $foto = [];
        foreach (['users', 'providers', 'empresas', 'empleados', 'menus', 'disponibilidad_dia', 'elecciones_empleado', 'entregas_empresa', 'ordenes_produccion', 'rutas', 'paradas', 'pedidos', 'pedido_dias', 'pedido_dia_platillos', 'incidencias', 'liquidaciones_comedor', 'cortes', 'zonas', 'paquetes', 'repartidores', 'addresses', 'calificaciones_entrega', 'calificaciones_platillo'] as $tabla) {
            $foto[$tabla] = DB::table($tabla)->count();
        }

        return $foto;
    }

    private function dias(string $desde, string $hasta): array
    {
        $dias = [];
        for ($d = \Carbon\CarbonImmutable::parse($desde); $d->lte(\Carbon\CarbonImmutable::parse($hasta)); $d = $d->addDay()) {
            if (! $d->isSunday()) {
                $dias[] = $d->toDateString();
            }
        }

        return $dias;
    }

    // ------------------------------------------------------------------ confirmación y bloqueos

    public function test_sin_confirmo_no_carga_nada_y_explica_que_va_a_hacer(): void
    {
        $this->enProduccion();
        $antes = $this->fotoDeLasTablas();

        $this->artisan('easylunch:cargar-demo', ['--clave' => 'Demo1234'])->expectsOutputToContain('--confirmo')->assertExitCode(1);

        $this->assertSame($antes, $this->fotoDeLasTablas());
    }

    public function test_el_seeder_de_ejemplo_sigue_bloqueado_en_produccion_y_la_demo_no_se_carga_con_db_seed(): void
    {
        $this->enProduccion();

        $mensaje = function (callable $accion): string {
            try {
                $accion();
            } catch (\Throwable $e) {
                return $e instanceof \PHPUnit\Framework\AssertionFailedError ? 'NO FALLÓ' : $e->getMessage();
            }

            return 'NO FALLÓ';
        };

        $this->assertStringContainsString('producción', $mensaje(fn () => app()->call([new DatosDeEjemploSeeder, 'run'])), 'el seeder de ejemplo sigue negándose a correr en producción');
        $this->assertStringContainsString('easylunch:cargar-demo --confirmo', $mensaje(fn () => app()->call([new DatosDeDemoParaProduccion, 'run'])), 'la demo no se carga como seeder');
        $this->assertStringContainsString('easylunch:cargar-demo --confirmo', $mensaje(fn () => $this->artisan('db:seed', ['--class' => DatosDeDemoParaProduccion::class, '--force' => true])->run()), 'ni con db:seed --force');
        $this->assertSame(0, Provider::count());
    }

    public function test_valida_la_contrasena_las_fechas_y_que_haya_un_administrador(): void
    {
        $antes = $this->fotoDeLasTablas();

        $this->artisan('easylunch:cargar-demo', ['--confirmo' => true, '--clave' => 'corta'])->assertExitCode(1);
        $this->artisan('easylunch:cargar-demo', ['--confirmo' => true, '--clave' => 'Demo1234', '--desde' => '2026-12-06', '--hasta' => '2026-11-24'])->assertExitCode(1);
        $this->artisan('easylunch:cargar-demo', ['--confirmo' => true, '--clave' => 'Demo1234', '--desde' => '24/11'])->assertExitCode(1);
        $this->artisan('easylunch:cargar-demo', ['--confirmo' => true, '--clave' => 'Demo1234', '--desde' => '2026-01-01', '--hasta' => '2026-12-31'])->assertExitCode(1);
        $this->assertSame($antes, $this->fotoDeLasTablas(), 'ningún intento rechazado dejó nada a medias');

        DB::table('users')->where('email', 'admin@real.mx')->update(['role_id' => 1]); // ya no hay administrador
        $this->cargar()->expectsOutputToContain('easylunch:crear-admin')->assertExitCode(1);
    }

    // ------------------------------------------------------------------ la carga

    public function test_en_produccion_carga_3_comedores_3_empresas_platillos_porciones_historial_y_rutas_activas(): void
    {
        $this->enProduccion();

        $this->cargar()->expectsOutputToContain('Datos de demo cargados')->assertExitCode(0);

        $demo = fn ($q) => $q->where('email', 'like', '%demo.easylunch.test');
        $this->assertSame(3, Provider::whereHas('user', $demo)->count(), 'exactamente 3 comedores');
        $this->assertSame(3, Empresa::whereHas('usuario', $demo)->count(), 'exactamente 3 empresas');
        $this->assertSame(15, Empleado::whereHas('usuario', $demo)->count(), '5 empleados por empresa');
        $this->assertSame(['Centro', 'Tlaquepaque', 'Zapopan'], Empresa::orderBy('zona')->pluck('zona')->all());

        $publicados = Menu::where('estado_revision', 'publicado')->whereIn('provider_id', Provider::pluck('id'))->pluck('id');
        $this->assertSame(15, $publicados->count());
        $ventana = $this->dias('2026-11-24', '2026-12-06'); // el domingo 29 no hay servicio
        $this->assertSame(11, count($ventana));
        foreach ($ventana as $dia) {
            $this->assertSame(15, DisponibilidadDia::whereIn('menu_id', $publicados)->whereDate('fecha', $dia)->count(), "porciones de todos los platillos el {$dia}");
            $rutas = Ruta::whereDate('fecha', $dia)->get();
            $this->assertCount(3, $rutas, "una ruta por zona el {$dia}");
            $this->assertSame(['aprobada'], $rutas->pluck('estado')->unique()->all());
            $this->assertSame(15, DB::table('elecciones_empleado')->whereDate('fecha', $dia)->whereNull('cerrada_en')->count(), "los empleados ya eligieron para el {$dia}");
            $this->assertSame(3, EntregaEmpresa::whereDate('fecha', $dia)->where('estado', 'programada')->count());
        }
        $this->assertGreaterThan(0, DisponibilidadDia::whereIn('menu_id', $publicados)->whereDate('fecha', '2026-12-19')->count(), 'las porciones llegan para los pedidos que se coman después de la ventana');
        $this->assertSame(0, Corte::whereDate('fecha_entrega', '>=', '2026-11-24')->count(), 'no se ejecuta ningún corte de la ventana: los hace el sistema a su hora');

        // Al menos dos semanas de historial.
        $hace14 = '2026-11-07';
        $entregadas = EntregaEmpresa::where('estado', 'entregada')->whereDate('fecha', '>=', $hace14)->pluck('fecha')->map(fn ($f) => $f->toDateString())->unique();
        $this->assertGreaterThanOrEqual(10, $entregadas->count(), 'entregas de al menos 10 días de servicio en las últimas dos semanas');
        $this->assertGreaterThanOrEqual(9, Pedido::whereIn('estado', ['completado', 'recibido'])->whereDate('fecha_entrega', '<', '2026-11-21')->count(), 'pedidos de los últimos sábados');
        $rutasDelHistorial = Ruta::whereDate('fecha', '<', '2026-11-21')->get();
        $this->assertGreaterThanOrEqual(10, $rutasDelHistorial->count());
        $paradasDelHistorial = Parada::whereIn('ruta_id', $rutasDelHistorial->pluck('id'))->get();
        $this->assertSame([], $paradasDelHistorial->whereNotIn('estado', ['entregado', 'no_localizado'])->pluck('id')->all(), 'todas las paradas del historial están cerradas');
        $this->assertGreaterThan(0, $paradasDelHistorial->whereNotNull('pedido_dia_id')->count(), 'hay paradas de clientes');
        $this->assertGreaterThan(0, $paradasDelHistorial->whereNotNull('entrega_empresa_id')->count(), 'y de empresas');
        $this->assertGreaterThanOrEqual(1, Incidencia::where('tipo', 'entrega_no_localizada')->where('estado', 'resuelta')->count());
        $this->assertGreaterThanOrEqual(3, Incidencia::count());
        $this->assertSame(3, LiquidacionComedor::count());
        $this->assertSame([2, 1], [LiquidacionComedor::where('estado', 'pagado')->count(), LiquidacionComedor::where('estado', 'pendiente')->count()]);
        $this->assertSame(3, Pedido::where('estado', 'recibido')->whereDate('fecha_entrega', '2026-11-28')->count(), 'pedidos pagados para el próximo sábado, listos para su corte');
    }

    public function test_se_puede_repetir_sin_duplicar_ni_borrar_y_no_toca_otras_cuentas(): void
    {
        $this->enProduccion();
        $prueba = collect(['soporte.prueba@easylunch.test', 'rrhh.prueba@easylunch.test', 'cliente.prueba@easylunch.test'])
            ->map(fn ($correo) => $this->usuarioDeRol('customer', ['email' => $correo, 'password' => 'hash-de-prueba']))->all();
        $filasDePrueba = DB::table('users')->whereIn('id', collect($prueba)->pluck('id'))->get()->map(fn ($u) => (array) $u)->all();
        $admin = (array) DB::table('users')->where('email', 'admin@real.mx')->first();
        $this->cargar()->assertExitCode(0);
        $despuesDeLaPrimera = $this->fotoDeLasTablas();

        $this->cargar()->expectsOutputToContain('ya estaban cargados')->assertExitCode(0);

        $this->assertSame($despuesDeLaPrimera, $this->fotoDeLasTablas(), 'la segunda vez no duplica nada ni borra nada');
        $this->assertSame($filasDePrueba, DB::table('users')->whereIn('id', collect($prueba)->pluck('id'))->get()->map(fn ($u) => (array) $u)->all(), 'las cuentas *.prueba@easylunch.test quedan exactamente igual');
        $this->assertSame($admin, (array) DB::table('users')->where('email', 'admin@real.mx')->first());
        $this->assertSame(27, User::where('email', 'like', '%demo.easylunch.test')->count(), 'cuentas de demo: 3 comedores, 3 RR. HH., 15 empleados, 3 repartidores y 3 clientes');
        $this->assertSame(27 + 3 + 1, User::count(), 'y no se creó ninguna otra: las 27 de demo, las 3 *.prueba y el administrador');
    }

    public function test_respeta_lo_que_ya_hay_en_el_servidor_zonas_paquetes_y_cortes_reales(): void
    {
        $this->enProduccion();
        $this->zonaDePruebasReal();
        $paquete = \App\Models\Paquete::create(['nombre' => 'Semana real', 'dias' => 6, 'descuento_porcentaje' => 20]);
        Corte::create(['fecha_entrega' => '2026-11-18', 'estado' => 'correcto', 'sin_cambios' => false]); // un corte real de un día del historial

        $this->cargar()->assertExitCode(0);

        $this->assertSame(1, DB::table('zonas')->whereRaw("lower(nombre) = 'centro'")->count(), 'reutiliza la zona que ya existe');
        $this->assertSame(1, \App\Models\Paquete::where('dias', 6)->count(), 'reutiliza el paquete de 6 días del servidor');
        $this->assertSame($paquete->id, \App\Models\Paquete::where('dias', 6)->value('id'));
        $this->assertSame(1, Corte::whereDate('fecha_entrega', '2026-11-18')->where('tipo', 'general')->count(), 'el corte real de ese día se respeta');
        $this->assertGreaterThan(0, DB::table('elecciones_empleado')->whereDate('fecha', '2026-11-18')->count(), 'el historial de ese día se cargó igual');
        $this->assertSame(1, DB::selectOne("select count(*) as n from pg_trigger where tgname = 'elecciones_empleado_tras_corte' and tgenabled = 'O'")->n, 'el candado de elecciones vuelve a quedar encendido');
    }

    public function test_si_falla_a_la_mitad_no_queda_nada_y_el_candado_vuelve_a_su_lugar(): void
    {
        $this->enProduccion();
        $antes = $this->fotoDeLasTablas();
        DB::unprepared("ALTER TABLE liquidaciones_comedor ADD CONSTRAINT falla_a_proposito CHECK (platillos_entregados < 0)");

        try {
            $this->cargar()->run();
        } catch (\Throwable) {
        }

        DB::unprepared('ALTER TABLE liquidaciones_comedor DROP CONSTRAINT falla_a_proposito');
        $this->assertSame($antes, $this->fotoDeLasTablas(), 'todo o nada');
        $this->assertSame(1, DB::selectOne("select count(*) as n from pg_trigger where tgname = 'elecciones_empleado_tras_corte' and tgenabled = 'O'")->n);
    }

    public function test_con_la_api_del_repartidor_instalada_el_historial_cumple_sus_restricciones(): void
    {
        // La migración de la rama feat/api-repartidor (rutas en_curso/completada y paradas con evidencia y motivo) todavía no está aquí:
        // se simula en esta transacción para comprobar que la demo cargada DESPUÉS de esa migración cumple sus restricciones. (Cargada
        // ANTES, esa migración fallaría al validar las paradas ya entregadas sin evidencia: por eso se carga la demo después.)
        DB::unprepared(<<<'SQL'
            ALTER TABLE rutas ADD COLUMN iniciada_en timestamptz, ADD COLUMN completada_en timestamptz;
            ALTER TABLE rutas DROP CONSTRAINT rutas_estado_valido;
            ALTER TABLE rutas ADD CONSTRAINT rutas_estado_valido CHECK (estado IN ('borrador', 'aprobada', 'en_curso', 'completada'));
            ALTER TABLE rutas ADD CONSTRAINT rutas_avance_coherente CHECK (((estado IN ('en_curso', 'completada')) = (iniciada_en IS NOT NULL)) AND ((estado = 'completada') = (completada_en IS NOT NULL)));
            ALTER TABLE paradas ADD COLUMN iniciada_en timestamptz, ADD COLUMN cerrada_en timestamptz, ADD COLUMN evidencia_foto_url varchar(255), ADD COLUMN motivo_no_entrega varchar(255);
            ALTER TABLE paradas DROP CONSTRAINT paradas_estado_valido;
            ALTER TABLE paradas ADD CONSTRAINT paradas_estado_valido CHECK (estado IN ('pendiente', 'en_camino', 'entregado', 'no_localizado'));
            ALTER TABLE paradas ADD CONSTRAINT paradas_inicio_coherente CHECK ((estado = 'pendiente') = (iniciada_en IS NULL));
            ALTER TABLE paradas ADD CONSTRAINT paradas_cierre_coherente CHECK ((estado IN ('entregado', 'no_localizado')) = (cerrada_en IS NOT NULL));
            ALTER TABLE paradas ADD CONSTRAINT paradas_entregada_con_evidencia CHECK (estado <> 'entregado' OR evidencia_foto_url IS NOT NULL);
            ALTER TABLE paradas ADD CONSTRAINT paradas_no_entregada_con_motivo CHECK (estado <> 'no_localizado' OR (motivo_no_entrega IS NOT NULL AND length(btrim(motivo_no_entrega)) > 0));
        SQL);
        $this->enProduccion();

        $this->cargar()->assertExitCode(0);

        $this->assertSame(0, Ruta::whereDate('fecha', '<', '2026-11-21')->where('estado', '<>', 'completada')->count(), 'las rutas del historial quedan completadas');
        $this->assertSame(0, Ruta::whereDate('fecha', '>=', '2026-11-24')->where('estado', '<>', 'aprobada')->count(), 'las de la ventana quedan aprobadas, sin empezar');
        $this->assertGreaterThan(0, Parada::where('estado', 'entregado')->whereNotNull('evidencia_foto_url')->count());
        $this->assertGreaterThan(0, Parada::where('estado', 'no_localizado')->whereNotNull('motivo_no_entrega')->count());
    }

    /** Una zona «Centro» real del servidor (otro polígono que el de ejemplo). */
    private function zonaDePruebasReal(): void
    {
        DB::insert("insert into zonas (nombre, area) values ('Centro', ST_Multi(ST_GeomFromText('POLYGON((-103.40 20.60, -103.30 20.60, -103.30 20.72, -103.40 20.72, -103.40 20.60))', 4326))::geography)");
    }
}
