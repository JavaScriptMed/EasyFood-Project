<?php

namespace Tests\Feature\Api\V1;

use App\Models\Address;
use App\Models\MensajeContacto;
use App\Models\Paquete;
use App\Models\Pedido;
use App\Services\ContratacionDePaquetes;
use App\Services\Pagos\PasarelaDePagos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\Support\PasarelaFalsa;
use Tests\TestCase;

/** App móvil: paquetes, direcciones y pedidos de los clientes, y el formulario de contacto. «Hoy» es el lunes 9 de nov de 2026 a las 10:00. */
class AppClienteTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private PasarelaFalsa $pasarela;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(PasarelaDePagos::class, $this->pasarela = new PasarelaFalsa); // sin red
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

        $this->postJson('/api/v1/cliente/direcciones', [])->assertStatus(422)->assertJsonStructure(['errores' => ['direccion']]);
        $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida(['longitud' => null]))->assertStatus(422)->assertJsonStructure(['errores' => ['longitud']]);
        $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida(['latitud' => 91]))->assertStatus(422)->assertJsonStructure(['errores' => ['latitud']]);
    }

    public function test_sin_coordenadas_la_direccion_se_ubica_por_su_texto_y_si_no_se_encuentra_se_pide_el_punto(): void
    {
        $this->comoRol('customer');
        Http::fake(['nominatim.openstreetmap.org/*' => Http::sequence()
            ->push([['lat' => '20.67', 'lon' => '-103.35']])
            ->push([['lat' => '25.0', 'lon' => '-103.35']])
            ->push([])]);
        $sinPunto = ['etiqueta' => 'Casa', 'direccion' => 'Av. Vallarta 1248', 'codigo_postal' => '44100'];

        $id = $this->postJson('/api/v1/cliente/direcciones', $sinPunto)->assertCreated()->assertJsonPath('zona', 'Centro')->assertJsonPath('sin_cobertura', false)->json('id');

        // Al cambiar la calle sin mandar el punto, se vuelve a ubicar (aquí cae fuera de cobertura); al cambiar solo la etiqueta no se consulta nada.
        $this->putJson("/api/v1/cliente/direcciones/{$id}", ['direccion' => 'Otra calle 5'])->assertOk()->assertJsonPath('sin_cobertura', true);
        $this->putJson("/api/v1/cliente/direcciones/{$id}", ['etiqueta' => 'Trabajo'])->assertOk()->assertJsonPath('sin_cobertura', true);
        Http::assertSentCount(2);

        $this->postJson('/api/v1/cliente/direcciones', $sinPunto)->assertStatus(422)->assertJsonPath('codigo', 'direccion_no_encontrada');
        $this->assertSame(1, Address::count(), 'la que no se encontró no se guardó con un punto inventado');
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

        $p = $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $paquete->id, 'direccion_id' => $direccion, 'platillos' => [['comida' => $menu], ['comida' => $menu]]])->assertCreated()->json();

        $this->assertSame(['pendiente_pago', '2026-11-10', '2026-11-11', 'Centro', 'GDL-20261109-0001'], [$p['estado'], $p['fecha_inicio'], $p['fecha_fin'], $p['zona'], $p['folio']]);
        $this->assertEquals(190.0, $p['total'], '(100 + 100) × 95 %');
        $this->assertSame(8 * 3600, $p['segundos_para_pagar']);
        $this->assertStringStartsWith('2026-11-09T18:00:00', $p['vence_pago_en']);
        $this->assertSame(2, $p['entregas_totales']);
        $this->assertCount(2, $p['dias']);
    }

    public function test_el_cliente_elige_cuantas_comidas_por_dia_y_el_pedido_las_devuelve_ordenadas(): void
    {
        $this->comoRol('customer');
        $direccion = $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida())->json('id');
        $paquete = Paquete::create(['nombre' => 'Un día', 'dias' => 1]);
        $desayuno = $this->platillo(null, 'desayuno', ['price' => 60]);
        $comida = $this->platillo(null, 'comida', ['price' => 100]);
        $cena = $this->platillo(null, 'cena', ['price' => 80]);
        foreach ([$desayuno, $comida, $cena] as $menu) {
            $this->disponible($menu, '2026-11-10');
        }

        $p = $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $paquete->id, 'direccion_id' => $direccion, 'platillos' => [['cena' => $cena->id, 'desayuno' => $desayuno->id, 'comida' => $comida->id]]])
            ->assertCreated()->json();

        $this->assertSame(3, $p['comidas_por_dia']);
        $this->assertEquals(240.0, $p['total']);
        $this->assertSame(1, $p['entregas_totales'], 'una entrega por día');
        $this->assertSame(['desayuno', 'comida', 'cena'], array_column($p['dias'][0]['platillos'], 'tiempo_comida'));
        $this->assertSame([60.0, 100.0, 80.0], array_map('floatval', array_column($p['dias'][0]['platillos'], 'precio_congelado')));

        $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $paquete->id, 'direccion_id' => $direccion, 'platillos' => [['comida' => $desayuno->id]]])
            ->assertStatus(422)->assertJsonPath('codigo', 'platillo_de_otro_tiempo');
        $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $paquete->id, 'direccion_id' => $direccion, 'platillos' => [[]]])->assertStatus(422);
    }

    public function test_el_cliente_puede_sumar_un_platillo_extra_por_dia(): void
    {
        $this->comoRol('customer');
        $direccion = $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida())->json('id');
        $paquete = Paquete::create(['nombre' => 'Un día', 'dias' => 1, 'descuento_porcentaje' => 10]);
        $comida = $this->platillo(null, 'comida', ['price' => 100]);
        $postre = $this->platillo(null, 'cena', ['price' => 50]);
        foreach ([$comida, $postre] as $menu) {
            $this->disponible($menu, '2026-11-10');
        }

        $p = $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $paquete->id, 'direccion_id' => $direccion, 'platillos' => [['comida' => $comida->id, 'extra' => $postre->id]]])
            ->assertCreated()->json();

        $this->assertTrue($p['con_extra']);
        $this->assertSame(1, $p['comidas_por_dia']);
        $this->assertEquals(135.0, $p['total'], '(100 + 50) × 90 %');
        $this->assertSame([false, true], array_column($p['dias'][0]['platillos'], 'es_extra'));
    }

    public function test_iniciar_el_pago_da_el_client_secret_por_el_total_en_mxn_y_no_cambia_el_pedido(): void
    {
        $this->comoRol('customer');
        $direccion = $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida())->json('id');
        $paquete = Paquete::create(['nombre' => 'Un día', 'dias' => 1]);
        $id = $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $paquete->id, 'direccion_id' => $direccion, 'platillos' => [['comida' => $this->menuDisponible()]]])->json('id');

        $pago = $this->postJson("/api/v1/cliente/pedidos/{$id}/pago")->assertOk()->assertJsonPath('monto', '100.00')->assertJsonPath('moneda', 'MXN')->json();

        $this->assertSame(['pi_prueba_1', 'pi_prueba_1_secret'], [$pago['payment_intent_id'], $pago['client_secret']]);
        $this->assertSame(10000, $this->pasarela->intentos['pi_prueba_1']['monto'], 'el total en centavos');
        $this->assertSame((string) $id, $this->pasarela->intentos['pi_prueba_1']['metadatos']['pedido_id']);
        $this->getJson("/api/v1/cliente/pedidos/{$id}")->assertJsonPath('estado', 'pendiente_pago')->assertJsonPath('ultimos_digitos', null);
        $this->assertSame('pi_prueba_1', Pedido::findOrFail($id)->stripe_payment_intent_id);

        $this->postJson("/api/v1/cliente/pedidos/{$id}/pago")->assertOk()->assertJsonPath('payment_intent_id', 'pi_prueba_1');
        $this->assertCount(1, $this->pasarela->intentos, 'repetir la llamada no crea otro intento');
        $this->postJson("/api/v1/cliente/pedidos/{$id}/pagar")->assertStatus(404); // ya no existe el pago simulado
    }

    public function test_sin_las_claves_de_stripe_los_pagos_responden_503(): void
    {
        $this->pasarela->configurada = false;
        $this->comoRol('customer');

        $this->postJson('/api/v1/cliente/pedidos/1/pago')->assertStatus(503)->assertJsonPath('codigo', 'pagos_no_configurados');
        $this->postJson('/api/v1/webhooks/stripe', [])->assertStatus(503)->assertJsonPath('codigo', 'pagos_no_configurados');
    }

    public function test_un_pedido_vencido_no_se_puede_pagar_pero_no_cambia_y_el_corte_lo_cancela(): void
    {
        $this->comoRol('customer');
        $direccion = $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida())->json('id');
        $paquete = Paquete::create(['nombre' => 'Un día', 'dias' => 1]);
        $id = $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $paquete->id, 'direccion_id' => $direccion, 'platillos' => [['comida' => $this->menuDisponible()]]])->json('id');

        $this->ahoraEs('2026-11-09 18:00:00');
        $this->postJson("/api/v1/cliente/pedidos/{$id}/pago")->assertStatus(422)->assertJsonPath('codigo', 'pedido_vencido');
        $this->getJson("/api/v1/cliente/pedidos/{$id}")->assertOk()->assertJsonPath('estado', 'pendiente_pago');
        $this->assertSame([], $this->pasarela->intentos, 'no se crea ningún intento');

        app(ContratacionDePaquetes::class)->cancelarVencidos();

        $this->getJson("/api/v1/cliente/pedidos/{$id}")->assertOk()->assertJsonPath('estado', 'cancelado')->assertJsonPath('segundos_para_pagar', null);
        $this->postJson("/api/v1/cliente/pedidos/{$id}/pago")->assertStatus(422)->assertJsonPath('codigo', 'pedido_no_pagable');
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

        $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $dos->id, 'direccion_id' => $lejos, 'platillos' => [['comida' => $menu], ['comida' => $menu]]])->assertStatus(422)->assertJsonPath('codigo', 'sin_cobertura');
        $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $dos->id, 'direccion_id' => $ajena->id, 'platillos' => [['comida' => $menu], ['comida' => $menu]]])->assertStatus(404);
        $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $dos->id, 'direccion_id' => $buena, 'platillos' => [['comida' => $menu]]])->assertStatus(422)->assertJsonPath('codigo', 'platillos_incompletos');
        $this->postJson('/api/v1/cliente/pedidos', [])->assertStatus(422)->assertJsonStructure(['errores' => ['paquete_id', 'direccion_id', 'platillos']]);
        $this->assertSame(0, Pedido::count());
    }

    public function test_el_cliente_solo_ve_sus_pedidos(): void
    {
        $cliente = $this->comoRol('customer');
        $direccion = $this->postJson('/api/v1/cliente/direcciones', $this->direccionValida())->json('id');
        $paquete = Paquete::create(['nombre' => 'Un día', 'dias' => 1]);
        $mio = $this->postJson('/api/v1/cliente/pedidos', ['paquete_id' => $paquete->id, 'direccion_id' => $direccion, 'platillos' => [['comida' => $this->menuDisponible()]]])->json('id');
        $otroCliente = $this->usuarioDeRol('customer');
        $otraDireccion = Address::create(['user_id' => $otroCliente->id, 'street_address' => 'Otra', 'latitude' => 20.67, 'longitude' => -103.35]);
        $ajeno = Pedido::create(['folio' => 'GDL-AJENO', 'cliente_id' => $otroCliente->id, 'paquete_id' => $paquete->id, 'direccion_id' => $otraDireccion->id,
            'fecha_inicio' => '2026-11-10', 'fecha_fin' => '2026-11-10', 'total' => 49, 'estado' => 'cancelado']);

        $this->getJson('/api/v1/cliente/pedidos')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $mio)->assertJsonPath('0.cliente_id', $cliente->id);
        $this->getJson("/api/v1/cliente/pedidos/{$ajeno->id}")->assertStatus(404);
        $this->postJson("/api/v1/cliente/pedidos/{$ajeno->id}/pago")->assertStatus(404);
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
