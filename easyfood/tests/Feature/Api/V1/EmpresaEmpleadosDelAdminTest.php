<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** La pestaña «Empleados» del detalle de una empresa en el panel de administración. */
class EmpresaEmpleadosDelAdminTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-09 10:00');
    }

    public function test_el_administrador_ve_solo_los_empleados_de_esa_empresa(): void
    {
        $this->comoRol('admin');
        $nexo = $this->empresaConContrato();
        $otra = $this->empresaConContrato();
        $ana = $this->empleadoDe($nexo);
        $this->empleadoDe($nexo)->desactivar();
        $this->empleadoDe($otra);

        $filas = $this->getJson("/api/v1/admin/empresas/{$nexo->id}/empleados")->assertOk()->assertJsonCount(2)->json();

        $this->assertSame([$ana->id], [$filas[0]['id']]);
        $this->assertSame([true, false], array_column($filas, 'activo'));
        $this->assertArrayHasKey('platillos_por_mes', $filas[0]);
        $this->assertArrayHasKey('eleccion_manana', $filas[0]);
    }

    public function test_el_portal_de_la_empresa_sigue_viendo_los_suyos(): void
    {
        $empresa = $this->comoEmpresa();
        $this->empleadoDe($empresa);
        $this->empleadoDe($this->empresaConContrato());

        $this->getJson('/api/v1/empresa/empleados')->assertOk()->assertJsonCount(1);
    }

    public function test_empresa_inexistente_es_404_y_solo_el_administrador_entra(): void
    {
        $empresa = $this->empresaConContrato();
        $this->comoRol('admin');
        $this->getJson('/api/v1/admin/empresas/999999/empleados')->assertStatus(404);

        foreach (['support', 'company', 'provider', 'customer'] as $rol) {
            $this->comoRol($rol);
            $this->getJson("/api/v1/admin/empresas/{$empresa->id}/empleados")->assertStatus(403);
        }
    }
}
