<?php

namespace Tests\Feature\Negocio;

use App\Models\DiaSinServicio;
use App\Services\CalendarioDeServicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** Regla: se entrega de lunes a sábado y el corte es a las 18:00 (Guadalajara) del día calendario anterior a la entrega. */
class CalendarioDeServicioTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private function entregaParaPedidoHecho(string $fechaHora): string
    {
        $this->ahoraEs($fechaHora);

        return app(CalendarioDeServicio::class)->fechaDeEntregaParaPedidoNuevo()->toDateString();
    }

    public function test_los_ejemplos_del_negocio_de_viernes_a_martes(): void
    {
        // 2026-11-13 es viernes.
        $this->assertSame('2026-11-14', $this->entregaParaPedidoHecho('2026-11-13 17:00'), 'viernes 17:00 → sábado');
        $this->assertSame('2026-11-16', $this->entregaParaPedidoHecho('2026-11-13 19:00'), 'viernes 19:00 → lunes');
        $this->assertSame('2026-11-16', $this->entregaParaPedidoHecho('2026-11-14 10:00'), 'sábado → lunes');
        $this->assertSame('2026-11-16', $this->entregaParaPedidoHecho('2026-11-14 20:00'), 'sábado noche → lunes');
        $this->assertSame('2026-11-16', $this->entregaParaPedidoHecho('2026-11-15 17:00'), 'domingo 17:00 → lunes');
        $this->assertSame('2026-11-17', $this->entregaParaPedidoHecho('2026-11-15 19:00'), 'domingo 19:00 → martes');
    }

    public function test_entre_semana_antes_del_corte_es_el_dia_siguiente_y_despues_es_el_subsiguiente(): void
    {
        $this->assertSame('2026-11-12', $this->entregaParaPedidoHecho('2026-11-11 09:00'), 'miércoles → jueves');
        $this->assertSame('2026-11-13', $this->entregaParaPedidoHecho('2026-11-11 18:30'), 'miércoles tras el corte → viernes');
    }

    public function test_el_corte_es_exactamente_a_las_18_00_del_dia_anterior(): void
    {
        $calendario = app(CalendarioDeServicio::class);

        $this->ahoraEs('2026-11-11 17:59:59');
        $this->assertTrue($calendario->corteAbierto('2026-11-12'));

        $this->ahoraEs('2026-11-11 18:00:00');
        $this->assertFalse($calendario->corteAbierto('2026-11-12'), 'a las 18:00:00 ya está cerrado');

        $this->assertSame('2026-11-11 18:00', $calendario->momentoDelCorte('2026-11-12')->format('Y-m-d H:i'));
        $this->assertSame('America/Mexico_City', $calendario->momentoDelCorte('2026-11-12')->timezoneName);
    }

    public function test_la_hora_de_corte_configurada_se_respeta(): void
    {
        $this->ajuste('hora_corte', '17:00');
        $this->ahoraEs('2026-11-11 17:30');

        $this->assertFalse(app(CalendarioDeServicio::class)->corteAbierto('2026-11-12'));
    }

    public function test_el_domingo_nunca_es_dia_de_servicio_y_el_sabado_depende_de_la_configuracion(): void
    {
        $calendario = app(CalendarioDeServicio::class);
        $this->assertTrue($calendario->esDiaDeServicio('2026-11-14'), 'sábado, por defecto de lunes a sábado');
        $this->assertFalse($calendario->esDiaDeServicio('2026-11-15'), 'domingo');

        $this->ajuste('dias_entrega', 'lunes_viernes');
        $this->assertFalse($calendario->esDiaDeServicio('2026-11-14'));
        $this->assertSame('2026-11-16', $this->entregaParaPedidoHecho('2026-11-13 10:00'), 'sin sábados, el viernes entrega el lunes');
    }

    public function test_un_festivo_general_se_salta_y_el_cierre_de_una_empresa_solo_afecta_a_esa_empresa(): void
    {
        $calendario = app(CalendarioDeServicio::class);
        $empresa = $this->empresaConContrato();
        $otra = $this->empresaConContrato();

        DiaSinServicio::create(['fecha' => '2026-11-16', 'tipo' => 'festivo', 'motivo' => 'Día de la Revolución (observado)']);
        DiaSinServicio::create(['fecha' => '2026-11-17', 'tipo' => 'cierre_empresa', 'motivo' => 'Cierre por inventario', 'empresa_id' => $empresa->id]);

        $this->assertSame('2026-11-17', $this->entregaParaPedidoHecho('2026-11-14 10:00'), 'el lunes festivo se salta');
        $this->assertFalse($calendario->esDiaDeServicio('2026-11-17', $empresa->id));
        $this->assertTrue($calendario->esDiaDeServicio('2026-11-17', $otra->id));
        $this->assertTrue($calendario->esDiaDeServicio('2026-11-17'), 'el cierre de una empresa no cierra el servicio general');
    }

    public function test_un_corte_ejecutado_a_mano_cierra_esa_fecha_aunque_falten_horas(): void
    {
        $this->ahoraEs('2026-11-11 10:00');
        $this->empresaConContrato();
        app(\App\Services\EjecutorDelCorte::class)->ejecutar('2026-11-12', $this->usuario());

        $this->assertFalse(app(CalendarioDeServicio::class)->corteAbierto('2026-11-12'));
        $this->assertSame('2026-11-13', app(CalendarioDeServicio::class)->fechaDeEntregaParaPedidoNuevo()->toDateString());
    }

    public function test_un_paquete_toma_dias_de_servicio_consecutivos_sin_domingos(): void
    {
        $this->ahoraEs('2026-11-09 10:00'); // lunes
        $fechas = array_map(fn ($f) => $f->toDateString(), app(CalendarioDeServicio::class)->fechasDeUnPaquete(6));

        $this->assertSame(['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-16'], $fechas);
    }
}
