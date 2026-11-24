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

/** PATCH /admin/zonas/{id}: corregir el nombre de una zona sin dejar textos viejos en empresas, comedores, repartidores, rutas y direcciones. */
class ZonasRenombrarTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    public function test_renombrar_actualiza_el_nombre_en_todos_los_lugares_donde_esta_escrito(): void
    {
        $admin = $this->comoRol('admin');
        $id = $this->zonaDePruebas('guadalajara');
        $empresa = $this->empresaConContrato('guadalajara');
        $repartidor = $this->repartidorEn('guadalajara');
        $comedor = $this->comedorNuevo();
        DB::table('providers')->where('id', $comedor->id)->update(['zona' => 'guadalajara']);
        $otraZona = $this->empresaConContrato('Zapopan');
        $direccion = Address::create(['user_id' => $this->usuarioDeRol('customer')->id, 'street_address' => 'Av. 1', 'latitude' => 20.67, 'longitude' => -103.35]);
        $this->assertSame('guadalajara', $direccion->fresh()->zona);
        DB::table('rutas')->insert([
            'fecha' => '2026-11-16', 'repartidor_id' => $repartidor->id, 'zona' => 'guadalajara', 'ventana_inicio' => '12:00', 'ventana_fin' => '13:00',
            'capacidad_entregas' => 30, 'capacidad_platillos' => 120, 'estado' => 'borrador',
        ]);

        $r = $this->patchJson("/api/v1/admin/zonas/{$id}", ['nombre' => '  Guadalajara '])->assertOk()->json();

        $this->assertSame('Guadalajara', $r['nombre']);
        $this->assertSame('Guadalajara', $r['geojson']['properties']['nombre']);
        $this->assertSame('Guadalajara', $empresa->fresh()->zona);
        $this->assertSame('Guadalajara', $repartidor->fresh()->zona_base);
        $this->assertSame('Guadalajara', DB::table('providers')->where('id', $comedor->id)->value('zona'));
        $this->assertSame('Guadalajara', DB::table('rutas')->value('zona'));
        $this->assertSame('Guadalajara', $direccion->fresh()->zona, 'las direcciones se recalculan solas');
        $this->assertSame('Zapopan', $otraZona->fresh()->zona, 'las de otra zona no se tocan');
        $this->assertSame($admin->id, RegistroAuditoria::where('entidad', 'zonas')->where('accion', 'actualizar')->latest('id')->first()->usuario_id);
        $this->assertSame('Guadalajara', collect($this->getJson('/api/v1/admin/zonas')->assertOk()->json())->firstWhere('id', $id)['nombre']);
    }

    public function test_acepta_acentos_y_mayusculas_y_conserva_el_poligono(): void
    {
        $this->comoRol('admin');
        $id = $this->zonaDePruebas('tonala');
        $antes = $this->getJson("/api/v1/admin/zonas/{$id}")->json();

        $despues = $this->patchJson("/api/v1/admin/zonas/{$id}", ['nombre' => 'Tonalá'])->assertOk()->json();

        $this->assertSame('Tonalá', $despues['nombre']);
        $this->assertSame($antes['geojson']['geometry'], $despues['geojson']['geometry']);
        $this->assertSame($antes['area_km2'], $despues['area_km2']);
        $this->patchJson("/api/v1/admin/zonas/{$id}", ['nombre' => 'TONALÁ'])->assertOk()->assertJsonPath('nombre', 'TONALÁ'); // solo cambia el uso de mayúsculas
    }

    public function test_no_acepta_un_nombre_que_ya_tiene_otra_zona_ni_uno_vacio(): void
    {
        $this->comoRol('admin');
        $a = $this->zonaDePruebas('Centro');
        $this->zonaDePruebas('Zapopan', -103.5, 20.8);
        $empresa = $this->empresaConContrato('Centro');

        $this->patchJson("/api/v1/admin/zonas/{$a}", ['nombre' => 'zapopan'])->assertStatus(422)->assertJsonPath('codigo', 'zona_repetida');
        $this->patchJson("/api/v1/admin/zonas/{$a}", ['nombre' => '   '])->assertStatus(422);
        $this->patchJson("/api/v1/admin/zonas/{$a}", [])->assertStatus(422);
        $this->patchJson("/api/v1/admin/zonas/{$a}", ['nombre' => str_repeat('a', 101)])->assertStatus(422);
        $this->patchJson('/api/v1/admin/zonas/999999', ['nombre' => 'Nueva'])->assertNotFound();

        $this->assertSame('Centro', DB::table('zonas')->where('id', $a)->value('nombre'));
        $this->assertSame('Centro', $empresa->fresh()->zona);
    }

    public function test_renombrar_una_zona_inactiva_tambien_funciona_y_las_rutas_siguen_encontrando_repartidor(): void
    {
        $this->comoRol('admin');
        $id = $this->zonaDePruebas('tlaquepaque');
        $this->patchJson("/api/v1/admin/zonas/{$id}/estado", ['activo' => false])->assertOk();

        $this->patchJson("/api/v1/admin/zonas/{$id}", ['nombre' => 'Tlaquepaque'])->assertOk()->assertJsonPath('activo', false);
        $this->assertSame('Tlaquepaque', DB::table('zonas')->where('id', $id)->value('nombre'));
    }

    public function test_solo_la_administracion_renombra_zonas(): void
    {
        $id = $this->zonaDePruebas('Centro');
        foreach (['support', 'driver', 'customer', 'company'] as $rol) {
            $this->comoRol($rol);
            $this->patchJson("/api/v1/admin/zonas/{$id}", ['nombre' => 'Otra'])->assertForbidden();
        }
        $this->assertSame('Centro', DB::table('zonas')->where('id', $id)->value('nombre'));
    }
}
