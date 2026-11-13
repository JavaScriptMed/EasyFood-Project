<?php

namespace Tests\Feature\Negocio;

use App\Models\Address;
use App\Models\Aviso;
use App\Models\Corte;
use App\Models\DiaSinServicio;
use App\Models\EleccionEmpleado;
use App\Models\Menu;
use App\Models\OrdenProduccion;
use App\Models\Paquete;
use App\Models\Pedido;
use App\Models\User;
use App\Services\ContratacionDePaquetes;
use App\Services\EjecutorDelCorte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Ejecución automática: el scheduler llama cada minuto a los comandos y cada uno decide si le toca.
 * Semana de prueba: lunes 9 a domingo 15 de nov de 2026 (el 16 es lunes).
 */
class TareasAutomaticasTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    /** Elecciones manuales abiertas para una fecha de entrega, con su disponibilidad. */
    private function eleccionesPara(string $fecha, int $cantidad = 2): Menu
    {
        $menu = $this->platillo();
        $this->disponible($menu, $fecha);
        $empresa = $this->empresaConContrato('Zapopan');
        for ($i = 0; $i < $cantidad; $i++) {
            DB::table('elecciones_empleado')->insert([
                'empleado_id' => $this->empleadoDe($empresa)->id, 'fecha' => $fecha, 'tiempo_comida' => 'comida', 'menu_id' => $menu->id,
                'precio_congelado' => 130, 'porcentaje_subsidio_congelado' => 60, 'origen' => 'manual',
            ]);
        }

        return $menu;
    }

    private function corteAutomatico(int $codigo = 0): void
    {
        $this->artisan('easylunch:corte-automatico')->assertExitCode($codigo);
    }

    // ------------------------------------------------------------------ el scheduler

    public function test_el_scheduler_tiene_programadas_las_tres_tareas(): void
    {
        Artisan::call('schedule:list');
        $lista = Artisan::output();

        $this->assertMatchesRegularExpression('/\*\s+\*\s+\*\s+\*\s+\*\s+php artisan easylunch:corte-automatico/', $lista, 'el corte se revisa cada minuto');
        $this->assertMatchesRegularExpression('/\*\s+\*\s+\*\s+\*\s+\*\s+php artisan easylunch:cancelar-pedidos-vencidos/', $lista);
        $this->assertMatchesRegularExpression('/0\s+8\s+\*\s+\*\s+\*\s+php artisan easylunch:avisos-de-contratos/', $lista, 'los avisos, todos los días a las 8:00');
    }

    public function test_docker_compose_levanta_el_scheduler(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));

        $this->assertStringContainsString('scheduler:', $compose);
        $this->assertStringContainsString('php artisan schedule:work', $compose);
    }

    // ------------------------------------------------------------------ corte automático

    public function test_no_corta_antes_de_la_hora_y_corta_a_las_18_00_en_punto(): void
    {
        $this->eleccionesPara('2026-11-12');

        $this->ahoraEs('2026-11-11 17:59:59');
        $this->corteAutomatico();
        $this->assertSame(0, Corte::count());
        $this->assertSame(2, EleccionEmpleado::whereNull('cerrada_en')->count());

        $this->ahoraEs('2026-11-11 18:00:00');
        $this->corteAutomatico();

        $corte = Corte::sole();
        $this->assertSame(['2026-11-12', false, null], [$corte->fecha_entrega->toDateString(), $corte->sin_cambios, $corte->ejecutado_por], 'lo lanzó el sistema');
        $this->assertSame(0, EleccionEmpleado::whereNull('cerrada_en')->count());
        $this->assertSame(1, OrdenProduccion::count());
    }

    public function test_llamarlo_cada_minuto_cortando_una_sola_vez_y_sin_dejar_registros_de_sin_cambios(): void
    {
        $this->eleccionesPara('2026-11-12');

        foreach (['18:00', '18:01', '18:02', '19:30', '23:59'] as $hora) {
            $this->ahoraEs("2026-11-11 {$hora}");
            $this->corteAutomatico();
        }

        $this->assertSame(1, Corte::count());
    }

    public function test_cada_dia_corta_la_entrega_siguiente_incluido_el_viernes_para_el_sabado(): void
    {
        foreach (['2026-11-12', '2026-11-13', '2026-11-14'] as $entrega) {
            $this->eleccionesPara($entrega, 1);
        }

        foreach (['2026-11-11 18:00', '2026-11-12 18:00', '2026-11-13 18:00'] as $momento) {
            $this->ahoraEs($momento);
            $this->corteAutomatico();
        }

        $this->assertSame(['2026-11-12', '2026-11-13', '2026-11-14'], Corte::orderBy('fecha_entrega')->get()->map(fn ($c) => $c->fecha_entrega->toDateString())->all());
    }

    public function test_el_sabado_no_corta_porque_el_domingo_no_hay_servicio_y_el_domingo_corta_el_lunes(): void
    {
        $this->eleccionesPara('2026-11-15', 1); // domingo: aunque hubiera elecciones, no es día de servicio
        $this->eleccionesPara('2026-11-16', 1);

        $this->ahoraEs('2026-11-14 20:00'); // sábado
        $this->corteAutomatico();
        $this->assertSame(0, Corte::count());

        $this->ahoraEs('2026-11-15 17:59');
        $this->corteAutomatico();
        $this->assertSame(0, Corte::count());

        $this->ahoraEs('2026-11-15 18:00'); // domingo 18:00
        $this->corteAutomatico();
        $this->assertSame('2026-11-16', Corte::sole()->fecha_entrega->toDateString());
    }

    public function test_un_festivo_general_no_se_corta(): void
    {
        $this->eleccionesPara('2026-11-12');
        DiaSinServicio::create(['fecha' => '2026-11-12', 'tipo' => 'festivo', 'motivo' => 'Festivo']);

        $this->ahoraEs('2026-11-11 19:00');
        $this->corteAutomatico();

        $this->assertSame(0, Corte::count());
    }

    public function test_respeta_la_hora_de_corte_configurada(): void
    {
        $this->ajuste('hora_corte', '17:00');
        $this->eleccionesPara('2026-11-12');

        $this->ahoraEs('2026-11-11 16:59');
        $this->corteAutomatico();
        $this->assertSame(0, Corte::count());

        $this->ahoraEs('2026-11-11 17:00');
        $this->corteAutomatico();
        $this->assertSame(1, Corte::count());
    }

    public function test_si_un_administrador_ya_lo_ejecuto_a_mano_el_automatico_no_hace_nada(): void
    {
        $this->eleccionesPara('2026-11-12');
        $this->ahoraEs('2026-11-11 12:00');
        app(EjecutorDelCorte::class)->ejecutar('2026-11-12', $this->usuario());

        $this->ahoraEs('2026-11-11 18:00');
        $this->corteAutomatico();

        $this->assertSame(1, Corte::count());
        $this->assertFalse(Corte::sole()->sin_cambios);
    }

    public function test_si_falla_reintenta_cada_5_minutos_y_en_cuanto_se_arregla_corta(): void
    {
        $this->eleccionesPara('2026-11-12');
        OrdenProduccion::creating(fn () => throw new \RuntimeException('Falla simulada'));

        $this->ahoraEs('2026-11-11 18:00');
        $this->corteAutomatico(1);
        $this->assertSame(['fallido'], Corte::pluck('estado')->all());
        $this->assertSame(2, EleccionEmpleado::whereNull('cerrada_en')->count(), 'un corte fallido no cambia nada');

        $this->ahoraEs('2026-11-11 18:04');
        $this->corteAutomatico(); // todavía no pasan 5 minutos: ni lo intenta
        $this->assertSame(1, Corte::count());

        $this->ahoraEs('2026-11-11 18:05');
        $this->corteAutomatico(1);
        $this->assertSame(2, Corte::where('estado', 'fallido')->count());

        OrdenProduccion::flushEventListeners(); // se arregló el problema
        $this->ahoraEs('2026-11-11 18:10');
        $this->corteAutomatico();

        $this->assertSame(1, Corte::where('estado', '<>', 'fallido')->count());
        $this->assertSame(0, EleccionEmpleado::whereNull('cerrada_en')->count());
        $this->ahoraEs('2026-11-11 18:20');
        $this->corteAutomatico();
        $this->assertSame(3, Corte::count(), 'ya cortó: no vuelve a intentar');
    }

    public function test_despues_de_12_intentos_fallidos_deja_de_insistir(): void
    {
        $this->eleccionesPara('2026-11-12');
        foreach (range(1, 12) as $i) {
            Corte::create(['fecha_entrega' => '2026-11-12', 'ejecutado_en' => sprintf('2026-11-11 18:%02d', $i), 'estado' => 'fallido', 'sin_cambios' => false, 'mensaje' => 'x']);
        }

        $this->ahoraEs('2026-11-11 23:00');
        $this->corteAutomatico();

        $this->assertSame(12, Corte::count());
        $this->assertSame(2, EleccionEmpleado::whereNull('cerrada_en')->count(), 'queda para que un administrador lo ejecute a mano');
    }

    // ------------------------------------------------------------------ pedidos vencidos y avisos

    public function test_cancela_los_pedidos_por_pagar_en_cuanto_vence_su_plazo(): void
    {
        $this->zonaDePruebas();
        $this->ahoraEs('2026-11-09 10:00');
        $cliente = User::findOrFail($this->usuario());
        $direccion = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. 1', 'latitude' => 20.67, 'longitude' => -103.35]);
        $menu = $this->platillo();
        $disp = $this->disponible($menu, '2026-11-10');
        $pedido = app(ContratacionDePaquetes::class)->pedir($cliente, Paquete::create(['nombre' => 'Un día', 'dias' => 1]), $direccion, [$menu]);

        $this->ahoraEs('2026-11-09 17:59:59');
        $this->artisan('easylunch:cancelar-pedidos-vencidos')->assertExitCode(0);
        $this->assertSame('pendiente_pago', $pedido->fresh()->estado);

        $this->ahoraEs('2026-11-09 18:00:00');
        $this->artisan('easylunch:cancelar-pedidos-vencidos')->expectsOutputToContain('Pedidos cancelados: 1')->assertExitCode(0);

        $this->assertSame('cancelado', $pedido->fresh()->estado);
        $this->assertSame(0, $disp->fresh()->porciones_tomadas);
        $this->artisan('easylunch:cancelar-pedidos-vencidos')->doesntExpectOutputToContain('Pedidos cancelados')->assertExitCode(0);
        $this->assertSame(1, Pedido::count());
    }

    public function test_genera_los_avisos_de_contratos_por_vencer_sin_repetirlos(): void
    {
        $this->ahoraEs('2026-11-05 08:00');
        DB::table('roles')->insertOrIgnore(['id' => 2, 'name' => 'admin', 'display_name' => 'Administrador']);
        DB::table('users')->insert(['name' => 'Admin', 'email' => 'admin@easylunch.mx', 'password' => 'x', 'role_id' => 2]);
        $this->empresaConContrato('Zapopan', ['vigencia_fin' => '2026-11-20']);

        $this->artisan('easylunch:avisos-de-contratos')->expectsOutputToContain('Avisos nuevos: 2')->assertExitCode(0);
        $this->artisan('easylunch:avisos-de-contratos')->expectsOutputToContain('Avisos nuevos: 0')->assertExitCode(0);

        $this->assertSame(2, Aviso::count());
    }
}
