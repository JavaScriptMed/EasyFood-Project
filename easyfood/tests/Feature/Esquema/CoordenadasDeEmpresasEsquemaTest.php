<?php

namespace Tests\Feature\Esquema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** La base de las coordenadas de las empresas: opcionales, las dos o ninguna, dentro de rango, y reversibles. */
class CoordenadasDeEmpresasEsquemaTest extends TestCase
{
    use RefreshDatabase;
    use VerificaRestricciones;

    public function test_las_coordenadas_son_opcionales_van_completas_y_dentro_de_rango(): void
    {
        $empresa = $this->empresa();
        $this->assertNull(DB::table('empresas')->where('id', $empresa)->value('latitud'), 'una empresa existente queda sin coordenadas');

        DB::table('empresas')->where('id', $empresa)->update(['latitud' => 20.676543, 'longitud' => -103.347321]);
        $this->assertSame('20.676543', DB::table('empresas')->where('id', $empresa)->value('latitud'));
        DB::table('empresas')->where('id', $empresa)->update(['latitud' => 90, 'longitud' => 180]);
        DB::table('empresas')->where('id', $empresa)->update(['latitud' => -90, 'longitud' => -180]);

        $this->rechaza('empresas_coordenadas_completas', fn () => DB::table('empresas')->where('id', $empresa)->update(['latitud' => 20.6, 'longitud' => null]));
        $this->rechaza('empresas_coordenadas_completas', fn () => DB::table('empresas')->where('id', $empresa)->update(['latitud' => null, 'longitud' => -103.3]));
        $this->rechaza('empresas_latitud_valida', fn () => DB::table('empresas')->where('id', $empresa)->update(['latitud' => 90.000001, 'longitud' => 0]));
        $this->rechaza('empresas_latitud_valida', fn () => DB::table('empresas')->where('id', $empresa)->update(['latitud' => -91, 'longitud' => 0]));
        $this->rechaza('empresas_longitud_valida', fn () => DB::table('empresas')->where('id', $empresa)->update(['latitud' => 0, 'longitud' => 180.000001]));
        $this->rechaza('empresas_longitud_valida', fn () => DB::table('empresas')->where('id', $empresa)->update(['latitud' => 0, 'longitud' => -181]));
    }

    public function test_la_migracion_se_puede_revertir_y_volver_a_aplicar_sin_tocar_datos(): void
    {
        $empresa = $this->empresa();
        $migracion = require database_path('migrations/2026_11_22_200000_agregar_coordenadas_a_empresas.php');

        $migracion->down();
        $this->assertSame(0, (int) DB::selectOne("select count(*) as n from information_schema.columns where table_name = 'empresas' and column_name in ('latitud', 'longitud')")->n);
        $this->assertSame(0, (int) DB::selectOne("select count(*) as n from pg_constraint where conrelid = 'empresas'::regclass and conname in ('empresas_coordenadas_completas', 'empresas_latitud_valida', 'empresas_longitud_valida')")->n, 'down() quita también las restricciones');
        $this->assertSame('Zapopan', DB::table('empresas')->where('id', $empresa)->value('zona'), 'los datos existentes no se tocan');

        $migracion->up();
        $this->assertSame(3, (int) DB::selectOne("select count(*) as n from pg_constraint where conrelid = 'empresas'::regclass and conname in ('empresas_coordenadas_completas', 'empresas_latitud_valida', 'empresas_longitud_valida')")->n);
        $this->assertNull(DB::table('empresas')->where('id', $empresa)->value('latitud'));
    }
}
