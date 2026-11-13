<?php

namespace Tests\Feature\Negocio;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Address;
use App\Models\DisponibilidadDia;
use App\Models\Menu;
use App\Models\Paquete;
use App\Models\Pedido;
use App\Models\PedidoDia;
use App\Models\User;
use App\Services\ContratacionDePaquetes;
use App\Services\EjecutorDelCorte;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Un pedido por pagar vence en el corte de su primer día de entrega. Si pasa sin pagarse se cancela, se liberan sus
 * porciones y hay que pedirlo de nuevo. El pedido expone cuánto tiempo queda para pagar.
 * Los pedidos se hacen el lunes 9 de nov de 2026 a las 10:00; el primero día de entrega es el martes 10 (corte: lunes 18:00).
 */
class PedidosPorPagarTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private User $cliente;

    private Address $direccion;

    private Menu $menu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-09 10:00');
        $this->zonaDePruebas();
        $this->cliente = User::findOrFail($this->usuario());
        $this->direccion = Address::create(['user_id' => $this->cliente->id, 'street_address' => 'Av. 1', 'latitude' => 20.67, 'longitude' => -103.35]);
        $this->menu = $this->platillo();
        foreach (['2026-11-10', '2026-11-11', '2026-11-12'] as $fecha) {
            $this->disponible($this->menu, $fecha);
        }
    }

    private function paquetes(): ContratacionDePaquetes
    {
        return app(ContratacionDePaquetes::class);
    }

    private function pedir(int $dias = 1): Pedido
    {
        $paquete = Paquete::firstOrCreate(['dias' => $dias], ['nombre' => "Paquete {$dias} días", 'descuento_porcentaje' => 0]);

        return $this->paquetes()->pedir($this->cliente, $paquete, $this->direccion, array_fill(0, $dias, $this->menu));
    }

    private function porcionesTomadas(): int
    {
        return (int) DisponibilidadDia::sum('porciones_tomadas');
    }

    public function test_el_plazo_para_pagar_es_el_corte_de_la_primera_entrega(): void
    {
        $pedido = $this->pedir(2);

        $this->assertSame('2026-11-09 18:00', $pedido->vence_pago_en->setTimezone('America/Mexico_City')->format('Y-m-d H:i'));
    }

    public function test_el_estado_del_pedido_trae_los_segundos_que_le_quedan_para_pagar(): void
    {
        $pedido = $this->pedir();

        $this->assertSame(8 * 3600, $pedido->segundos_para_pagar);
        $this->assertSame(8 * 3600, $pedido->toArray()['segundos_para_pagar']);
        $this->assertArrayHasKey('vence_pago_en', $pedido->toArray());

        $this->ahoraEs('2026-11-09 17:59:30');
        $this->assertSame(30, $pedido->fresh()->segundos_para_pagar);

        $this->ahoraEs('2026-11-09 18:00:00');
        $this->assertSame(0, $pedido->fresh()->segundos_para_pagar);
        $this->ahoraEs('2026-11-09 20:00');
        $this->assertSame(0, $pedido->fresh()->segundos_para_pagar, 'nunca negativo');
    }

    public function test_un_pedido_pagado_o_cancelado_ya_no_tiene_tiempo_para_pagar(): void
    {
        $pagado = $this->paquetes()->pagar($this->pedir(), 'tarjeta', '4821');
        $this->assertNull($pagado->segundos_para_pagar);

        $cancelado = $this->pedir();
        $this->ahoraEs('2026-11-09 18:00');
        $this->paquetes()->cancelarVencidos();
        $this->assertSame('cancelado', $cancelado->fresh()->estado);
        $this->assertNull($cancelado->fresh()->segundos_para_pagar);
    }

    public function test_se_puede_pagar_hasta_un_segundo_antes_del_corte(): void
    {
        $pedido = $this->pedir();

        $this->ahoraEs('2026-11-09 17:59:59');
        $pagado = $this->paquetes()->pagar($pedido, 'tarjeta', '4821');

        $this->assertSame('recibido', $pagado->estado);
        $this->assertNull($pagado->segundos_para_pagar);
    }

    public function test_pasado_el_corte_pagar_cancela_el_pedido_y_libera_las_porciones(): void
    {
        $pedido = $this->pedir(2);
        $this->assertSame(2, $this->porcionesTomadas());

        $this->ahoraEs('2026-11-09 18:00:00');
        try {
            $this->paquetes()->pagar($pedido, 'tarjeta', '4821');
            $this->fail('Debía fallar: el plazo terminó.');
        } catch (ReglaDeNegocioException $e) {
            $this->assertSame('pedido_vencido', $e->codigo);
            $this->assertStringContainsString('hay que pedirlo de nuevo', $e->getMessage());
        }

        $this->assertSame('cancelado', $pedido->fresh()->estado);
        $this->assertNull($pedido->fresh()->pagado_en);
        $this->assertSame(['cancelada', 'cancelada'], PedidoDia::where('pedido_id', $pedido->id)->pluck('estado')->all());
        $this->assertSame(0, $this->porcionesTomadas(), 'las porciones apartadas vuelven a quedar libres');

        try {
            $this->paquetes()->pagar($pedido, 'tarjeta', '4821');
            $this->fail('Un pedido cancelado ya no se paga.');
        } catch (ReglaDeNegocioException $e) {
            $this->assertSame('pedido_no_pagable', $e->codigo, 'hay que pedirlo de nuevo');
        }
    }

    public function test_cancelar_los_vencidos_solo_toca_los_que_ya_vencieron_y_se_puede_repetir(): void
    {
        $vencido = $this->pedir();
        $this->ahoraEs('2026-11-10 10:00');
        $vigente = $this->pedir(); // primer día: miércoles 11 (corte martes 18:00)
        $this->ahoraEs('2026-11-09 10:00');
        $pagado = $this->paquetes()->pagar($this->pedir(), 'tarjeta', '4821');

        $this->ahoraEs('2026-11-09 18:00');
        $this->assertSame(1, $this->paquetes()->cancelarVencidos());
        $this->assertSame(0, $this->paquetes()->cancelarVencidos(), 'repetirlo no hace nada');

        $this->assertSame('cancelado', $vencido->fresh()->estado);
        $this->assertSame('pendiente_pago', $vigente->fresh()->estado);
        $this->assertSame('recibido', $pagado->fresh()->estado, 'un pedido pagado no se cancela');
    }

    public function test_el_corte_cancela_los_pedidos_por_pagar_de_ese_dia_y_libera_sus_porciones(): void
    {
        $sinPagar = $this->pedir();
        $pagado = $this->paquetes()->pagar($this->pedir(), 'tarjeta', '4821');
        $this->assertSame(2, $this->porcionesTomadas());

        $this->ahoraEs('2026-11-09 18:30');
        app(EjecutorDelCorte::class)->ejecutar('2026-11-10');

        $this->assertSame('cancelado', $sinPagar->fresh()->estado);
        $this->assertSame('recibido', $pagado->fresh()->estado);
        $this->assertSame(1, DisponibilidadDia::whereDate('fecha', '2026-11-10')->value('porciones_tomadas'), 'solo queda la del pedido pagado');
    }

    public function test_un_corte_manual_anticipado_tambien_cancela_los_que_siguen_por_pagar(): void
    {
        $sinPagar = $this->pedir();

        app(EjecutorDelCorte::class)->ejecutar('2026-11-10', $this->usuario()); // 10:00, antes de las 18:00

        $this->assertSame('cancelado', $sinPagar->fresh()->estado);
        $this->assertSame(0, $this->porcionesTomadas());
    }

    public function test_si_el_corte_manual_ya_cerro_ese_dia_pagar_cancela_el_pedido(): void
    {
        $pedido = $this->pedir();
        app(EjecutorDelCorte::class)->ejecutar('2026-11-10', $this->usuario());
        $pedido->forceFill(['estado' => 'pendiente_pago'])->saveQuietly(); // como si hubiera quedado por pagar

        $this->expectException(ReglaDeNegocioException::class);
        $this->paquetes()->pagar($pedido->fresh(), 'tarjeta', '4821');
    }

    public function test_la_base_exige_la_fecha_limite_mientras_esta_por_pagar(): void
    {
        $pedido = $this->pedir();

        $this->rechaza('pedidos_vence_pago_si_pendiente', fn () => \Illuminate\Support\Facades\DB::table('pedidos')->where('id', $pedido->id)->update(['vence_pago_en' => null]));
    }
}
