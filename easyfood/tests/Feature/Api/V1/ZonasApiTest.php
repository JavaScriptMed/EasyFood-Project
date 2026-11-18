<?php

namespace Tests\Feature\Api\V1;

use App\Models\Address;
use App\Models\RegistroAuditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** Zonas de entrega: crear, reemplazar el polígono, importar varias, desactivar. Solo el administrador. */
class ZonasApiTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    private function poligono(float $lon, float $lat, float $mitad = 0.05): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[[$lon - $mitad, $lat - $mitad], [$lon + $mitad, $lat - $mitad], [$lon + $mitad, $lat + $mitad], [$lon - $mitad, $lat + $mitad], [$lon - $mitad, $lat - $mitad]]]];
    }

    private function feature(string $nombre, array $geometria, string $clave = 'nombre'): array
    {
        return ['type' => 'Feature', 'properties' => [$clave => $nombre], 'geometry' => $geometria];
    }

    private function direccion(float $lon, float $lat): Address
    {
        return Address::create(['user_id' => $this->usuarioDeRol('customer')->id, 'street_address' => 'Av. 1', 'latitude' => $lat, 'longitude' => $lon]);
    }

    // ------------------------------------------------------------------ crear y consultar

    public function test_el_administrador_crea_una_zona_y_la_ve_con_su_poligono(): void
    {
        $admin = $this->comoRol('admin');

        $r = $this->postJson('/api/v1/admin/zonas', ['nombre' => 'Zapopan', 'geojson' => $this->poligono(-103.55, 20.72)])->assertCreated()->json();

        $this->assertSame(['Zapopan', true], [$r['nombre'], $r['activo']]);
        $this->assertGreaterThan(50, $r['area_km2']);
        $this->assertSame(['Feature', 'MultiPolygon', 'Zapopan'], [$r['geojson']['type'], $r['geojson']['geometry']['type'], $r['geojson']['properties']['nombre']]);
        $this->assertSame($admin->id, RegistroAuditoria::where('entidad', 'zonas')->where('accion', 'crear')->sole()->usuario_id);
        $this->getJson("/api/v1/admin/zonas/{$r['id']}")->assertOk()->assertJsonPath('geojson.geometry.type', 'MultiPolygon');
    }

    public function test_las_direcciones_nuevas_y_las_que_ya_existian_quedan_dentro_de_la_zona_creada(): void
    {
        $this->comoRol('admin');
        $antes = $this->direccion(-103.55, 20.72);
        $this->assertNull($antes->fresh()->zona);

        $this->postJson('/api/v1/admin/zonas', ['nombre' => 'Zapopan', 'geojson' => $this->poligono(-103.55, 20.72)])->assertCreated();

        $this->assertSame('Zapopan', $antes->fresh()->zona, 'al crear la zona se recalculan las direcciones ya registradas');
        $this->assertSame('Zapopan', $this->direccion(-103.55, 20.72)->fresh()->zona);
        $this->assertNull($this->direccion(-100.0, 25.0)->fresh()->zona);
    }

    public function test_acepta_feature_multipoligono_y_feature_collection_de_un_solo_poligono(): void
    {
        $this->comoRol('admin');
        $multi = ['type' => 'MultiPolygon', 'coordinates' => [$this->poligono(-103.35, 20.67)['coordinates']]];

        $this->postJson('/api/v1/admin/zonas', ['nombre' => 'Centro', 'geojson' => $this->feature('x', $multi)])->assertCreated();
        $this->postJson('/api/v1/admin/zonas', ['nombre' => 'Norte', 'geojson' => ['type' => 'FeatureCollection', 'features' => [$this->feature('x', $this->poligono(-103.3, 20.9))]]])->assertCreated();

        $this->assertSame(2, DB::table('zonas')->count());
    }

    public function test_el_listado_cuenta_direcciones_empresas_comedores_y_repartidores_de_cada_zona(): void
    {
        $this->comoRol('admin');
        $this->postJson('/api/v1/admin/zonas', ['nombre' => 'Zapopan', 'geojson' => $this->poligono(-103.55, 20.72)])->assertCreated();
        $this->postJson('/api/v1/admin/zonas', ['nombre' => 'Centro', 'geojson' => $this->poligono(-103.35, 20.67)])->assertCreated();
        $this->direccion(-103.55, 20.72);
        $this->empresaConContrato('Zapopan');
        $this->repartidorEn('Zapopan');
        $this->comedorNuevo(); // su zona de ejemplo es Zapopan

        $filas = collect($this->getJson('/api/v1/admin/zonas')->assertOk()->json())->keyBy('nombre');

        $this->assertSame(['Centro', 'Zapopan'], $filas->keys()->all(), 'ordenadas por nombre');
        $this->assertSame([1, 1, 1, 1], [$filas['Zapopan']['direcciones'], $filas['Zapopan']['empresas'], $filas['Zapopan']['comedores'], $filas['Zapopan']['repartidores']]);
        $this->assertSame([0, 0, 0, 0], [$filas['Centro']['direcciones'], $filas['Centro']['empresas'], $filas['Centro']['comedores'], $filas['Centro']['repartidores']]);
    }

    // ------------------------------------------------------------------ validaciones

    public function test_rechaza_nombres_repetidos_sin_importar_mayusculas_y_nombres_vacios(): void
    {
        $this->comoRol('admin');
        $this->postJson('/api/v1/admin/zonas', ['nombre' => 'Zapopan', 'geojson' => $this->poligono(-103.55, 20.72)])->assertCreated();

        $this->postJson('/api/v1/admin/zonas', ['nombre' => 'ZAPOPAN', 'geojson' => $this->poligono(-103.4, 20.5)])->assertStatus(422)->assertJsonPath('codigo', 'zona_repetida');
        $this->postJson('/api/v1/admin/zonas', ['nombre' => '', 'geojson' => $this->poligono(-103.4, 20.5)])->assertStatus(422)->assertJsonStructure(['errores' => ['nombre']]);
        $this->postJson('/api/v1/admin/zonas', ['nombre' => 'Sin polígono'])->assertStatus(422)->assertJsonStructure(['errores' => ['geojson']]);
        $this->assertSame(1, DB::table('zonas')->count());
    }

    public function test_rechaza_geojson_que_no_sirve_y_no_guarda_nada(): void
    {
        $this->comoRol('admin');
        $abierto = $this->poligono(-103.35, 20.67);
        array_pop($abierto['coordinates'][0]);
        $casos = [
            'geojson_invalido' => ['type' => 'Point', 'coordinates' => [-103.3, 20.6]],
            'coordenadas_invalidas' => $this->poligono(20.67, -103.35), // latitud y longitud al revés
            'poligono_invalido' => ['type' => 'Polygon', 'coordinates' => [[[-103.4, 20.6], [-103.3, 20.7], [-103.3, 20.6], [-103.4, 20.7], [-103.4, 20.6]]]], // corbata
        ];

        foreach ($casos as $codigo => $geojson) {
            $this->postJson('/api/v1/admin/zonas', ['nombre' => 'Mala', 'geojson' => $geojson])->assertStatus(422)->assertJsonPath('codigo', $codigo);
        }
        $this->postJson('/api/v1/admin/zonas', ['nombre' => 'Mala', 'geojson' => $abierto])->assertStatus(422)->assertJsonPath('codigo', 'coordenadas_invalidas');
        $this->assertSame(0, DB::table('zonas')->count());
    }

    // ------------------------------------------------------------------ reemplazar el polígono

    public function test_reemplazar_el_poligono_recalcula_direcciones_y_no_cambia_el_nombre(): void
    {
        $this->comoRol('admin');
        $id = $this->postJson('/api/v1/admin/zonas', ['nombre' => 'Centro', 'geojson' => $this->poligono(-103.35, 20.67)])->assertCreated()->json('id');
        $direccion = $this->direccion(-103.35, 20.67);
        $this->assertSame('Centro', $direccion->fresh()->zona);

        $this->putJson("/api/v1/admin/zonas/{$id}", ['nombre' => 'Otro nombre', 'geojson' => $this->poligono(-100.0, 25.0)])->assertOk()->assertJsonPath('nombre', 'Centro');

        $this->assertNull($direccion->fresh()->zona, 'la dirección quedó fuera del polígono nuevo');
        $this->assertSame(1, DB::table('zonas')->count());
        $this->assertSame('actualizar', RegistroAuditoria::where('entidad', 'zonas')->where('accion', 'actualizar')->sole()->accion);
        $this->putJson("/api/v1/admin/zonas/{$id}", ['geojson' => ['type' => 'Point', 'coordinates' => [1, 1]]])->assertStatus(422)->assertJsonPath('codigo', 'geojson_invalido');
        $this->putJson('/api/v1/admin/zonas/999999', ['geojson' => $this->poligono(-100.0, 25.0)])->assertStatus(404);
    }

    // ------------------------------------------------------------------ importar varias

    public function test_importa_varias_zonas_de_una_vez_creando_y_actualizando(): void
    {
        $this->comoRol('admin');
        $this->postJson('/api/v1/admin/zonas', ['nombre' => 'Centro', 'geojson' => $this->poligono(-103.35, 20.67)])->assertCreated();

        $r = $this->postJson('/api/v1/admin/zonas/importar', ['type' => 'FeatureCollection', 'features' => [
            $this->feature('centro', $this->poligono(-103.36, 20.68)),
            $this->feature('Zapopan', $this->poligono(-103.55, 20.72)),
            $this->feature('Tlaquepaque', $this->poligono(-103.3, 20.6), 'name'),
        ]])->assertCreated()->json();

        $this->assertSame([2, 1], [$r['creadas'], $r['actualizadas']]);
        $this->assertSame(['Centro', 'Tlaquepaque', 'Zapopan'], DB::table('zonas')->orderBy('nombre')->pluck('nombre')->all(), 'centro actualizó a Centro, no creó otra');
    }

    public function test_importar_es_todo_o_nada_y_pide_nombres_distintos(): void
    {
        $this->comoRol('admin');
        $buena = $this->feature('Buena', $this->poligono(-103.35, 20.67));

        $this->postJson('/api/v1/admin/zonas/importar', ['type' => 'FeatureCollection', 'features' => [$buena, $this->feature('Mala', ['type' => 'Point', 'coordinates' => [1, 1]])]])
            ->assertStatus(422)->assertJsonPath('codigo', 'geojson_invalido');
        $this->assertSame(0, DB::table('zonas')->count(), 'una zona mala impide guardar las buenas');

        $r = $this->postJson('/api/v1/admin/zonas/importar', ['type' => 'FeatureCollection', 'features' => [$buena, $this->feature('Mala', ['type' => 'Point', 'coordinates' => [1, 1]])]]);
        $this->assertStringContainsString('Zona «Mala»', $r->json('mensaje'));

        $this->postJson('/api/v1/admin/zonas/importar', ['type' => 'FeatureCollection', 'features' => [$buena, ['type' => 'Feature', 'properties' => [], 'geometry' => $buena['geometry']]]])
            ->assertStatus(422)->assertJsonPath('codigo', 'nombre_de_zona_invalido');
        $this->postJson('/api/v1/admin/zonas/importar', ['type' => 'FeatureCollection', 'features' => [$buena, $this->feature('BUENA', $buena['geometry'])]])
            ->assertStatus(422)->assertJsonPath('codigo', 'zona_repetida');
        $this->postJson('/api/v1/admin/zonas/importar', ['type' => 'FeatureCollection', 'features' => []])->assertStatus(422);
        $this->postJson('/api/v1/admin/zonas/importar', ['type' => 'Polygon'])->assertStatus(422);
        $this->assertSame(0, DB::table('zonas')->count());
    }

    // ------------------------------------------------------------------ desactivar

    public function test_desactivar_una_zona_sin_uso_deja_sin_cobertura_sus_direcciones_y_reactivarla_las_recupera(): void
    {
        $this->comoRol('admin');
        $id = $this->postJson('/api/v1/admin/zonas', ['nombre' => 'Norte', 'geojson' => $this->poligono(-103.3, 20.9)])->assertCreated()->json('id');
        $direccion = $this->direccion(-103.3, 20.9);

        $this->patchJson("/api/v1/admin/zonas/{$id}/estado", ['activo' => false])->assertOk()->assertJsonPath('activo', false);
        $this->assertNull($direccion->fresh()->zona);

        $this->patchJson("/api/v1/admin/zonas/{$id}/estado", ['activo' => true])->assertOk()->assertJsonPath('activo', true);
        $this->assertSame('Norte', $direccion->fresh()->zona);
        $this->assertSame(['desactivar', 'reactivar'], RegistroAuditoria::where('entidad', 'zonas')->whereIn('accion', ['desactivar', 'reactivar'])->orderBy('id')->pluck('accion')->all());
        $this->patchJson("/api/v1/admin/zonas/{$id}/estado", [])->assertStatus(422);
        $this->patchJson('/api/v1/admin/zonas/999999/estado', ['activo' => false])->assertStatus(404);
    }

    public function test_no_desactiva_una_zona_que_usan_empresas_comedores_o_repartidores_activos(): void
    {
        $this->comoRol('admin');
        $id = $this->postJson('/api/v1/admin/zonas', ['nombre' => 'Zapopan', 'geojson' => $this->poligono(-103.55, 20.72)])->assertCreated()->json('id');
        $this->empresaConContrato('Zapopan');
        $this->repartidorEn('Zapopan');

        $r = $this->patchJson("/api/v1/admin/zonas/{$id}/estado", ['activo' => false])->assertStatus(422)->assertJsonPath('codigo', 'zona_en_uso');

        $this->assertStringContainsString('1 empresa(s)', $r->json('mensaje'));
        $this->assertTrue((bool) DB::table('zonas')->where('id', $id)->value('activo'));
    }

    // ------------------------------------------------------------------ permisos

    public function test_las_zonas_son_solo_del_administrador(): void
    {
        $this->comoRol('admin');
        $id = $this->postJson('/api/v1/admin/zonas', ['nombre' => 'Zapopan', 'geojson' => $this->poligono(-103.55, 20.72)])->assertCreated()->json('id');

        foreach (['support', 'company', 'provider', 'customer'] as $rol) {
            $this->comoRol($rol);
            $this->getJson('/api/v1/admin/zonas')->assertStatus(403);
            $this->getJson("/api/v1/admin/zonas/{$id}")->assertStatus(403);
            $this->postJson('/api/v1/admin/zonas', ['nombre' => 'X', 'geojson' => $this->poligono(-103.4, 20.5)])->assertStatus(403);
            $this->postJson('/api/v1/admin/zonas/importar', ['type' => 'FeatureCollection', 'features' => []])->assertStatus(403);
            $this->putJson("/api/v1/admin/zonas/{$id}", ['geojson' => $this->poligono(-100.0, 25.0)])->assertStatus(403);
            $this->patchJson("/api/v1/admin/zonas/{$id}/estado", ['activo' => false])->assertStatus(403);
        }
        $this->assertSame(1, DB::table('zonas')->count());
    }
}
