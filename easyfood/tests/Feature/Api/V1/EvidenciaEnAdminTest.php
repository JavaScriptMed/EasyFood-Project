<?php

namespace Tests\Feature\Api\V1;

use App\Models\Address;
use App\Models\Paquete;
use App\Models\Parada;
use App\Models\Pedido;
use App\Models\Repartidor;
use App\Models\Ruta;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\ContratacionDePaquetes;
use App\Services\CorteDeIndividuales;
use App\Services\EjecutorDelCorte;
use App\Services\EleccionDePlatillos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Lo que dejó el repartidor al cerrar una parada se ve desde la administración: la foto de entrega en los pedidos de clientes
 * (`evidencia_foto_url`, además de `tiene_evidencia`) y, en las paradas de GET /admin/rutas, la foto y el motivo de no entrega.
 * Todo se lee de la parada donde ya está guardado, con la misma URL que le devuelve la API del repartidor.
 */
class EvidenciaEnAdminTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private const SABADO = '2026-11-14';

    private User $usuarioRepartidor;

    private Ruta $rutaEmpresa;

    private Ruta $rutaCliente;

    private Pedido $pedido;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ahoraEs('2026-11-11 10:00');
        $this->zonaDePruebas('Centro');
        $this->armarElSabado();
        $this->ahoraEs('2026-11-14 12:00'); // sábado: salen a repartir
    }

    /** Una empresa con 2 empleados que eligieron y un cliente con un pedido; un repartidor; los cortes hechos y las rutas aprobadas. */
    private function armarElSabado(): void
    {
        $menu = $this->platillo(null, 'comida', ['name' => 'Pollo en mole']);
        foreach ([self::SABADO, '2026-11-16', '2026-11-17'] as $dia) {
            $this->disponible($menu, $dia);
        }
        $empresa = $this->empresaConContrato('Centro', ['ventana_entrega_inicio' => '12:30', 'ventana_entrega_fin' => '12:50', 'hora_entrega' => '12:40']);
        foreach (range(1, 2) as $i) {
            app(EleccionDePlatillos::class)->elegir($this->empleadoDe($empresa), self::SABADO, 'comida', $menu);
        }

        $cliente = $this->usuarioDeRol('customer', ['name' => 'Clara Cliente']);
        UserProfile::create(['user_id' => $cliente->id, 'first_name' => 'Clara', 'last_name' => 'Cliente', 'phone' => '33 1111 2222']);
        $direccion = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. Vallarta 100', 'latitude' => 20.67, 'longitude' => -103.35]);
        $servicio = app(ContratacionDePaquetes::class);
        $this->pedido = $servicio->pagar($servicio->pedir($cliente, Paquete::create(['nombre' => 'Dos días', 'dias' => 2, 'descuento_porcentaje' => 0]), $direccion, [$menu, $menu]), 'tarjeta', '4242');

        $this->usuarioRepartidor = $this->usuarioDeRol('driver', ['name' => 'Rodrigo Repartidor']);
        Repartidor::create(['usuario_id' => $this->usuarioRepartidor->id, 'numero_empleado' => 'R-1', 'placas' => 'JAL-123-A', 'zona_base' => 'Centro']);

        $this->ahoraEs('2026-11-13 18:30'); // viernes: corte de empresas y de clientes
        app(EjecutorDelCorte::class)->ejecutar(self::SABADO);
        app(CorteDeIndividuales::class)->ejecutar(self::SABADO);
        Ruta::query()->update(['estado' => 'aprobada']);

        $this->rutaEmpresa = Ruta::whereHas('paradas', fn ($p) => $p->whereNotNull('entrega_empresa_id'))->sole();
        $this->rutaCliente = Ruta::whereHas('paradas', fn ($p) => $p->whereNotNull('pedido_dia_id'))->sole();
    }

    private function paradaDeEmpresa(): Parada
    {
        return $this->rutaEmpresa->paradas()->sole();
    }

    private function paradaDeCliente(): Parada
    {
        return $this->rutaCliente->paradas()->sole();
    }

    /** El repartidor inicia la parada y la entrega con una foto; devuelve la URL que le responde SU API. */
    private function entregarComoRepartidor(Parada $parada): string
    {
        Sanctum::actingAs($this->usuarioRepartidor);
        $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/iniciar")->assertOk();
        $foto = UploadedFile::fake()->createWithContent('evidencia.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $url = $this->post("/api/v1/repartidor/paradas/{$parada->id}/entregar", ['foto' => $foto], ['Accept' => 'application/json'])->assertOk()->json('parada.evidencia_foto_url');
        $this->assertNotEmpty($url);

        return $url;
    }

    private function pedidoDelAdmin(?string $rol = 'admin'): array
    {
        $this->comoRol($rol);

        return collect($this->getJson('/api/v1/admin/pedidos')->assertOk()->json())->firstWhere('id', $this->pedido->id);
    }

    private function paradasDeLasRutas(): \Illuminate\Support\Collection
    {
        $this->comoRol('admin');

        return collect($this->getJson('/api/v1/admin/rutas?fecha='.self::SABADO)->assertOk()->json())->flatMap(fn ($ruta) => $ruta['paradas'])->keyBy('id');
    }

    // ------------------------------------------------------------------ pedidos de clientes

    public function test_un_pedido_entregado_con_foto_trae_su_evidencia_en_el_listado_del_administrador(): void
    {
        $url = $this->entregarComoRepartidor($this->paradaDeCliente());

        $pedido = $this->pedidoDelAdmin();
        $this->assertSame($url, $pedido['evidencia_foto_url'], 'la misma URL que le devolvió la API del repartidor');
        $this->assertTrue($pedido['tiene_evidencia'], 'tiene_evidencia se conserva tal cual');
        $this->assertSame($url, Parada::find($this->paradaDeCliente()->id)->evidencia_foto_url, 'se lee de la parada, no se guarda nada nuevo');

        $this->assertSame($url, $this->pedidoDelAdmin('support')['evidencia_foto_url'], 'soporte también la ve');
    }

    public function test_un_pedido_sin_evidencia_trae_la_llave_en_null(): void
    {
        $pedido = $this->pedidoDelAdmin();

        $this->assertArrayHasKey('evidencia_foto_url', $pedido);
        $this->assertNull($pedido['evidencia_foto_url']);
        $this->assertFalse($pedido['tiene_evidencia']);
    }

    public function test_la_evidencia_no_aparece_en_la_api_del_cliente(): void
    {
        $this->entregarComoRepartidor($this->paradaDeCliente());

        Sanctum::actingAs($this->pedido->cliente);
        $propio = collect($this->getJson('/api/v1/cliente/pedidos')->assertOk()->json())->firstWhere('id', $this->pedido->id);
        $this->assertArrayNotHasKey('evidencia_foto_url', $propio, 'la API de la app de clientes conserva sus llaves');
        $this->assertTrue($propio['tiene_evidencia']);
    }

    // ------------------------------------------------------------------ paradas de las rutas

    public function test_una_parada_de_empresa_entregada_con_foto_trae_su_evidencia_en_las_rutas_del_administrador(): void
    {
        $parada = $this->paradaDeEmpresa();
        $url = $this->entregarComoRepartidor($parada);

        $enRuta = $this->paradasDeLasRutas()[$parada->id];
        $this->assertSame('entregado', $enRuta['estado']);
        $this->assertNotNull($enRuta['entrega_id'], 'es una entrega a una empresa');
        $this->assertSame($url, $enRuta['evidencia_foto_url']);
        $this->assertNull($enRuta['motivo_no_entrega']);
    }

    public function test_una_parada_no_localizada_trae_su_motivo_y_ninguna_foto(): void
    {
        $parada = $this->paradaDeCliente();
        Sanctum::actingAs($this->usuarioRepartidor);
        $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/iniciar")->assertOk();
        $this->postJson("/api/v1/repartidor/paradas/{$parada->id}/no-entregada", ['motivo' => 'Nadie abrió el portón'])->assertOk();

        $enRuta = $this->paradasDeLasRutas()[$parada->id];
        $this->assertSame('no_localizado', $enRuta['estado']);
        $this->assertSame('Nadie abrió el portón', $enRuta['motivo_no_entrega']);
        $this->assertNull($enRuta['evidencia_foto_url']);
        $this->assertNull($this->pedidoDelAdmin()['evidencia_foto_url'], 'sin entrega no hay foto en el pedido');
    }

    public function test_las_paradas_pendientes_traen_las_dos_llaves_en_null(): void
    {
        foreach ($this->paradasDeLasRutas() as $parada) {
            $this->assertSame('pendiente', $parada['estado']);
            $this->assertArrayHasKey('evidencia_foto_url', $parada);
            $this->assertArrayHasKey('motivo_no_entrega', $parada);
            $this->assertNull($parada['evidencia_foto_url']);
            $this->assertNull($parada['motivo_no_entrega']);
        }
    }

    public function test_la_api_del_repartidor_conserva_sus_llaves(): void
    {
        $this->entregarComoRepartidor($this->paradaDeEmpresa());
        Sanctum::actingAs($this->usuarioRepartidor);

        $parada = collect($this->getJson('/api/v1/repartidor/rutas?fecha='.self::SABADO)->assertOk()->json())->flatMap(fn ($r) => $r['paradas'])->firstWhere('estado', 'entregado');
        foreach (['id', 'orden', 'estado', 'tipo', 'destinatario', 'direccion', 'ubicacion', 'hora_estimada', 'platillos', 'total_platillos', 'iniciada_en', 'cerrada_en', 'evidencia_foto_url', 'motivo_no_entrega', 'referencias', 'contacto_nombre', 'telefono', 'folio'] as $llave) {
            $this->assertArrayHasKey($llave, $parada);
        }
    }
}
