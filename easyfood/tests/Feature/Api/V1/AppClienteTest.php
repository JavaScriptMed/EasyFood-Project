<?php

namespace Tests\Feature\Api\V1;

use App\Models\Address;
use App\Models\MensajeContacto;
use App\Models\Paquete;
use App\Models\Pedido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** App móvil: paquetes, direcciones y pedidos de los clientes, y el formulario de contacto. «Hoy» es el lunes 9 de nov de 2026 a las 10:00. */
class AppClienteTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-09 10:00');
        $this->zonaDePruebas('Centro');
    }

    private function direccionValida(array $extra = []): array
    {
        return $extra + ['etiqueta' => 'Casa', 'direccion' => 'Av. Vallarta 1248', 'numero_interior' => '4B', 'codigo_postal' => '44100', 'latitud' => 20.67, 'longitud' => -103.35, 'referencias' => 'Portón negro'];
    }

    // ------------------------------------------------------------------ paquetes

    public function test_lista_los_paquetes_activos_con_el_descuento_que_defina_el_administrador(): void
    {
        $this->comoRol('customer');
        Paquete::create(['nombre' => 'Un día', 'dias' => 1, 'descuento_porcentaje' => 0]);
        Paquete::create(['nombre' => 'Semana', 'dias' => 6, 'descuento_porcentaje' => 12, 'destacado' => true, 'detalle' => 'El más pedido']);
        Paquete::create(['nombre' => 'Viejo', 'dias' => 2])->desactivar();

        $filas = $this->getJson('/api/v1/cliente/paquetes')->assertOk()->json();

        $this->assertSame(['Un día', 'Semana'], array_column($filas, 'nombre'));
        $this->assertEquals([0, 12], array_column($filas, 'descuento_porcentaje'));
        $this->assertTrue($filas[1]['destacado']);
    }

    // ------------------------------------------------------------------ direcciones

    public function test_guarda_una_direccion_con_su_zona_calculada_y_la_lista(): void
    {
        $this->comoRol('customer');

        $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida(['predeterminada' => true]))->assertCreated()
            ->assertJsonPath('zona', 'Centro')->assertJsonPath('sin_cobertura', false)->assertJsonPath('direccion', 'Av. Vallarta 1248')->assertJsonPath('predeterminada', true);

        $this->getJson('/api/v1/cliente/direcciones')->assertOk()->assertJsonCount(1)->assertJsonPath('0.numero_interior', '4B');
    }

    public function test_una_direccion_fuera_de_cobertura_se_guarda_pero_avisa(): void
    {
        $this->comoRol('customer');

        $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida(['latitud' => 19.43, 'longitud' => -99.13]))->assertCreated()
            ->assertJsonPath('zona', null)->assertJsonPath('sin_cobertura', true);
    }

    public function test_editar_las_coordenadas_recalcula_la_zona_y_solo_una_direccion_es_predeterminada(): void
    {
        $this->comoRol('customer');
        $a = $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida(['predeterminada' => true]))->json('id');
        $b = $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida(['etiqueta' => 'Trabajo', 'predeterminada' => true]))->json('id');

        $this->assertFalse(Address::find($a)->is_default);
        $this->assertTrue(Address::find($b)->is_default);

        $this->putJson("/api/v1/cliente/direcciones/{$b}", ['latitud' => 25.0])->assertOk()->assertJsonPath('sin_cobertura', true);
        $this->putJson("/api/v1/cliente/direcciones/{$b}", ['latitud' => 20.67])->assertOk()->assertJsonPath('zona', 'Centro');
    }

    public function test_valida_las_coordenadas_y_los_campos_obligatorios(): void
    {
        $this->comoRol('customer');

        $this->postJson('/api/v1/cliente/direcciones', [])->assertStatus(422)->assertJsonStructure(['errores' => ['direccion', 'latitud', 'longitud']]);
        $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida(['latitud' => 91]))->assertStatus(422)->assertJsonStructure(['errores' => ['latitud']]);
    }

    public function test_nadie_ve_ni_toca_las_direcciones_de_otro_cliente(): void
    {
        $this->comoRol('customer');
        $ajena = Address::create(['user_id' => $this->usuarioDeRol('customer')->id, 'street_address' => 'Ajena', 'latitude' => 20.67, 'longitude' => -103.35]);

        $this->getJson('/api/v1/cliente/direcciones')->assertJsonCount(0);
        $this->putJson("/api/v1/cliente/direcciones/{$ajena->id}", ['direccion' => 'Mía ahora'])->assertStatus(404);
        $this->deleteJson("/api/v1/cliente/direcciones/{$ajena->id}")->assertStatus(404);
    }

    public function test_elimina_una_direccion_sin_pedidos_pero_no_una_con_pedidos(): void
    {
        $cliente = $this->comoRol('customer');
        $libre = $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida())->json('id');
        $usada = $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida(['etiqueta' => 'Usada']))->json('id');
        $paquete = Paquete::create(['nombre' => 'Un día', 'dias' => 1]);
        Pedido::create(['folio' => 'GDL-X', 'cliente_id' => $cliente->id, 'paquete_id' => $paquete->id, 'direccion_id' => $usada, 'fecha_inicio' => '2026-11-10',
            'fecha_fin' => '2026-11-10', 'total' => 49, 'estado' => 'cancelado']);

        $this->deleteJson("/api/v1/cliente/direcciones/{$libre}")->assertOk();
        $this->assertNull(Address::find($libre));
        $this->deleteJson("/api/v1/cliente/direcciones/{$usada}")->assertStatus(422)->assertJsonPath('codigo', 'direccion_en_uso');
    }

    // ------------------------------------------------------------------ pedidos

    private function menuDisponible(array $fechas = ['2026-11-10', '2026-11-11'], float $precio = 100): int
    {
        $menu = $this->platillo(null, 'comida', ['price' => $precio]);
        foreach ($fechas as $fecha) {
            $this->disponible($menu, $fecha);
        }

        return $menu->id;
    }

    public function test_el_cliente_pide_un_paquete_y_el_pedido_trae_el_tiempo_para_pagar(): void
    {
        $this->comoRol('customer');
        $direccion = $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida())->json('id');
        $paquete = Paquete::create(['nombre' => 'Dos días', 'dias' => 2, 'descuento_porcentaje' => 5]);
        $menu = $this->menuDisponible();

        $p = $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $paquete->id, 'direccion_id' => $direccion, 'platillos' => [$menu, $menu]])->assertCreated()->json();

        $this->assertSame(['pendiente_pago', '2026-11-10', '2026-11-11', 'Centro', 'GDL-20261109-0001'], [$p['estado'], $p['fecha_inicio'], $p['fecha_fin'], $p['zona'], $p['folio']]);
        $this->assertEquals(190.0, $p['total'], '(100 + 100) × 95 %');
        $this->assertSame(8 * 3600, $p['segundos_para_pagar']);
        $this->assertStringStartsWith('2026-11-09T18:00:00', $p['vence_pago_en']);
        $this->assertSame(2, $p['entregas_totales']);
        $this->assertCount(2, $p['dias']);
    }

    public function test_paga_el_pedido_en_forma_simulada_y_despues_ya_no_tiene_tiempo_para_pagar(): void
    {
        $this->comoRol('customer');
        $direccion = $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida())->json('id');
        $paquete = Paquete::create(['nombre' => 'Un día', 'dias' => 1]);
        $id = $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $paquete->id, 'direccion_id' => $direccion, 'platillos' => [$this->menuDisponible()]])->json('id');

        $this->postJson("/api/v1/cliente/pedidos/{$id}/pagar", ['metodo_pago' => 'tarjeta', 'ultimos_digitos' => '48'])->assertStatus(422)->assertJsonStructure(['errores' => ['ultimos_digitos']]);
        $this->postJson("/api/v1/cliente/pedidos/{$id}/pagar", ['metodo_pago' => 'tarjeta', 'ultimos_digitos' => '4821'])->assertOk()
            ->assertJsonPath('estado', 'recibido')->assertJsonPath('ultimos_digitos', '4821')->assertJsonPath('segundos_para_pagar', null);
        $this->postJson("/api/v1/cliente/pedidos/{$id}/pagar", ['metodo_pago' => 'tarjeta'])->assertStatus(422)->assertJsonPath('codigo', 'pedido_no_pagable');
    }

    public function test_pagar_despues_del_corte_cancela_el_pedido_y_pide_hacerlo_de_nuevo(): void
    {
        $this->comoRol('customer');
        $direccion = $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida())->json('id');
        $paquete = Paquete::create(['nombre' => 'Un día', 'dias' => 1]);
        $id = $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $paquete->id, 'direccion_id' => $direccion, 'platillos' => [$this->menuDisponible()]])->json('id');

        $this->ahoraEs('2026-11-09 18:00:00');
        $this->postJson("/api/v1/cliente/pedidos/{$id}/pagar", ['metodo_pago' => 'tarjeta'])->assertStatus(422)->assertJsonPath('codigo', 'pedido_vencido');
        $this->getJson("/api/v1/cliente/pedidos/{$id}")->assertOk()->assertJsonPath('estado', 'cancelado')->assertJsonPath('segundos_para_pagar', null);
        $this->assertSame(0, (int) DB::table('disponibilidad_dia')->sum('porciones_tomadas'));
    }

    public function test_no_se_pide_con_una_direccion_sin_cobertura_ni_ajena_ni_con_platillos_de_menos(): void
    {
        $this->comoRol('customer');
        $lejos = $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida(['latitud' => 19.43, 'longitud' => -99.13]))->json('id');
        $buena = $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida())->json('id');
        $ajena = Address::create(['user_id' => $this->usuarioDeRol('customer')->id, 'street_address' => 'Ajena', 'latitude' => 20.67, 'longitude' => -103.35]);
        $dos = Paquete::create(['nombre' => 'Dos días', 'dias' => 2]);
        $menu = $this->menuDisponible();

        $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $dos->id, 'direccion_id' => $lejos, 'platillos' => [$menu, $menu]])->assertStatus(422)->assertJsonPath('codigo', 'sin_cobertura');
        $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $dos->id, 'direccion_id' => $ajena->id, 'platillos' => [$menu, $menu]])->assertStatus(404);
        $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $dos->id, 'direccion_id' => $buena, 'platillos' => [$menu]])->assertStatus(422)->assertJsonPath('codigo', 'platillos_incompletos');
        $this->postJson('/api/v1/cliente/pedidos', [])->assertStatus(422)->assertJsonStructure(['errores' => ['paquete_id', 'direccion_id', 'platillos']]);
        $this->assertSame(0, Pedido::count());
    }

    public function test_el_cliente_solo_ve_sus_pedidos(): void
    {
        $cliente = $this->comoRol('customer');
        $direccion = $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida())->json('id');
        $paquete = Paquete::create(['nombre' => 'Un día', 'dias' => 1]);
        $mio = $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $paquete->id, 'direccion_id' => $direccion, 'platillos' => [$this->menuDisponible()]])->json('id');
        $otroCliente = $this->usuarioDeRol('customer');
        $otraDireccion = Address::create(['user_id' => $otroCliente->id, 'street_address' => 'Otra', 'latitude' => 20.67, 'longitude' => -103.35]);
        $ajeno = Pedido::create(['folio' => 'GDL-AJENO', 'cliente_id' => $otroCliente->id, 'paquete_id' => $paquete->id, 'direccion_id' => $otraDireccion->id,
            'fecha_inicio' => '2026-11-10', 'fecha_fin' => '2026-11-10', 'total' => 49, 'estado' => 'cancelado']);

        $this->getJson('/api/v1/cliente/pedidos')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $mio)->assertJsonPath('0.cliente_id', $cliente->id);
        $this->getJson("/api/v1/cliente/pedidos/{$ajeno->id}")->assertStatus(404);
        $this->postJson("/api/v1/cliente/pedidos/{$ajeno->id}/pagar", ['metodo_pago' => 'tarjeta'])->assertStatus(404);
    }

    // ------------------------------------------------------------------ contacto (público)

    public function test_el_formulario_de_contacto_se_envia_sin_iniciar_sesion(): void
    {
        $this->postJson('/api/v1/contacto', ['nombre' => 'Mariana', 'correo' => 'mariana@nexo.mx', 'empresa' => 'Nexo', 'mensaje' => 'Quiero una cotización'])
            ->assertCreated()->assertJsonPath('mensaje', 'Gracias por escribirnos: te respondemos lo antes posible.');

        $this->assertSame(['Mariana', 'Nexo'], [MensajeContacto::sole()->nombre, MensajeContacto::sole()->empresa]);
    }

    public function test_el_contacto_valida_y_limita_los_envios(): void
    {
        $this->postJson('/api/v1/contacto', ['correo' => 'no-es-correo'])->assertStatus(422)->assertJsonStructure(['mensaje', 'errores' => ['nombre', 'correo', 'mensaje']]);
        $this->postJson('/api/v1/contacto', ['nombre' => 'A', 'correo' => 'a@b.mx', 'mensaje' => 'Hola'])->assertStatus(422)->assertJsonStructure(['errores' => ['nombre', 'mensaje']]);

        $datos = ['nombre' => 'Ana', 'correo' => 'a@b.mx', 'mensaje' => 'Hola, quiero informes'];
        foreach (range(1, 8) as $i) { // con los 2 intentos inválidos de arriba son 10 en el minuto
            $this->postJson('/api/v1/contacto', $datos)->assertCreated();
        }
        $this->postJson('/api/v1/contacto', $datos)->assertStatus(429)->assertJsonPath('codigo', 'demasiados_intentos');
    }
}
