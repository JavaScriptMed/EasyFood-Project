<?php

namespace Tests\Feature\Comandos;

use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ActuaComoUsuario;
use Tests\TestCase;

/** Los dos comandos para dejar un servidor nuevo listo: el primer administrador y las zonas de entrega. */
class PrepararServidorTest extends TestCase
{
    use ActuaComoUsuario;
    use RefreshDatabase;

    private string $geojson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geojson = sys_get_temp_dir().'/zona-'.bin2hex(random_bytes(4)).'.geojson';
    }

    protected function tearDown(): void
    {
        @unlink($this->geojson);
        parent::tearDown();
    }

    private function poligono(float $lon, float $lat, float $mitad = 0.05): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[[$lon - $mitad, $lat - $mitad], [$lon + $mitad, $lat - $mitad], [$lon + $mitad, $lat + $mitad], [$lon - $mitad, $lat + $mitad], [$lon - $mitad, $lat - $mitad]]]];
    }

    private function archivo(mixed $contenido): string
    {
        file_put_contents($this->geojson, is_string($contenido) ? $contenido : json_encode($contenido));

        return $this->geojson;
    }

    // ------------------------------------------------------------------ primer administrador

    public function test_crea_el_administrador_pidiendo_la_contrasena_y_puede_iniciar_sesion(): void
    {
        $this->usuarioDeRol('admin'); // deja los roles creados

        $this->artisan('easylunch:crear-admin', ['correo' => 'Dueña@Empresa.mx', '--nombre' => 'Ana Dueña'])
            ->expectsQuestion('Contraseña (mínimo 8 caracteres, con letras y números)', 'Clave1234')
            ->expectsQuestion('Repite la contraseña', 'Clave1234')
            ->expectsOutputToContain('dueña@empresa.mx')->assertSuccessful();

        $admin = User::where('email', 'dueña@empresa.mx')->firstOrFail();
        $this->assertSame(['admin', 'active', 'Ana Dueña'], [$admin->role->name, $admin->status, $admin->name]);
        $this->assertTrue(Hash::check('Clave1234', $admin->password));
        $this->assertNotNull($admin->profile);
        $this->postJson('/api/auth/login', ['email' => 'dueña@empresa.mx', 'password' => 'Clave1234'])->assertOk();
    }

    public function test_rechaza_contrasenas_debiles_distintas_o_un_correo_ya_usado_y_no_crea_nada(): void
    {
        $existente = $this->usuarioDeRol('admin', ['email' => 'ya@empresa.mx']);
        $total = User::count();

        $this->artisan('easylunch:crear-admin', ['correo' => 'nuevo@empresa.mx'])
            ->expectsQuestion('Contraseña (mínimo 8 caracteres, con letras y números)', 'corta')
            ->expectsOutputToContain('al menos 8 caracteres')->assertFailed();
        $this->artisan('easylunch:crear-admin', ['correo' => 'nuevo@empresa.mx'])
            ->expectsQuestion('Contraseña (mínimo 8 caracteres, con letras y números)', 'SoloLetrasAqui')
            ->expectsOutputToContain('letras y números')->assertFailed();
        $this->artisan('easylunch:crear-admin', ['correo' => 'nuevo@empresa.mx'])
            ->expectsQuestion('Contraseña (mínimo 8 caracteres, con letras y números)', 'Clave1234')
            ->expectsQuestion('Repite la contraseña', 'Clave12345')
            ->expectsOutputToContain('no coinciden')->assertFailed();
        $this->artisan('easylunch:crear-admin', ['correo' => 'YA@empresa.mx'])->expectsOutputToContain('Ya existe una cuenta')->assertFailed();
        $this->artisan('easylunch:crear-admin', ['correo' => 'no-es-correo'])->expectsOutputToContain('no es válido')->assertFailed();

        $this->assertSame($total, User::count());
        $this->assertTrue($existente->fresh()->exists);
    }

    public function test_sin_roles_explica_que_hay_que_cargarlos_primero(): void
    {
        $this->artisan('easylunch:crear-admin', ['correo' => 'a@empresa.mx'])->expectsOutputToContain('InitialDataSeeder')->assertFailed();
    }

    // ------------------------------------------------------------------ zonas

    public function test_carga_una_zona_desde_geojson_y_las_direcciones_nuevas_caen_en_ella(): void
    {
        $this->artisan('easylunch:cargar-zona', ['nombre' => 'Zapopan', 'archivo' => $this->archivo($this->poligono(-103.55, 20.72))])->expectsOutputToContain('Zona creada')->assertSuccessful();

        $this->assertSame(1, DB::table('zonas')->count());
        $cliente = $this->usuarioDeRol('customer');
        $dentro = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. 1', 'latitude' => 20.72, 'longitude' => -103.55]);
        $fuera = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. 2', 'latitude' => 25.0, 'longitude' => -100.0]);
        $this->assertSame(['Zapopan', null], [$dentro->fresh()->zona, $fuera->fresh()->zona]);
    }

    public function test_acepta_feature_collection_y_multipoligono_y_reemplaza_la_zona_existente_recalculando_direcciones(): void
    {
        $this->artisan('easylunch:cargar-zona', ['nombre' => 'Centro', 'archivo' => $this->archivo(['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => [], 'geometry' => $this->poligono(-103.35, 20.67)]]])])->assertSuccessful();
        $cliente = $this->usuarioDeRol('customer');
        $direccion = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. 1', 'latitude' => 20.67, 'longitude' => -103.35]);
        $this->assertSame('Centro', $direccion->fresh()->zona);

        // Se mueve la zona a otra parte (como MultiPolygon): la dirección queda sin cobertura.
        $multi = ['type' => 'MultiPolygon', 'coordinates' => [$this->poligono(-100.0, 25.0)['coordinates']]];
        $this->artisan('easylunch:cargar-zona', ['nombre' => 'CENTRO', 'archivo' => $this->archivo($multi)])->expectsOutputToContain('Zona actualizada')->assertSuccessful();

        $this->assertSame(1, DB::table('zonas')->count(), 'el nombre se compara sin importar mayúsculas: no duplica');
        $this->assertNull($direccion->fresh()->zona);
    }

    public function test_rechaza_archivos_que_no_sirven_y_no_cambia_nada(): void
    {
        $this->artisan('easylunch:cargar-zona', ['nombre' => 'Centro', 'archivo' => '/no/existe.geojson'])->expectsOutputToContain('No puedo leer')->assertFailed();
        $this->artisan('easylunch:cargar-zona', ['nombre' => 'Centro', 'archivo' => $this->archivo('no es json')])->expectsOutputToContain('no trae un polígono')->assertFailed();
        $this->artisan('easylunch:cargar-zona', ['nombre' => 'Centro', 'archivo' => $this->archivo(['type' => 'Point', 'coordinates' => [-103.3, 20.6]])])->expectsOutputToContain('no trae un polígono')->assertFailed();
        // Latitud y longitud al revés.
        $this->artisan('easylunch:cargar-zona', ['nombre' => 'Centro', 'archivo' => $this->archivo($this->poligono(20.67, -103.35))])->expectsOutputToContain('[longitud, latitud]')->assertFailed();
        // Anillo sin cerrar.
        $abierto = $this->poligono(-103.35, 20.67);
        array_pop($abierto['coordinates'][0]);
        $this->artisan('easylunch:cargar-zona', ['nombre' => 'Centro', 'archivo' => $this->archivo($abierto)])->expectsOutputToContain('anillos deben cerrar')->assertFailed();
        // Polígono que se cruza a sí mismo (corbata).
        $corbata = ['type' => 'Polygon', 'coordinates' => [[[-103.4, 20.6], [-103.3, 20.7], [-103.3, 20.6], [-103.4, 20.7], [-103.4, 20.6]]]];
        $this->artisan('easylunch:cargar-zona', ['nombre' => 'Centro', 'archivo' => $this->archivo($corbata)])->expectsOutputToContain('no es válido')->assertFailed();

        $this->assertSame(0, DB::table('zonas')->count());
    }
}
