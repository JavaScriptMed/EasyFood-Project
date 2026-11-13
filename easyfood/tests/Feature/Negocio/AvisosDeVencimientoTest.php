<?php

namespace Tests\Feature\Negocio;

use App\Models\Aviso;
use App\Models\ContratoEmpresa;
use App\Models\Empresa;
use App\Services\AvisosDeVencimiento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Aviso a la empresa y a los administradores cuando un contrato vence en el mes en curso o en los 7 días siguientes a ese mes.
 * «Hoy» es el jueves 5 de nov de 2026: la ventana va del 1 de noviembre al 7 de diciembre.
 */
class AvisosDeVencimientoTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ahoraEs('2026-11-05 09:00');
    }

    private function avisos(): AvisosDeVencimiento
    {
        return app(AvisosDeVencimiento::class);
    }

    /** @return array<int, int> ids de los administradores activos */
    private function administradores(int $cuantos = 2): array
    {
        DB::table('roles')->insertOrIgnore(['id' => 2, 'name' => 'admin', 'display_name' => 'Administrador']);
        $ids = [];
        for ($i = 0; $i < $cuantos; $i++) {
            $ids[] = DB::table('users')->insertGetId(['name' => "Admin {$i}", 'email' => uniqid('a', true).'@easylunch.mx', 'password' => 'x', 'role_id' => 2]);
        }

        return $ids;
    }

    private function empresaQueVence(string $fin): Empresa
    {
        return $this->empresaConContrato('Zapopan', ['vigencia_inicio' => '2026-01-01', 'vigencia_fin' => $fin]);
    }

    public function test_un_contrato_que_vence_este_mes_avisa_a_la_empresa_y_a_cada_administrador(): void
    {
        $admins = $this->administradores(2);
        $empresa = $this->empresaQueVence('2026-11-20');

        $nuevos = $this->avisos()->generar();

        $this->assertSame(3, $nuevos);
        $destinatarios = Aviso::pluck('usuario_id')->sort()->values()->all();
        $this->assertSame(collect([...$admins, $empresa->usuario_id])->sort()->values()->all(), $destinatarios);
        $aviso = Aviso::where('usuario_id', $empresa->usuario_id)->sole();
        $this->assertSame(['contrato_por_vencer', '2026-11-20'], [$aviso->tipo, $aviso->vigencia_fin->toDateString()]);
        $this->assertStringContainsString($empresa->nombre, $aviso->mensaje);
        $this->assertStringContainsString('20 de noviembre de 2026', $aviso->mensaje);
        $this->assertNull($aviso->leido_en);
    }

    public function test_la_ventana_es_el_mes_en_curso_mas_7_dias(): void
    {
        $this->administradores(1);
        $dentro = ['2026-11-02' => 'ya venció este mes', '2026-11-30' => 'último día del mes', '2026-12-01' => 'primer día de margen', '2026-12-07' => 'último día de margen'];
        $fuera = ['2026-10-31' => 'venció el mes pasado', '2026-12-08' => 'pasó el margen', '2027-04-30' => 'falta mucho'];
        $empresas = [];
        foreach (array_merge($dentro, $fuera) as $fin => $motivo) {
            $empresas[$fin] = $this->empresaQueVence($fin);
        }

        $this->avisos()->generar();

        $conAviso = Aviso::where('usuario_id', '<>', 0)->get()->pluck('contrato_empresa_id')->unique()->all();
        foreach ($dentro as $fin => $motivo) {
            $contrato = ContratoEmpresa::where('empresa_id', $empresas[$fin]->id)->value('id');
            $this->assertContains($contrato, $conAviso, "debía avisar: {$fin} ({$motivo})");
        }
        foreach ($fuera as $fin => $motivo) {
            $contrato = ContratoEmpresa::where('empresa_id', $empresas[$fin]->id)->value('id');
            $this->assertNotContains($contrato, $conAviso, "no debía avisar: {$fin} ({$motivo})");
        }
    }

    public function test_repetirlo_no_duplica_los_avisos(): void
    {
        $this->administradores(2);
        $this->empresaQueVence('2026-11-20');

        $this->assertSame(3, $this->avisos()->generar());
        $this->assertSame(0, $this->avisos()->generar());
        $this->ahoraEs('2026-11-18 09:00');
        $this->assertSame(0, $this->avisos()->generar());
        $this->assertSame(3, Aviso::count());
    }

    public function test_renovar_el_contrato_y_que_vuelva_a_caer_en_la_ventana_avisa_otra_vez(): void
    {
        $this->administradores(1);
        $empresa = $this->empresaQueVence('2026-11-20');
        $this->avisos()->generar();

        $contrato = ContratoEmpresa::where('empresa_id', $empresa->id)->sole();
        $contrato->desactivar();
        ContratoEmpresa::create([
            'empresa_id' => $empresa->id, 'porcentaje_subsidio' => 60, 'compra_minima_mensual' => 10, 'precio_por_platillo' => 130,
            'vigencia_inicio' => '2026-11-21', 'vigencia_fin' => '2026-12-05', 'hora_entrega' => '12:40', 'ventana_entrega_inicio' => '12:30', 'ventana_entrega_fin' => '12:50',
        ]);

        $this->assertSame(2, $this->avisos()->generar(), 'el contrato nuevo vence en la ventana: aviso para la empresa y el administrador');
    }

    public function test_no_avisa_de_contratos_desactivados_ni_de_empresas_desactivadas(): void
    {
        $this->administradores(1);
        $inactivoContrato = $this->empresaQueVence('2026-11-20');
        ContratoEmpresa::where('empresa_id', $inactivoContrato->id)->sole()->desactivar();
        $inactivaEmpresa = $this->empresaQueVence('2026-11-20');
        $inactivaEmpresa->desactivar();

        $this->assertSame(0, $this->avisos()->generar());
    }

    public function test_solo_los_administradores_activos_reciben_el_aviso(): void
    {
        [$admin] = $this->administradores(1);
        $suspendido = DB::table('users')->insertGetId(['name' => 'Suspendido', 'email' => 's@easylunch.mx', 'password' => 'x', 'role_id' => 2, 'status' => 'inactive']);
        $empresa = $this->empresaQueVence('2026-11-20');
        $cliente = $this->usuario();

        $this->avisos()->generar();

        $recibieron = Aviso::pluck('usuario_id')->all();
        $this->assertContains($admin, $recibieron);
        $this->assertContains($empresa->usuario_id, $recibieron);
        $this->assertNotContains($suspendido, $recibieron);
        $this->assertNotContains($cliente, $recibieron);
    }

    public function test_la_base_impide_avisos_repetidos_para_el_mismo_contrato_y_fecha(): void
    {
        [$admin] = $this->administradores(1);
        $empresa = $this->empresaQueVence('2026-11-20');
        $contrato = ContratoEmpresa::where('empresa_id', $empresa->id)->value('id');
        $fila = ['usuario_id' => $admin, 'tipo' => 'contrato_por_vencer', 'contrato_empresa_id' => $contrato, 'vigencia_fin' => '2026-11-20', 'mensaje' => 'Vence'];
        DB::table('avisos')->insert($fila);

        $this->rechaza('avisos_usuario_id_tipo_contrato_empresa_id_vigencia_fin_unique', fn () => DB::table('avisos')->insert($fila));
        $this->rechaza('avisos_contrato_por_vencer_completo', fn () => DB::table('avisos')->insert(['usuario_id' => $admin, 'tipo' => 'contrato_por_vencer', 'mensaje' => 'Sin contrato']));
    }
}
