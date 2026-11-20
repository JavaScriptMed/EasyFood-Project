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

    public function test_la_entrega_de_individuales_es_el_sabado_y_cierra_ese_mismo_sabado_a_la_hora_de_corte(): void
    {
        $calendario = app(CalendarioDeServicio::class);
        $sabado = fn (string $ahora) => (function () use ($calendario, $ahora) {
            $this->ahoraEs($ahora);

            return $calendario->fechaDeEntregaDeIndividuales()->toDateString();
        })();

        $this->assertSame('2026-11-14', $sabado('2026-11-09 00:00'), 'lunes temprano');
        $this->assertSame('2026-11-14', $sabado('2026-11-13 23:59'), 'viernes tarde');
        $this->assertSame('2026-11-14', $sabado('2026-11-14 00:00'), 'sábado temprano');
        $this->assertSame('2026-11-14', $sabado('2026-11-14 17:59:59'), 'un segundo antes de la hora de corte');
        $this->assertSame('2026-11-21', $sabado('2026-11-14 18:00:00'), 'a la hora de corte ya cerró');
        $this->assertSame('2026-11-21', $sabado('2026-11-15 10:00'), 'domingo');

        $this->assertSame('2026-11-14 18:00', $calendario->momentoDeCierreDeIndividuales('2026-11-14')->format('Y-m-d H:i'), 'el mismo sábado, no el día anterior');
        $this->ajuste('hora_corte', '16:30');
        $this->assertSame('2026-11-14 16:30', $calendario->momentoDeCierreDeIndividuales('2026-11-14')->format('Y-m-d H:i'), 'respeta la hora configurada');
    }

    public function test_un_sabado_festivo_o_ya_cortado_no_es_dia_de_entrega_de_individuales(): void
    {
        $calendario = app(CalendarioDeServicio::class);
        $this->ahoraEs('2026-11-09 10:00');
        $this->assertTrue($calendario->esDiaDeEntregaDeIndividuales('2026-11-14'));
        $this->assertFalse($calendario->esDiaDeEntregaDeIndividuales('2026-11-13'), 'viernes');
        $this->assertFalse($calendario->esDiaDeEntregaDeIndividuales('2026-11-15'), 'domingo');

        \App\Models\DiaSinServicio::create(['fecha' => '2026-11-14', 'tipo' => 'festivo', 'motivo' => 'Festivo']);
        $this->assertFalse($calendario->esDiaDeEntregaDeIndividuales('2026-11-14'));
        $this->assertSame('2026-11-21', $calendario->fechaDeEntregaDeIndividuales()->toDateString());

        // Un corte de individuales ya ejecutado cierra ese sábado aunque todavía no sea la hora.
        app(\App\Services\CorteDeIndividuales::class)->ejecutar('2026-11-21', $this->usuario());
        $this->assertFalse($calendario->pedidosDeIndividualesAbiertos('2026-11-21'));
        $this->assertSame('2026-11-28', $calendario->fechaDeEntregaDeIndividuales()->toDateString());
    }

    public function test_los_dias_de_comida_de_un_pedido_son_los_siguientes_de_servicio_despues_de_la_entrega_sin_domingos(): void
    {
        $fechas = array_map(fn ($f) => $f->toDateString(), app(CalendarioDeServicio::class)->diasDeComidaDeUnPedido(6, '2026-11-14'));

        $this->assertSame(['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21'], $fechas);
        $this->assertSame(['2026-11-16'], array_map(fn ($f) => $f->toDateString(), app(CalendarioDeServicio::class)->diasDeComidaDeUnPedido(1, '2026-11-14')));
    }

    public function test_las_empresas_no_cambian_siguen_de_lunes_a_sabado_con_el_corte_del_dia_anterior(): void
    {
        $this->ahoraEs('2026-11-13 10:00'); // viernes
        $calendario = app(CalendarioDeServicio::class);

        $this->assertSame('2026-11-14', $calendario->fechaDeEntregaParaPedidoNuevo()->toDateString(), 'el sábado también se entrega a empresas');
        $this->assertSame('2026-11-13 18:00', $calendario->momentoDelCorte('2026-11-14')->format('Y-m-d H:i'), 'su corte es el día anterior');
        $this->ahoraEs('2026-11-13 19:00');
        $this->assertSame('2026-11-16', $calendario->fechaDeEntregaParaPedidoNuevo()->toDateString(), 'después del corte pasa al lunes');
    }
}
