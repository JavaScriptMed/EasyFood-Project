<?php

namespace Tests\Feature\Api\V1;

use App\Models\Menu;
use App\Models\PrecioDePlatillo;
use App\Models\RegistroAuditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** Fotos y precios programados de los platillos. «Hoy» es el lunes 9 de nov de 2026 salvo que la prueba avance el reloj. */
class PreciosEImagenesTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->ahoraEs('2026-11-09 10:00');
    }

    /** Una foto real mínima (el servidor de pruebas no trae GD para fabricarlas): PNG o JPEG de 1 × 1 píxel. */
    private function foto(string $nombre = 'plato.png', string $tipo = 'png'): UploadedFile
    {
        $bytes = $tipo === 'png'
            ? 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
            : '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

        return UploadedFile::fake()->createWithContent($nombre, base64_decode($bytes));
    }

    // ------------------------------------------------------------------ imágenes

    public function test_el_administrador_sube_la_foto_de_un_platillo_y_la_anterior_se_borra(): void
    {
        $this->comoRol('admin');
        $platillo = $this->platillo();

        $primera = $this->postJson("/api/v1/admin/platillos/{$platillo->id}/imagen", ['imagen' => $this->foto()])->assertOk()->json('foto_url');
        $this->assertStringContainsString("/storage/platillos/{$platillo->id}-", $primera);
        $this->assertStringEndsWith('.png', $primera);
        $archivo = 'platillos/'.basename($primera);
        Storage::disk('public')->assertExists($archivo);

        $segunda = $this->postJson("/api/v1/admin/platillos/{$platillo->id}/imagen", ['imagen' => $this->foto('otra.jpg', 'jpg')])->assertOk()->json('foto_url');
        $this->assertNotSame($primera, $segunda);
        $this->assertStringEndsWith('.jpg', $segunda);
        Storage::disk('public')->assertMissing($archivo);
        Storage::disk('public')->assertExists('platillos/'.basename($segunda));
        $this->assertSame($segunda, $platillo->fresh()->image_url);
        $this->assertSame('platillo.imagen', RegistroAuditoria::latest('id')->first()->accion);
    }

    public function test_una_foto_externa_no_se_borra_y_el_nombre_del_archivo_no_lo_elige_quien_sube(): void
    {
        $this->comoRol('admin');
        $platillo = $this->platillo(null, 'comida', ['image_url' => 'https://cdn.ejemplo.com/pollo.jpg']);

        $url = $this->postJson("/api/v1/admin/platillos/{$platillo->id}/imagen", ['imagen' => $this->foto('../../etc/passwd.jpg', 'jpg')])->assertOk()->json('foto_url');

        $this->assertStringNotContainsString('passwd', $url);
        $this->assertCount(1, Storage::disk('public')->files('platillos'));
    }

    public function test_rechaza_archivos_que_no_son_fotos_o_pesan_demasiado(): void
    {
        $this->comoRol('admin');
        $platillo = $this->platillo();
        $url = "/api/v1/admin/platillos/{$platillo->id}/imagen";

        $this->postJson($url, [])->assertStatus(422)->assertJsonStructure(['errores' => ['imagen']]);
        $this->postJson($url, ['imagen' => UploadedFile::fake()->create('menu.pdf', 100, 'application/pdf')])->assertStatus(422)->assertJsonStructure(['errores' => ['imagen']]);
        $this->postJson($url, ['imagen' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertStatus(422);
        $this->postJson($url, ['imagen' => UploadedFile::fake()->create('grande.jpg', 3000, 'image/jpeg')])->assertStatus(422)->assertJsonStructure(['errores' => ['imagen']]);

        $this->assertSame([], Storage::disk('public')->files('platillos'));
        $this->assertNull($platillo->fresh()->image_url);
    }

    public function test_la_foto_de_un_platillo_que_no_existe_es_404_y_solo_el_administrador_la_cambia(): void
    {
        $platillo = $this->platillo();
        $this->comoRol('admin');
        $this->postJson('/api/v1/admin/platillos/999999/imagen', ['imagen' => $this->foto()])->assertStatus(404);

        foreach (['support', 'company', 'customer'] as $rol) {
            $this->comoRol($rol);
            $this->postJson("/api/v1/admin/platillos/{$platillo->id}/imagen", ['imagen' => $this->foto()])->assertStatus(403);
        }
    }

    public function test_el_comedor_cambia_la_foto_de_sus_platillos_y_vuelven_a_revision(): void
    {
        $comedor = $this->comoComedor();
        $propio = $this->platillo($comedor);
        $ajeno = $this->platillo();

        $this->postJson("/api/v1/comedor/platillos/{$propio->id}/imagen", ['imagen' => $this->foto()])->assertOk()->assertJsonPath('estado_revision', 'en_revision');
        $this->assertNotNull($propio->fresh()->image_url);

        $this->postJson("/api/v1/comedor/platillos/{$ajeno->id}/imagen", ['imagen' => $this->foto()])->assertStatus(404);
        $this->assertNull($ajeno->fresh()->image_url);
        $this->postJson("/api/v1/comedor/platillos/{$propio->id}/imagen", [])->assertStatus(422);
    }

    // ------------------------------------------------------------------ historial de precios

    public function test_un_platillo_sin_historial_arranca_con_su_precio_de_hoy_y_el_costo_de_su_contrato(): void
    {
        $this->comoRol('admin');
        $platillo = $this->platillo($this->comedorConContrato(200, 41.5));

        $filas = $this->getJson("/api/v1/admin/platillos/{$platillo->id}/historial-precios")->assertOk()->assertJsonCount(1)->json();

        $this->assertSame([49, 41.5, '2026-11-09', null], [$filas[0]['precio_venta'], $filas[0]['costo'], $filas[0]['vigente_desde'], $filas[0]['vigente_hasta']]);
        $this->assertSame($platillo->id, $filas[0]['platillo_id']);
    }

    public function test_programar_un_precio_cierra_el_tramo_de_hoy_el_dia_anterior(): void
    {
        $admin = $this->comoRol('admin');
        $platillo = $this->platillo();

        $this->postJson("/api/v1/admin/platillos/{$platillo->id}/precios", ['precio_venta' => 55, 'vigente_desde' => '2026-11-16'])->assertCreated()
            ->assertJsonPath('precio_venta', 55)->assertJsonPath('vigente_desde', '2026-11-16')->assertJsonPath('vigente_hasta', null);

        $filas = $this->getJson("/api/v1/admin/platillos/{$platillo->id}/historial-precios")->assertOk()->json();
        $this->assertSame([['2026-11-16', null, 55], ['2026-11-09', '2026-11-15', 49]], array_map(fn ($f) => [$f['vigente_desde'], $f['vigente_hasta'], $f['precio_venta']], $filas));
        $this->assertEquals(49, $platillo->fresh()->price, 'hasta que llegue la fecha, el precio de hoy no cambia');
        $this->assertSame($admin->id, RegistroAuditoria::where('accion', 'platillo.precio_programado')->sole()->usuario_id);
    }

    public function test_programar_otro_precio_reemplaza_al_pendiente_y_no_apila_tramos(): void
    {
        $this->comoRol('admin');
        $platillo = $this->platillo();
        $url = "/api/v1/admin/platillos/{$platillo->id}/precios";

        $this->postJson($url, ['precio_venta' => 55, 'vigente_desde' => '2026-11-16'])->assertCreated();
        $this->postJson($url, ['precio_venta' => 60, 'vigente_desde' => '2026-11-20'])->assertCreated();

        $filas = $this->getJson("/api/v1/admin/platillos/{$platillo->id}/historial-precios")->json();
        $this->assertSame([['2026-11-20', null, 60], ['2026-11-09', '2026-11-19', 49]], array_map(fn ($f) => [$f['vigente_desde'], $f['vigente_hasta'], $f['precio_venta']], $filas));
    }

    public function test_no_programa_un_precio_para_hoy_el_pasado_igual_al_vigente_o_en_cero(): void
    {
        $this->comoRol('admin');
        $platillo = $this->platillo();
        $url = "/api/v1/admin/platillos/{$platillo->id}/precios";

        $this->postJson($url, ['precio_venta' => 55, 'vigente_desde' => '2026-11-09'])->assertStatus(422)->assertJsonPath('codigo', 'fecha_de_precio_invalida');
        $this->postJson($url, ['precio_venta' => 55, 'vigente_desde' => '2026-11-01'])->assertStatus(422)->assertJsonPath('codigo', 'fecha_de_precio_invalida');
        $this->postJson($url, ['precio_venta' => 49, 'vigente_desde' => '2026-11-16'])->assertStatus(422)->assertJsonPath('codigo', 'precio_sin_cambio');
        $this->postJson($url, ['precio_venta' => 0, 'vigente_desde' => '2026-11-16'])->assertStatus(422)->assertJsonStructure(['errores' => ['precio_venta']]);
        $this->postJson($url, ['precio_venta' => 55])->assertStatus(422)->assertJsonStructure(['errores' => ['vigente_desde']]);
        $this->postJson('/api/v1/admin/platillos/999999/precios', ['precio_venta' => 55, 'vigente_desde' => '2026-11-16'])->assertStatus(404);

        $this->assertSame(0, PrecioDePlatillo::where('vigente_desde', '>', '2026-11-09')->count(), 'no quedó ningún precio programado');
    }

    public function test_el_comando_diario_pone_el_precio_programado_el_dia_que_toca(): void
    {
        $this->comoRol('admin');
        $platillo = $this->platillo();
        $otro = $this->platillo();
        $this->postJson("/api/v1/admin/platillos/{$platillo->id}/precios", ['precio_venta' => 55, 'vigente_desde' => '2026-11-16'])->assertCreated();

        $this->ahoraEs('2026-11-15 23:00');
        Artisan::call('easylunch:aplicar-precios-programados');
        $this->assertEquals(49, $platillo->fresh()->price, 'todavía no es la fecha');

        $this->ahoraEs('2026-11-16 00:05');
        $this->assertSame(0, Artisan::call('easylunch:aplicar-precios-programados'));
        $this->assertEquals(55, $platillo->fresh()->price);
        $this->assertEquals(49, $otro->fresh()->price);
        $this->assertSame('platillo.precios_aplicados', RegistroAuditoria::latest('id')->first()->accion);

        Artisan::call('easylunch:aplicar-precios-programados'); // volver a correrlo no cambia nada
        $this->assertSame(1, RegistroAuditoria::where('accion', 'platillo.precios_aplicados')->count());
    }

    public function test_editar_el_precio_de_un_platillo_deja_su_tramo_en_el_historial(): void
    {
        $this->comoRol('admin');
        $platillo = $this->platillo();
        $this->ahoraEs('2026-11-10 10:00');

        $this->putJson("/api/v1/admin/platillos/{$platillo->id}", ['precio_venta' => 52])->assertOk()->assertJsonPath('precio_venta', 52);
        $this->putJson("/api/v1/admin/platillos/{$platillo->id}", ['precio_venta' => 53])->assertOk(); // el mismo día: no abre otro tramo

        $filas = $this->getJson("/api/v1/admin/platillos/{$platillo->id}/historial-precios")->json();
        $this->assertSame([['2026-11-10', null, 53], ['2026-11-09', '2026-11-09', 49]], array_map(fn ($f) => [$f['vigente_desde'], $f['vigente_hasta'], $f['precio_venta']], $filas));
    }

    public function test_editar_sin_cambiar_el_precio_no_agrega_tramos_y_un_platillo_nuevo_nace_con_su_tramo(): void
    {
        $this->comoRol('admin');
        $comedor = $this->comedorNuevo();

        $creado = $this->postJson('/api/v1/admin/platillos', ['comedor_id' => $comedor->id, 'nombre' => 'Sopa nueva', 'tiempo_comida' => 'comida', 'precio_venta' => 70])->assertCreated()->json('id');
        $this->assertSame(1, PrecioDePlatillo::where('menu_id', $creado)->count());

        $this->putJson("/api/v1/admin/platillos/{$creado}", ['nombre' => 'Sopa renombrada'])->assertOk();
        $this->assertSame(1, PrecioDePlatillo::where('menu_id', $creado)->count());
    }

    public function test_cuando_el_comedor_cambia_el_precio_de_su_platillo_tambien_queda_en_el_historial(): void
    {
        $comedor = $this->comoComedor();
        $platillo = $this->platillo($comedor);
        $this->ahoraEs('2026-11-10 10:00');

        $this->putJson("/api/v1/comedor/platillos/{$platillo->id}", ['precio_venta' => 60])->assertOk();

        $this->assertSame(2, PrecioDePlatillo::where('menu_id', $platillo->id)->count());
        $this->assertEquals(60, PrecioDePlatillo::where('menu_id', $platillo->id)->orderByDesc('vigente_desde')->first()->precio_venta);
    }

    public function test_el_historial_y_programar_precios_son_solo_del_administrador(): void
    {
        $platillo = $this->platillo();
        foreach (['support', 'company', 'provider', 'customer'] as $rol) {
            $this->comoRol($rol);
            $this->getJson("/api/v1/admin/platillos/{$platillo->id}/historial-precios")->assertStatus(403);
            $this->postJson("/api/v1/admin/platillos/{$platillo->id}/precios", ['precio_venta' => 55, 'vigente_desde' => '2026-11-16'])->assertStatus(403);
        }
    }

    public function test_la_base_impide_dos_tramos_abiertos_o_un_precio_en_cero(): void
    {
        $platillo = $this->platillo();
        PrecioDePlatillo::create(['menu_id' => $platillo->id, 'precio_venta' => 49, 'vigente_desde' => '2026-11-01']);

        $this->rechaza('precios_de_platillos_un_tramo_abierto', fn () => PrecioDePlatillo::create(['menu_id' => $platillo->id, 'precio_venta' => 50, 'vigente_desde' => '2026-11-05']));
        $this->rechaza('precio_positivo', fn () => PrecioDePlatillo::create(['menu_id' => $platillo->id, 'precio_venta' => 0, 'vigente_desde' => '2026-10-01', 'vigente_hasta' => '2026-10-31']));
        $this->rechaza('tramo_valido', fn () => PrecioDePlatillo::create(['menu_id' => $platillo->id, 'precio_venta' => 40, 'vigente_desde' => '2026-10-10', 'vigente_hasta' => '2026-10-01']));
    }
}
