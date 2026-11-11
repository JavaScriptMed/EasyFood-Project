<?php

namespace Tests\Feature\Api\V1;

use App\Models\Address;
use App\Models\ContratoComedor;
use App\Models\FacturaMensual;
use App\Models\MensajeTicket;
use App\Models\Paquete;
use App\Models\Pedido;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** Panel de administración: facturación, liquidaciones, clientes, pedidos y soporte. «Hoy» es el 5 de nov de 2026 (se factura octubre). */
class AdminDineroYSoporteTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-05 09:00');
    }

    /** Platillos entregados a una empresa en octubre (una elección y una entrega «entregada» por fecha). */
    private function consumo($empresa, array $fechas, $menu = null): void
    {
        $menu ??= $this->platillo();
        foreach ($fechas as $fecha) {
            DB::table('elecciones_empleado')->insert([
                'empleado_id' => $this->empleadoDe($empresa)->id, 'fecha' => $fecha, 'tiempo_comida' => 'comida', 'menu_id' => $menu->id,
                'precio_congelado' => 130, 'porcentaje_subsidio_congelado' => 60, 'origen' => 'manual', 'cerrada_en' => "{$fecha} 00:00:00",
            ]);
            DB::table('entregas_empresa')->insertOrIgnore(['empresa_id' => $empresa->id, 'fecha' => $fecha, 'platillos' => 1, 'ventana_inicio' => '12:30', 'ventana_fin' => '12:50', 'estado' => 'entregada']);
        }
    }

    // ------------------------------------------------------------------ facturación

    public function test_genera_la_facturacion_del_mes_con_total_partes_y_diferencia_por_minimo(): void
    {
        $this->comoRol('admin');
        $empresa = $this->empresaConContrato('Zapopan', ['compra_minima_mensual' => 10]);
        $this->consumo($empresa, ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08']);

        $filas = $this->postJson('/api/v1/admin/facturas/generar', ['mes' => '2026-10'])->assertOk()->json();

        $this->assertCount(1, $filas);
        $this->assertEquals([$empresa->id, '2026-10', 4, 312.0, 208.0, 6, 780.0, 1300.0, 'borrador'],
            [$filas[0]['empresa_id'], $filas[0]['mes'], $filas[0]['platillos'], $filas[0]['parte_empresa'], $filas[0]['parte_empleados'], $filas[0]['platillos_faltantes'],
                $filas[0]['diferencia_minimo'], $filas[0]['total'], $filas[0]['estado']]);
        $this->assertSame('EL-202610-001', $filas[0]['folio']);
    }

    public function test_no_se_factura_un_mes_que_no_ha_terminado(): void
    {
        $this->comoRol('admin');

        $this->postJson('/api/v1/admin/facturas/generar', ['mes' => '2026-11'])->assertStatus(422)->assertJsonPath('codigo', 'mes_sin_terminar');
        $this->postJson('/api/v1/admin/facturas/generar', ['mes' => 'octubre'])->assertStatus(422)->assertJsonStructure(['errores' => ['mes']]);
    }

    public function test_lista_las_facturas_del_mes_con_la_empresa_y_muestra_el_detalle_por_empleado(): void
    {
        $this->comoRol('admin');
        $empresa = $this->empresaConContrato('Zapopan', ['compra_minima_mensual' => 3]);
        $this->consumo($empresa, ['2026-10-05', '2026-10-06']);
        $this->postJson('/api/v1/admin/facturas/generar', ['mes' => '2026-10'])->assertOk();

        $lista = $this->getJson('/api/v1/admin/facturas?mes=2026-10')->assertOk()->json();
        $this->assertSame($empresa->nombre, $lista[0]['empresa']['nombre']);

        $detalle = $this->getJson("/api/v1/admin/facturas/{$lista[0]['id']}")->assertOk()->json();
        $this->assertEquals(260.0, $detalle['importe_consumo']);
        $this->assertCount(2, $detalle['lineas']);
        $this->assertEquals([1, 130.0, 78.0, 52.0], [$detalle['lineas'][0]['platillos'], $detalle['lineas'][0]['importe'], $detalle['lineas'][0]['parte_empresa'], $detalle['lineas'][0]['parte_empleado']]);
        $this->getJson('/api/v1/admin/facturas?mes=2026-09')->assertJsonCount(0);
    }

    public function test_emite_y_paga_una_factura_en_orden_y_no_al_reves(): void
    {
        $this->comoRol('admin');
        $empresa = $this->empresaConContrato();
        $id = $this->postJson('/api/v1/admin/facturas/generar', ['mes' => '2026-10'])->json('0.id');

        $this->postJson("/api/v1/admin/facturas/{$id}/pagar")->assertStatus(422)->assertJsonPath('codigo', 'factura_no_emitida');
        $this->postJson("/api/v1/admin/facturas/{$id}/emitir")->assertOk()->assertJsonPath('estado', 'emitida');
        $this->postJson("/api/v1/admin/facturas/{$id}/emitir")->assertStatus(422)->assertJsonPath('codigo', 'factura_ya_emitida');
        $this->postJson("/api/v1/admin/facturas/{$id}/pagar")->assertOk()->assertJsonPath('estado', 'pagada');
        $this->assertSame($empresa->id, FacturaMensual::sole()->empresa_id);
    }

    public function test_la_empresa_ve_sus_facturas_emitidas_y_no_las_de_otras_ni_los_borradores(): void
    {
        $miEmpresa = $this->empresaConContrato('Zapopan', ['compra_minima_mensual' => 3]);
        $otra = $this->empresaConContrato('Zapopan');
        $this->consumo($miEmpresa, ['2026-10-05']);
        $this->comoRol('admin');
        $this->postJson('/api/v1/admin/facturas/generar', ['mes' => '2026-10'])->assertOk();
        $ids = FacturaMensual::pluck('id', 'empresa_id');

        $usuario = $this->usuarioDeRol('company');
        $miEmpresa->update(['usuario_id' => $usuario->id]);
        \Laravel\Sanctum\Sanctum::actingAs($usuario);

        $this->getJson('/api/v1/empresa/facturas')->assertOk()->assertJsonCount(0); // todavía es borrador
        $this->getJson("/api/v1/empresa/facturas/{$ids[$miEmpresa->id]}")->assertStatus(404);

        FacturaMensual::whereKey($ids[$miEmpresa->id])->update(['estado' => 'emitida', 'emitida_en' => now()]);
        $this->getJson('/api/v1/empresa/facturas')->assertOk()->assertJsonCount(1)->assertJsonPath('0.empresa_id', $miEmpresa->id);
        $this->getJson("/api/v1/empresa/facturas/{$ids[$miEmpresa->id]}")->assertOk()->assertJsonPath('lineas.0.platillos', 1);

        FacturaMensual::whereKey($ids[$otra->id])->update(['estado' => 'emitida', 'emitida_en' => now()]);
        $this->getJson("/api/v1/empresa/facturas/{$ids[$otra->id]}")->assertStatus(404);
    }

    // ------------------------------------------------------------------ liquidaciones

    public function test_genera_y_paga_las_liquidaciones_a_comedores_con_su_diferencia_por_minimo(): void
    {
        $this->comoRol('admin');
        $comedor = $this->comedorNuevo();
        ContratoComedor::create(['comedor_id' => $comedor->id, 'precio_por_platillo' => 49, 'compra_minima_mensual' => 5, 'vigencia_inicio' => '2026-07-01', 'vigencia_fin' => '2027-01-31']);
        $this->consumo($this->empresaConContrato(), ['2026-10-05', '2026-10-06', '2026-10-07'], $this->platillo($comedor));
        $this->comedorNuevo(); // sin contrato: se omite

        $filas = $this->postJson('/api/v1/admin/liquidaciones/generar', ['mes' => '2026-10'])->assertOk()->json();

        $this->assertCount(1, $filas);
        $this->assertEquals([3, 2, 98.0, 245.0, 'pendiente'], [$filas[0]['platillos_entregados'], $filas[0]['platillos_faltantes'], $filas[0]['diferencia_minimo'], $filas[0]['monto'], $filas[0]['estado']]);
        $this->getJson('/api/v1/admin/liquidaciones?mes=2026-10')->assertOk()->assertJsonPath('0.comedor.id', $comedor->id);

        $this->postJson("/api/v1/admin/liquidaciones/{$filas[0]['id']}/pagar")->assertOk()->assertJsonPath('estado', 'pagado');
        $this->postJson("/api/v1/admin/liquidaciones/{$filas[0]['id']}/pagar")->assertStatus(422)->assertJsonPath('codigo', 'liquidacion_ya_pagada');
    }

    // ------------------------------------------------------------------ clientes, pedidos y soporte

    private function clienteConPedido(float $total = 300): array
    {
        $this->zonaDePruebas();
        $cliente = $this->usuarioDeRol('customer');
        $direccion = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. 1', 'latitude' => 20.67, 'longitude' => -103.35]);
        $paquete = Paquete::firstOrCreate(['dias' => 2], ['nombre' => 'Dos días']);
        $pedido = Pedido::create(['folio' => 'GDL-'.uniqid(), 'cliente_id' => $cliente->id, 'paquete_id' => $paquete->id, 'direccion_id' => $direccion->id,
            'fecha_inicio' => '2026-11-16', 'fecha_fin' => '2026-11-17', 'total' => $total, 'estado' => 'recibido', 'pagado_en' => now(), 'metodo_pago' => 'tarjeta']);

        return [$cliente, $pedido];
    }

    public function test_lista_clientes_con_su_gasto_y_pedidos_ordenados_por_gasto(): void
    {
        $this->comoRol('admin');
        [$pocoGasto] = $this->clienteConPedido(100);
        [$muchoGasto] = $this->clienteConPedido(500);
        $this->usuarioDeRol('admin'); // no es cliente

        $filas = $this->getJson('/api/v1/admin/clientes')->assertOk()->json();

        $this->assertSame([$muchoGasto->id, $pocoGasto->id], array_column($filas, 'usuario_id'));
        $this->assertEquals([500.0, 1, 1], [$filas[0]['gasto_total'], $filas[0]['pedidos_total'], $filas[0]['pedidos_activos']]);
    }

    public function test_muestra_el_detalle_de_un_cliente_con_pedidos_y_tickets(): void
    {
        $this->comoRol('admin');
        [$cliente, $pedido] = $this->clienteConPedido();
        Ticket::create(['folio' => 'T-1', 'cliente_id' => $cliente->id, 'pedido_id' => $pedido->id, 'asunto' => 'No llegó']);

        $d = $this->getJson("/api/v1/admin/clientes/{$cliente->id}")->assertOk()->json();

        $this->assertSame([$pedido->folio, 'recibido'], [$d['pedidos'][0]['folio'], $d['pedidos'][0]['estado']]);
        $this->assertSame(['No llegó', $pedido->folio], [$d['tickets'][0]['asunto'], $d['tickets'][0]['pedido_folio']]);
        $this->assertSame(1, $d['tickets_abiertos']);
        $this->getJson('/api/v1/admin/clientes/999999')->assertStatus(404);
    }

    public function test_lista_los_pedidos_con_filtro_de_estado(): void
    {
        $this->comoRol('support');
        $this->clienteConPedido();

        $this->getJson('/api/v1/admin/pedidos')->assertOk()->assertJsonCount(1)->assertJsonPath('0.zona', 'Centro')->assertJsonPath('0.plan_nombre', 'Dos días');
        $this->getJson('/api/v1/admin/pedidos?estado=cancelado')->assertJsonCount(0);
    }

    public function test_el_agente_atiende_un_ticket_y_el_cliente_queda_con_su_historial(): void
    {
        $agente = $this->comoRol('support');
        [$cliente, $pedido] = $this->clienteConPedido();
        $ticket = Ticket::create(['folio' => 'T-1', 'cliente_id' => $cliente->id, 'pedido_id' => $pedido->id, 'asunto' => 'No llegó', 'mensajes_sin_leer' => 1]);
        MensajeTicket::create(['ticket_id' => $ticket->id, 'autor_id' => $cliente->id, 'autor_tipo' => 'cliente', 'texto' => 'No me llegó el pedido']);

        $this->getJson('/api/v1/admin/tickets')->assertOk()->assertJsonPath('0.cliente_nombre', $cliente->name)->assertJsonPath('0.mensajes_sin_leer', 1);
        $detalle = $this->getJson("/api/v1/admin/tickets/{$ticket->id}")->assertOk()->json();
        $this->assertSame(['cliente'], array_column($detalle['mensajes'], 'autor'));
        $this->assertEquals([300.0, 'Centro'], [$detalle['resumen_pedido']['total'], $detalle['resumen_pedido']['zona']]);

        $this->postJson("/api/v1/admin/tickets/{$ticket->id}/mensajes", ['texto' => 'Lo revisamos hoy mismo'])->assertCreated()->assertJsonPath('autor', 'agente')->assertJsonPath('autor_id', $agente->id);
        $this->assertSame(['en_proceso', 0], [$ticket->fresh()->estado, $ticket->fresh()->mensajes_sin_leer]);
        $this->postJson("/api/v1/admin/tickets/{$ticket->id}/mensajes", ['texto' => ''])->assertStatus(422)->assertJsonStructure(['errores' => ['texto']]);
        $this->getJson('/api/v1/admin/tickets?estado=resuelto')->assertJsonCount(0);
        $this->assertNotNull(User::find($agente->id));
    }
}
