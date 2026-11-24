<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** GET /zonas: las zonas con servicio, sin iniciar sesión. */
class ZonasDisponiblesTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    public function test_cualquiera_ve_las_zonas_activas_con_su_poligono_y_nada_mas(): void
    {
        $this->zonaDePruebas('Centro');
        $this->zonaDePruebas('Zapopan', -103.5, 20.8);

        $r = $this->getJson('/api/v1/zonas')->assertOk()->json();

        $this->assertSame(['Centro', 'Zapopan'], array_column($r, 'nombre'));
        $this->assertSame(['id', 'nombre', 'geojson'], array_keys($r[0]), 'sin conteos de empresas, direcciones ni repartidores');
        $this->assertSame(['Feature', 'Centro'], [$r[0]['geojson']['type'], $r[0]['geojson']['properties']['nombre']]);
        $this->assertContains($r[0]['geojson']['geometry']['type'], ['Polygon', 'MultiPolygon'], 'al simplificar, una zona de una sola parte queda como Polygon');
        $this->assertNotEmpty($r[0]['geojson']['geometry']['coordinates']);
    }

    public function test_las_zonas_desactivadas_no_aparecen(): void
    {
        $this->zonaDePruebas('Centro');
        $id = $this->zonaDePruebas('Tlaquepaque', -103.3, 20.6);
        $this->comoRol('admin');
        $this->patchJson("/api/v1/admin/zonas/{$id}/estado", ['activo' => false])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->assertSame(['Centro'], array_column($this->getJson('/api/v1/zonas')->assertOk()->json(), 'nombre'));
    }

    public function test_sin_zonas_responde_una_lista_vacia(): void
    {
        $this->getJson('/api/v1/zonas')->assertOk()->assertExactJson([]);
    }
}
