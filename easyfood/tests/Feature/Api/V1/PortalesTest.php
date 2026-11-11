<?php

namespace Tests\Feature\Api\V1;

use App\Models\DisponibilidadDia;
use App\Models\EleccionEmpleado;
use App\Models\Empleado;
use App\Models\LiquidacionComedor;
use App\Models\Menu;
use App\Models\OrdenProduccion;
use App\Models\RegistroAuditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** Portales de empresas, comedores y empleados. «Hoy» es el miércoles 11 de nov de 2026 a las 10:00. */
class PortalesTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-11 10:00');
    }

    // ------------------------------------------------------------------ portal de empresas

    public function test_la_empresa_ve_solo_a_sus_empleados_con_su_consumo_y_su_eleccion_de_manana(): void
    {
        $empresa = $this->comoEmpresa();
        $otra = $this->empresaConContrato();
        $mio = $this->empleadoDe($empresa);
        $this->empleadoDe($otra);
        $menu = $this->platillo(null, 'comida', ['name' => 'Pollo']);
        $this->disponible($menu, '2026-11-12');
        DB::table('elecciones_empleado')->insert([
            ['empleado_id' => $mio->id, 'fecha' => '2026-11-12', 'tiempo_comida' => 'comida', 'menu_id' => $menu->id, 'precio_congelado' => 130, 'porcentaje_subsidio_congelado' => 60, 'origen' => 'manual'],
            ['empleado_id' => $mio->id, 'fecha' => '2026-10-05', 'tiempo_comida' => 'comida', 'menu_id' => $menu->id, 'precio_congelado' => 130, 'porcentaje_subsidio_congelado' => 60, 'origen' => 'manual'],
        ]);

        $filas = $this->getJson('/api/v1/empresa/empleados')->assertOk()->json();

        $this->assertCount(1, $filas);
        $this->assertSame($mio->id, $filas[0]['id']);
        $this->assertSame(['2026-10' => 1, '2026-11' => 1], $filas[0]['platillos_por_mes']);
        $this->assertSame(['Pollo', 'manual'], [$filas[0]['eleccion_manana']['platillo_nombre'], $filas[0]['eleccion_manana']['origen']]);
        $this->assertNull($filas[0]['invitacion']);
    }

    public function test_la_empresa_desactiva_y_reactiva_empleados_propios_pero_no_ajenos(): void
    {
        $empresa = $this->comoEmpresa();
        $mio = $this->empleadoDe($empresa);
        $ajeno = $this->empleadoDe($this->empresaConContrato());

        $this->patchJson("/api/v1/empresa/empleados/{$mio->id}/estado", ['activo' => false])->assertOk()->assertJsonPath('activo', false);
        $this->assertNotNull(Empleado::find($mio->id)->desactivado_en);
        $this->patchJson("/api/v1/empresa/empleados/{$mio->id}/estado", ['activo' => true])->assertOk()->assertJsonPath('activo', true);
        $this->assertSame(['empleado.desactivado', 'empleado.reactivado'], RegistroAuditoria::orderBy('id')->pluck('accion')->all());

        $this->patchJson("/api/v1/empresa/empleados/{$ajeno->id}/estado", ['activo' => false])->assertStatus(404);
        $this->patchJson("/api/v1/empresa/empleados/{$mio->id}/estado", [])->assertStatus(422)->assertJsonStructure(['errores' => ['activo']]);
    }

    public function test_un_usuario_de_empresa_sin_empresa_recibe_403_en_espanol(): void
    {
        $this->comoRol('company');

        $this->getJson('/api/v1/empresa/empleados')->assertStatus(403)->assertJsonPath('mensaje', 'Tu cuenta no está asociada a ninguna empresa.');
    }

    // ------------------------------------------------------------------ portal de comedores

    public function test_el_comedor_ve_su_perfil_con_contrato_y_platillos_publicados(): void
    {
        $comedor = $this->comoComedor();
        $this->platillo($comedor);
        $this->platillo($comedor, 'comida', ['estado_revision' => 'borrador']);

        $this->getJson('/api/v1/comedor/perfil')->assertOk()->assertJsonPath('id', $comedor->id)->assertJsonPath('platillos_publicados', 1)
            ->assertJsonPath('contrato.compra_minima_mensual', 200)->assertJsonPath('estado_contrato', 'activo');
    }

    public function test_el_comedor_crea_edita_y_lista_solo_sus_platillos_y_no_puede_publicarlos(): void
    {
        $comedor = $this->comoComedor();
        $ajeno = $this->platillo();
        $datos = ['nombre' => 'Caldo', 'tiempo_comida' => 'comida', 'precio_venta' => 49, 'estado_revision' => 'en_revision', 'calorias' => 400];

        $id = $this->postJson('/api/v1/comedor/platillos', $datos)->assertCreated()->assertJsonPath('comedor_id', $comedor->id)->json('id');
        $this->postJson('/api/v1/comedor/platillos', ['estado_revision' => 'publicado'] + $datos)->assertStatus(422)->assertJsonStructure(['errores' => ['estado_revision']]);
        $this->getJson('/api/v1/comedor/platillos')->assertJsonCount(1);

        $this->putJson("/api/v1/comedor/platillos/{$ajeno->id}", ['nombre' => 'Robado'])->assertStatus(404);
        $this->putJson("/api/v1/comedor/platillos/{$id}", ['nombre' => 'Caldo de res'])->assertOk()->assertJsonPath('nombre', 'Caldo de res')->assertJsonPath('estado_revision', 'en_revision');
    }

    public function test_editar_un_platillo_publicado_o_rechazado_lo_regresa_a_revision(): void
    {
        $comedor = $this->comoComedor();
        $publicado = $this->platillo($comedor);
        $rechazado = $this->platillo($comedor, 'comida', ['estado_revision' => 'rechazado', 'motivo_rechazo' => 'Falta foto']);

        $this->putJson("/api/v1/comedor/platillos/{$publicado->id}", ['precio_venta' => 55])->assertOk()->assertJsonPath('estado_revision', 'en_revision');
        $this->putJson("/api/v1/comedor/platillos/{$rechazado->id}", ['descripcion' => 'Ya con foto'])->assertOk()->assertJsonPath('estado_revision', 'en_revision')->assertJsonPath('motivo_rechazo', null);
    }

    public function test_el_comedor_pone_porciones_por_dia_solo_a_platillos_publicados_y_con_corte_abierto(): void
    {
        $comedor = $this->comoComedor();
        $publicado = $this->platillo($comedor);
        $borrador = $this->platillo($comedor, 'comida', ['estado_revision' => 'borrador']);
        $ajeno = $this->platillo();

        $this->putJson('/api/v1/comedor/disponibilidad', ['cambios' => [['platillo_id' => $publicado->id, 'fecha' => '2026-11-12', 'porciones_maximas' => 40], ['platillo_id' => $publicado->id, 'fecha' => '2026-11-13', 'porciones_maximas' => 30]]])
            ->assertOk()->assertJsonPath('guardados', 2);
        $this->putJson('/api/v1/comedor/disponibilidad', ['cambios' => [['platillo_id' => $publicado->id, 'fecha' => '2026-11-12', 'porciones_maximas' => 60]]])->assertOk();
        $this->assertSame(60, DisponibilidadDia::whereDate('fecha', '2026-11-12')->value('porciones_maximas'));
        $this->assertSame(2, DisponibilidadDia::count(), 'actualizar no duplica');

        $this->putJson('/api/v1/comedor/disponibilidad', ['cambios' => [['platillo_id' => $borrador->id, 'fecha' => '2026-11-12', 'porciones_maximas' => 5]]])->assertStatus(422)->assertJsonPath('codigo', 'platillo_no_publicado');
        $this->putJson('/api/v1/comedor/disponibilidad', ['cambios' => [['platillo_id' => $ajeno->id, 'fecha' => '2026-11-12', 'porciones_maximas' => 5]]])->assertStatus(422)->assertJsonPath('codigo', 'platillo_ajeno');
        $this->putJson('/api/v1/comedor/disponibilidad', ['cambios' => [['platillo_id' => $publicado->id, 'fecha' => '2026-11-10', 'porciones_maximas' => 5]]])->assertStatus(422)->assertJsonPath('codigo', 'corte_cerrado');
    }

    public function test_si_un_cambio_falla_no_se_guarda_ninguno(): void
    {
        $comedor = $this->comoComedor();
        $publicado = $this->platillo($comedor);

        $this->putJson('/api/v1/comedor/disponibilidad', ['cambios' => [
            ['platillo_id' => $publicado->id, 'fecha' => '2026-11-12', 'porciones_maximas' => 40],
            ['platillo_id' => $publicado->id, 'fecha' => '2026-11-10', 'porciones_maximas' => 40], // corte pasado
        ]])->assertStatus(422);

        $this->assertSame(0, DisponibilidadDia::count());
    }

    public function test_lista_la_disponibilidad_de_un_rango_solo_del_propio_comedor(): void
    {
        $comedor = $this->comoComedor();
        $mio = $this->platillo($comedor);
        $this->disponible($mio, '2026-11-12', 40);
        $this->disponible($mio, '2026-11-20', 40);
        $this->disponible($this->platillo(), '2026-11-12', 40);

        $this->getJson('/api/v1/comedor/disponibilidad?desde=2026-11-12&hasta=2026-11-14')->assertOk()->assertJsonCount(1)->assertJsonPath('0.porciones_restantes', 40);
        $this->getJson('/api/v1/comedor/disponibilidad?desde=2026-11-14&hasta=2026-11-12')->assertStatus(422);
    }

    public function test_el_comedor_ve_su_produccion_y_marca_las_ordenes_como_listas(): void
    {
        $comedor = $this->comoComedor();
        $menu = $this->platillo($comedor);
        $otro = $this->platillo();
        foreach ([[$comedor->id, $menu->id], [$otro->provider_id, $otro->id]] as [$c, $m]) {
            DB::table('ordenes_produccion')->insert(['comedor_id' => $c, 'menu_id' => $m, 'fecha' => '2026-11-12', 'porciones_totales' => 10, 'porciones_empresas' => 10, 'porciones_individuales' => 0, 'hora_recoleccion' => '11:00']);
        }

        $this->getJson('/api/v1/comedor/produccion?fecha=2026-11-12')->assertOk()->assertJsonCount(1)->assertJsonPath('0.platillo_id', $menu->id)->assertJsonPath('0.hora_recoleccion', '11:00');

        $this->postJson('/api/v1/comedor/produccion/marcar-listas', ['fecha' => '2026-11-12'])->assertOk()->assertJsonPath('marcadas', 1);
        $this->postJson('/api/v1/comedor/produccion/marcar-listas', ['fecha' => '2026-11-12'])->assertOk()->assertJsonPath('marcadas', 0);
        $this->assertSame(1, OrdenProduccion::where('estado', 'lista')->count(), 'la orden de otro comedor no se toca');
    }

    public function test_el_comedor_ve_sus_pagos(): void
    {
        $comedor = $this->comoComedor();
        $otro = $this->comedorConContrato();
        foreach ([$comedor, $otro] as $c) {
            LiquidacionComedor::create(['comedor_id' => $c->id, 'mes' => '2026-10-01', 'platillos_entregados' => 200, 'precio_por_platillo' => 49, 'platillos_minimos' => 200, 'platillos_faltantes' => 0, 'diferencia_minimo' => 0, 'monto' => 9800]);
        }

        $this->getJson('/api/v1/comedor/liquidaciones')->assertOk()->assertJsonCount(1)->assertJsonPath('0.comedor_id', $comedor->id)->assertJsonPath('0.monto', 9800);
    }

    // ------------------------------------------------------------------ portal de empleados

    private function menuDelJueves(?Menu $menu = null, int $porciones = 10): Menu
    {
        $menu ??= $this->platillo(null, 'comida', ['name' => 'Pollo']);
        $this->disponible($menu, '2026-11-12', $porciones);

        return $menu;
    }

    public function test_el_menu_del_empleado_trae_opciones_precio_y_lo_que_cubre_la_empresa(): void
    {
        $empleado = $this->comoEmpleado($this->empresaConContrato('Zapopan', ['precio_por_platillo' => 130, 'porcentaje_subsidio' => 60]));
        $menu = $this->menuDelJueves();
        $this->platillo(null, 'cena'); // otro tiempo de comida: no aparece

        $m = $this->getJson('/api/v1/empleado/menu')->assertOk()->json();

        $this->assertSame(['2026-11-12', 'comida', '18:00', false, null], [$m['fecha'], $m['tiempo_comida'], $m['hora_corte'], $m['cerrado'], $m['motivo_cerrado']]);
        $this->assertEquals([130, 60, 78, 52], [$m['precio'], $m['porcentaje_subsidio'], $m['empresa_cubre'], $m['empleado_paga']]);
        $this->assertCount(1, $m['opciones']);
        $this->assertSame([$menu->id, 10, false], [$m['opciones'][0]['platillo']['id'], $m['opciones'][0]['porciones_restantes'], $m['opciones'][0]['agotado']]);
        $this->assertNull($m['eleccion']);
        $this->assertSame($empleado->id, $m['empleado']['id']);
    }

    public function test_el_menu_aparece_cerrado_despues_del_corte_o_si_la_empresa_no_tiene_servicio(): void
    {
        $empleado = $this->comoEmpleado();
        $this->menuDelJueves();

        $this->ahoraEs('2026-11-11 18:00');
        $this->getJson('/api/v1/empleado/menu?fecha=2026-11-12')->assertOk()->assertJsonPath('cerrado', true)->assertJsonPath('motivo_cerrado', 'corte');

        $this->ahoraEs('2026-11-11 10:00');
        DB::table('dias_sin_servicio')->insert(['fecha' => '2026-11-13', 'tipo' => 'cierre_empresa', 'motivo' => 'Inventario', 'empresa_id' => $empleado->empresa_id]);
        $this->getJson('/api/v1/empleado/menu?fecha=2026-11-13')->assertOk()->assertJsonPath('cerrado', true)->assertJsonPath('motivo_cerrado', 'empresa_cerrada')->assertJsonCount(0, 'opciones');
    }

    public function test_el_empleado_elige_cambia_y_quita_su_platillo_antes_del_corte(): void
    {
        $this->comoEmpleado($this->empresaConContrato('Zapopan', ['precio_por_platillo' => 125, 'porcentaje_subsidio' => 70]));
        $a = $this->menuDelJueves();
        $b = $this->menuDelJueves($this->platillo());

        $this->putJson('/api/v1/empleado/elecciones', ['fecha' => '2026-11-12', 'tiempo_comida' => 'comida', 'platillo_id' => $a->id])->assertOk()
            ->assertJsonPath('platillo_id', $a->id)->assertJsonPath('precio_congelado', 125)->assertJsonPath('porcentaje_subsidio_congelado', 70)->assertJsonPath('origen', 'manual');
        $this->getJson('/api/v1/empleado/menu')->assertJsonPath('eleccion.platillo_id', $a->id);

        $this->putJson('/api/v1/empleado/elecciones', ['fecha' => '2026-11-12', 'tiempo_comida' => 'comida', 'platillo_id' => $b->id])->assertOk()->assertJsonPath('platillo_id', $b->id);
        $this->assertSame(1, EleccionEmpleado::count());

        $this->deleteJson('/api/v1/empleado/elecciones', ['fecha' => '2026-11-12', 'tiempo_comida' => 'comida'])->assertOk();
        $this->assertSame(0, EleccionEmpleado::count());
        $this->deleteJson('/api/v1/empleado/elecciones', ['fecha' => '2026-11-12', 'tiempo_comida' => 'comida'])->assertStatus(422)->assertJsonPath('codigo', 'sin_eleccion');
    }

    public function test_despues_del_corte_ya_no_se_elige_y_responde_con_el_codigo_corte_cerrado(): void
    {
        $this->comoEmpleado();
        $menu = $this->menuDelJueves();

        $this->ahoraEs('2026-11-11 18:00:00');
        $this->putJson('/api/v1/empleado/elecciones', ['fecha' => '2026-11-12', 'tiempo_comida' => 'comida', 'platillo_id' => $menu->id])
            ->assertStatus(422)->assertJsonPath('codigo', 'corte_cerrado');
    }

    public function test_sin_porciones_valida_y_el_historial_trae_lo_que_cubre_la_empresa(): void
    {
        $empleado = $this->comoEmpleado();
        $agotado = $this->menuDelJueves(null, 0);
        $this->putJson('/api/v1/empleado/elecciones', ['fecha' => '2026-11-12', 'tiempo_comida' => 'comida', 'platillo_id' => $agotado->id])->assertStatus(422)->assertJsonPath('codigo', 'sin_porciones');
        $this->putJson('/api/v1/empleado/elecciones', [])->assertStatus(422)->assertJsonStructure(['errores' => ['fecha', 'tiempo_comida', 'platillo_id']]);

        DB::table('elecciones_empleado')->insert(['empleado_id' => $empleado->id, 'fecha' => '2026-11-10', 'tiempo_comida' => 'comida', 'menu_id' => $agotado->id,
            'precio_congelado' => 130, 'porcentaje_subsidio_congelado' => 60, 'origen' => 'por_defecto', 'cerrada_en' => '2026-11-09 18:00:00']);

        $fila = $this->getJson('/api/v1/empleado/elecciones')->assertOk()->json('0');
        $this->assertEquals([130, 78, 52, 'por_defecto', true], [$fila['precio'], $fila['empresa_cubre'], $fila['empleado_paga'], $fila['origen'], $fila['cerrada']]);
    }
}
