<?php

namespace Tests\Feature\Api\V1;

use App\Models\Empresa;
use App\Models\Repartidor;
use App\Models\Ruta;
use App\Services\EjecutorDelCorte;
use App\Services\EleccionDePlatillos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Coordenadas opcionales de empresas (PostGIS ST_Covers) y exposición de `ubicacion` en paradas
 * tanto en GET /api/v1/admin/rutas como en GET /api/v1/repartidor/rutas sin modificar GeneradorDeRutas.
 */
class CoordenadasDeEmpresasTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->ahoraEs('2026-11-09 10:00');
        $this->zonaDePruebas('Centro', -103.35, 20.67, 0.05);
        $this->zonaDePruebas('Zapopan', -103.55, 20.72, 0.05);
    }

    private function payloadEmpresa(array $extra = []): array
    {
        return $extra + [
            'nombre' => 'Tecnología Tapatía',
            'contacto_nombre' => 'Elena Morales',
            'contacto_correo' => 'elena@tapatia.mx',
            'direccion_entrega' => 'Av. Patria 500, Zapopan',
            'vigencia_inicio' => '2026-11-10',
            'compra_minima_mensual' => 120,
            'porcentaje_subsidio' => 50,
            'ventana_entrega_inicio' => '12:30',
            'ventana_entrega_fin' => '13:15',
        ];
    }

    private function payloadEdicion(array $extra = []): array
    {
        return $extra + [
            'nombre' => 'Empresa Editada',
            'direccion_entrega' => 'Av. Vallarta 2500',
            'contacto_nombre' => 'Rosa Prado',
            'contacto_telefono' => '33 5555 0000',
            'porcentaje_subsidio' => 45,
            'compra_minima_mensual' => 200,
            'ventana_entrega_inicio' => '12:00',
            'ventana_entrega_fin' => '12:45',
        ];
    }

    public function test_crear_empresa_con_punto_dentro_de_zona_calcula_zona_con_postgis(): void
    {
        $this->comoRol('admin');

        $r = $this->postJson('/api/v1/admin/empresas', $this->payloadEmpresa([
            'latitud' => 20.72,
            'longitud' => -103.55,
            'zona' => 'Centro', // aunque se mande otra zona, manda PostGIS
        ]))->assertCreated()->json();

        $this->assertSame('Zapopan', $r['zona']);
        $this->assertEqualsWithDelta(20.72, $r['latitud'], 0.000001);
        $this->assertEqualsWithDelta(-103.55, $r['longitud'], 0.000001);

        // También funciona sin mandar `zona` en el payload
        $r2 = $this->postJson('/api/v1/admin/empresas', $this->payloadEmpresa([
            'nombre' => 'Sin Zona Textual',
            'contacto_correo' => 'rrhh@sinzona.mx',
            'latitud' => 20.67,
            'longitud' => -103.35,
        ]))->assertCreated()->json();

        $this->assertSame('Centro', $r2['zona']);
        $this->assertEqualsWithDelta(20.67, $r2['latitud'], 0.000001);
        $this->assertEqualsWithDelta(-103.35, $r2['longitud'], 0.000001);
    }

    public function test_crear_y_editar_empresa_con_punto_fuera_de_cobertura_responde_422(): void
    {
        $this->comoRol('admin');

        $this->postJson('/api/v1/admin/empresas', $this->payloadEmpresa([
            'latitud' => 19.4326,
            'longitud' => -99.1332,
        ]))->assertStatus(422)->assertJsonPath('codigo', 'fuera_de_cobertura')
            ->assertJsonPath('mensaje', 'El punto de entrega está fuera de las zonas de cobertura activas.');
        $this->assertSame(0, Empresa::count());

        $empresa = $this->empresaConContrato('Centro');
        $this->putJson("/api/v1/admin/empresas/{$empresa->id}", $this->payloadEdicion([
            'latitud' => 19.4326,
            'longitud' => -99.1332,
        ]))->assertStatus(422)->assertJsonPath('codigo', 'fuera_de_cobertura');

        $empresa->refresh();
        $this->assertSame('Centro', $empresa->zona);
        $this->assertNull($empresa->latitud);
        $this->assertNull($empresa->longitud);
    }

    public function test_en_zonas_traslapadas_gana_la_mas_pequena(): void
    {
        $this->comoRol('admin');
        // Zona pequeña completamente dentro de Centro (-103.35, 20.67, mitad 0.05)
        $this->zonaDePruebas('Centro Histórico', -103.35, 20.67, 0.01);

        $r = $this->postJson('/api/v1/admin/empresas', $this->payloadEmpresa([
            'latitud' => 20.67,
            'longitud' => -103.35,
        ]))->assertCreated()->json();

        $this->assertSame('Centro Histórico', $r['zona']);

        // Un punto en Centro pero fuera de Centro Histórico cae en Centro
        $r2 = $this->postJson('/api/v1/admin/empresas', $this->payloadEmpresa([
            'nombre' => 'Borde Centro',
            'contacto_correo' => 'borde@centro.mx',
            'latitud' => 20.70,
            'longitud' => -103.35,
        ]))->assertCreated()->json();

        $this->assertSame('Centro', $r2['zona']);
    }

    public function test_un_punto_sobre_el_borde_de_la_zona_cuenta_como_cubierto_st_covers_y_no_st_contains(): void
    {
        $this->comoRol('admin');
        // La zona Centro es el cuadro de ±0.05° alrededor de (-103.35, 20.67): su esquina noreste (-103.30, 20.72) es un vértice, o sea, borde.
        $r = $this->postJson('/api/v1/admin/empresas', $this->payloadEmpresa(['latitud' => 20.72, 'longitud' => -103.30]))->assertCreated()->json();

        $this->assertSame('Centro', $r['zona'], 'el borde está cubierto');
        $this->assertSame(1, (int) DB::selectOne("select count(*) as n from zonas where nombre = 'Centro' and ST_Covers(area, ST_SetSRID(ST_MakePoint(-103.30, 20.72), 4326)::geography)")->n);
        $this->assertSame(0, (int) DB::selectOne("select count(*) as n from zonas where nombre = 'Centro' and ST_Contains(area::geometry, ST_SetSRID(ST_MakePoint(-103.30, 20.72), 4326))")->n, 'ST_Contains lo dejaría fuera: por eso se usa ST_Covers');
    }

    public function test_una_zona_desactivada_ya_no_cubre_el_punto(): void
    {
        $this->comoRol('admin');
        DB::table('zonas')->where('nombre', 'Zapopan')->update(['activo' => false, 'desactivado_en' => now()]);

        $this->postJson('/api/v1/admin/empresas', $this->payloadEmpresa(['latitud' => 20.72, 'longitud' => -103.55]))
            ->assertStatus(422)->assertJsonPath('codigo', 'fuera_de_cobertura');
        $this->assertSame(0, Empresa::count());
    }

    public function test_los_valores_limite_de_latitud_y_longitud_son_validos_y_solo_fallan_por_cobertura(): void
    {
        $this->comoRol('admin');

        foreach ([[90, 180], [-90, -180], [90, -180], [-90, 180]] as [$latitud, $longitud]) {
            $this->postJson('/api/v1/admin/empresas', $this->payloadEmpresa(['latitud' => $latitud, 'longitud' => $longitud]))
                ->assertStatus(422)->assertJsonPath('codigo', 'fuera_de_cobertura'); // pasan la validación de rango: lo único que falla es la cobertura
        }
    }

    public function test_rechaza_latitud_o_longitud_fuera_de_rango_o_incompletas(): void
    {
        $this->comoRol('admin');

        $this->postJson('/api/v1/admin/empresas', $this->payloadEmpresa(['latitud' => 95, 'longitud' => -103.35]))
            ->assertStatus(422)->assertJsonStructure(['errores' => ['latitud']]);
        $this->postJson('/api/v1/admin/empresas', $this->payloadEmpresa(['latitud' => -91, 'longitud' => -103.35]))
            ->assertStatus(422)->assertJsonStructure(['errores' => ['latitud']]);
        $this->postJson('/api/v1/admin/empresas', $this->payloadEmpresa(['latitud' => 20.67, 'longitud' => -185]))
            ->assertStatus(422)->assertJsonStructure(['errores' => ['longitud']]);
        $this->postJson('/api/v1/admin/empresas', $this->payloadEmpresa(['latitud' => 20.67, 'longitud' => 181]))
            ->assertStatus(422)->assertJsonStructure(['errores' => ['longitud']]);
        $this->postJson('/api/v1/admin/empresas', $this->payloadEmpresa(['latitud' => 20.67]))
            ->assertStatus(422)->assertJsonStructure(['errores' => ['longitud']]);
        $this->postJson('/api/v1/admin/empresas', $this->payloadEmpresa(['longitud' => -103.35]))
            ->assertStatus(422)->assertJsonStructure(['errores' => ['latitud']]);

        $empresa = $this->empresaConContrato('Centro');
        $this->putJson("/api/v1/admin/empresas/{$empresa->id}", $this->payloadEdicion(['latitud' => 99, 'longitud' => -103.35]))
            ->assertStatus(422)->assertJsonStructure(['errores' => ['latitud']]);
        $this->putJson("/api/v1/admin/empresas/{$empresa->id}", $this->payloadEdicion(['latitud' => 20.67]))
            ->assertStatus(422)->assertJsonStructure(['errores' => ['longitud']]);
    }

    public function test_empresa_existente_sin_punto_sigue_funcionando_y_editar_con_punto_recalcula_zona(): void
    {
        $this->comoRol('admin');
        $empresa = $this->empresaConContrato('Centro');
        $this->assertNull($empresa->latitud);
        $this->assertNull($empresa->longitud);

        // Editar sin coordenadas conserva su zona y deja coordenadas en null
        $r = $this->putJson("/api/v1/admin/empresas/{$empresa->id}", $this->payloadEdicion())->assertOk()->json();
        $this->assertSame('Centro', $r['zona']);
        $this->assertNull($r['latitud']);
        $this->assertNull($r['longitud']);

        // Editar con punto en Zapopan actualiza punto y recalcula zona
        $r2 = $this->putJson("/api/v1/admin/empresas/{$empresa->id}", $this->payloadEdicion([
            'latitud' => 20.72,
            'longitud' => -103.55,
        ]))->assertOk()->json();
        $this->assertSame('Zapopan', $r2['zona']);
        $this->assertEqualsWithDelta(20.72, $r2['latitud'], 0.000001);
        $this->assertEqualsWithDelta(-103.55, $r2['longitud'], 0.000001);
    }

    public function test_editar_poligono_de_zona_no_mueve_empresas_por_su_cuenta(): void
    {
        $this->comoRol('admin');
        $r = $this->postJson('/api/v1/admin/empresas', $this->payloadEmpresa([
            'latitud' => 20.72,
            'longitud' => -103.55,
        ]))->assertCreated()->json();
        $this->assertSame('Zapopan', $r['zona']);

        $zapopanId = DB::table('zonas')->where('nombre', 'Zapopan')->value('id');
        // Movemos el polígono de Zapopan lejos del punto de la empresa
        $poligonoLejos = [
            'type' => 'Polygon',
            'coordinates' => [[[-100.1, 25.0], [-100.0, 25.0], [-100.0, 25.1], [-100.1, 25.1], [-100.1, 25.0]]],
        ];
        $this->putJson("/api/v1/admin/zonas/{$zapopanId}", ['geojson' => $poligonoLejos])->assertOk();

        $empresa = Empresa::findOrFail($r['id']);
        $this->assertSame('Zapopan', $empresa->zona, 'un cambio de polígono no mueve empresas por su cuenta');
        $this->assertEqualsWithDelta(20.72, $empresa->latitud, 0.000001);
        $this->assertEqualsWithDelta(-103.55, $empresa->longitud, 0.000001);
    }

    public function test_ubicacion_de_parada_de_empresa_con_y_sin_punto_en_admin_y_repartidor(): void
    {
        $fecha = '2026-11-10';
        $menu = $this->platillo(null, 'comida');
        $this->disponible($menu, $fecha);

        $conPunto = $this->empresaConContrato('Centro', ['ventana_entrega_inicio' => '12:00', 'ventana_entrega_fin' => '12:30', 'hora_entrega' => '12:15']);
        $conPunto->update(['latitud' => 20.675432, 'longitud' => -103.351234]);

        $sinPunto = $this->empresaConContrato('Centro', ['ventana_entrega_inicio' => '12:30', 'ventana_entrega_fin' => '13:00', 'hora_entrega' => '12:45']);

        app(EleccionDePlatillos::class)->elegir($this->empleadoDe($conPunto), $fecha, 'comida', $menu);
        app(EleccionDePlatillos::class)->elegir($this->empleadoDe($sinPunto), $fecha, 'comida', $menu);

        $usuarioRep = $this->usuarioDeRol('driver', ['name' => 'Repartidor Mapa']);
        Repartidor::create(['usuario_id' => $usuarioRep->id, 'numero_empleado' => 'R-90', 'placas' => 'JAL-990-A', 'zona_base' => 'Centro']);

        $this->ahoraEs('2026-11-09 18:30');
        app(EjecutorDelCorte::class)->ejecutar($fecha);
        Ruta::query()->update(['estado' => 'aprobada']);

        // Verificar que GeneradorDeRutas NO escribió paradas.ubicacion (se calcula al presentar)
        $this->assertSame(0, DB::table('paradas')->whereNotNull('ubicacion')->count());

        // 1) API de administración: GET /api/v1/admin/rutas
        $this->comoRol('admin');
        $rutasAdmin = $this->getJson('/api/v1/admin/rutas?fecha='.$fecha)->assertOk()->json();
        $paradasAdmin = collect($rutasAdmin[0]['paradas'])->keyBy('nombre_destinatario');

        $this->assertEqualsWithDelta(20.675432, $paradasAdmin[$conPunto->nombre]['ubicacion']['latitud'], 0.000001);
        $this->assertEqualsWithDelta(-103.351234, $paradasAdmin[$conPunto->nombre]['ubicacion']['longitud'], 0.000001);
        $this->assertNull($paradasAdmin[$sinPunto->nombre]['ubicacion']);

        // 2) API del repartidor: GET /api/v1/repartidor/rutas (mismo contrato, solo `ubicacion` poblado cuando hay punto)
        $this->ahoraEs($fecha.' 11:00');
        Sanctum::actingAs($usuarioRep);
        $rutasRep = $this->getJson('/api/v1/repartidor/rutas?fecha='.$fecha)->assertOk()->json();
        $paradasRep = collect($rutasRep[0]['paradas'])->keyBy('destinatario');

        $pCon = $paradasRep[$conPunto->nombre];
        $pSin = $paradasRep[$sinPunto->nombre];

        $this->assertEqualsWithDelta(20.675432, $pCon['ubicacion']['latitud'], 0.000001);
        $this->assertEqualsWithDelta(-103.351234, $pCon['ubicacion']['longitud'], 0.000001);
        $this->assertNull($pSin['ubicacion']);

        // Confirmar que el contrato del repartidor mantiene exactamente las mismas llaves
        $llavesEsperadas = [
            'id', 'orden', 'estado', 'tipo', 'destinatario', 'direccion', 'ubicacion', 'hora_estimada',
            'platillos', 'total_platillos', 'iniciada_en', 'cerrada_en', 'evidencia_foto_url', 'motivo_no_entrega',
            'referencias', 'contacto_nombre', 'telefono', 'folio',
        ];
        $this->assertSame($llavesEsperadas, array_keys($pCon));
        $this->assertSame($llavesEsperadas, array_keys($pSin));
    }
}
