<?php

namespace Tests\Feature\Negocio;

use App\Exceptions\ReglaDeNegocioException;
use App\Models\Address;
use App\Models\Paquete;
use App\Models\User;
use App\Services\ContratacionDePaquetes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Cada dirección guarda su punto en el mapa (PostGIS) y la zona se calcula sola al crearla o editarla.
 * Fuera de toda zona queda «sin cobertura» y no se puede usar para pedir.
 */
class ZonasDeDireccionesTest extends TestCase
{
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    // Dos zonas vecinas: Centro (lon -103.40…-103.30) y Zapopan (lon -103.30…-103.20), ambas de lat 20.60 a 20.70.
    private function dosZonas(): void
    {
        $this->zonaConPoligono('Centro', [[-103.40, 20.60], [-103.30, 20.60], [-103.30, 20.70], [-103.40, 20.70]]);
        $this->zonaConPoligono('Zapopan', [[-103.30, 20.60], [-103.20, 20.60], [-103.20, 20.70], [-103.30, 20.70]]);
    }

    private function direccion(float $lat, float $lon, ?int $usuario = null): Address
    {
        return Address::create(['user_id' => $usuario ?? $this->usuario(), 'street_address' => 'Calle 1', 'latitude' => $lat, 'longitude' => $lon])->refresh();
    }

    public function test_una_direccion_dentro_de_una_zona_la_recibe_y_guarda_su_punto_en_el_mapa(): void
    {
        $this->dosZonas();

        $direccion = $this->direccion(20.65, -103.35);

        $this->assertSame('Centro', $direccion->zona);
        $this->assertFalse($direccion->sin_cobertura);
        $punto = DB::selectOne('select ST_X(ubicacion::geometry) as lon, ST_Y(ubicacion::geometry) as lat, ST_SRID(ubicacion::geometry) as srid from addresses where id = ?', [$direccion->id]);
        $this->assertEqualsWithDelta(-103.35, $punto->lon, 0.000001);
        $this->assertEqualsWithDelta(20.65, $punto->lat, 0.000001);
        $this->assertSame(4326, $punto->srid);
    }

    public function test_cada_direccion_cae_en_su_zona(): void
    {
        $this->dosZonas();

        $this->assertSame('Zapopan', $this->direccion(20.65, -103.25)->zona);
        $this->assertSame('Centro', $this->direccion(20.65, -103.39)->zona);
    }

    public function test_fuera_de_toda_zona_queda_sin_cobertura(): void
    {
        $this->dosZonas();

        $lejos = $this->direccion(19.43, -99.13); // Ciudad de México

        $this->assertNull($lejos->zona);
        $this->assertTrue($lejos->sin_cobertura);
    }

    public function test_al_editar_las_coordenadas_se_recalcula_la_zona(): void
    {
        $this->dosZonas();
        $direccion = $this->direccion(20.65, -103.35);

        $direccion->update(['longitude' => -103.25]);
        $this->assertSame('Zapopan', $direccion->fresh()->zona);

        $direccion->update(['latitude' => 25.0]);
        $this->assertTrue($direccion->fresh()->sin_cobertura);
        $this->assertNull($direccion->fresh()->zona);

        $direccion->update(['latitude' => 20.65, 'longitude' => -103.35]);
        $this->assertSame('Centro', $direccion->fresh()->zona);
    }

    public function test_si_se_crea_o_se_apaga_una_zona_las_direcciones_se_recalculan(): void
    {
        $direccion = $this->direccion(20.65, -103.35);
        $this->assertTrue($direccion->sin_cobertura, 'sin zonas cargadas nada tiene cobertura');

        $this->dosZonas();
        $this->assertSame('Centro', $direccion->fresh()->zona);

        DB::table('zonas')->where('nombre', 'Centro')->update(['activo' => false, 'desactivado_en' => now()]);
        $this->assertTrue($direccion->fresh()->sin_cobertura, 'una zona desactivada ya no cubre');
    }

    public function test_si_dos_zonas_se_traslapan_gana_la_mas_pequena(): void
    {
        $this->zonaConPoligono('Metropolitana', [[-103.50, 20.50], [-103.10, 20.50], [-103.10, 20.80], [-103.50, 20.80]]);
        $this->zonaConPoligono('Colonia Americana', [[-103.36, 20.64], [-103.34, 20.64], [-103.34, 20.66], [-103.36, 20.66]]);

        $this->assertSame('Colonia Americana', $this->direccion(20.65, -103.35)->zona);
        $this->assertSame('Metropolitana', $this->direccion(20.75, -103.45)->zona);
    }

    public function test_los_limites_de_la_zona_cuentan_como_dentro(): void
    {
        $this->dosZonas();

        $this->assertNotNull($this->direccion(20.65, -103.40)->zona);
    }

    public function test_el_nombre_de_zona_es_unico_y_el_poligono_debe_ser_valido(): void
    {
        $this->dosZonas();

        $this->rechaza('zonas_nombre_unique', fn () => DB::statement("insert into zonas (nombre, area) values ('Centro', ST_Multi(ST_GeomFromText('POLYGON((0 0, 1 0, 1 1, 0 0))', 4326))::geography)"));
        // Moño de corbata: se cruza a sí mismo.
        $this->rechaza('zonas_area_valida', fn () => DB::statement("insert into zonas (nombre, area) values ('Rota', ST_Multi(ST_GeomFromText('POLYGON((0 0, 1 1, 1 0, 0 1, 0 0))', 4326))::geography)"));
    }

    public function test_una_direccion_sin_cobertura_no_se_puede_usar_para_pedir(): void
    {
        $this->dosZonas();
        $this->ahoraEs('2026-11-09 10:00');
        $cliente = User::findOrFail($this->usuario());
        $lejos = $this->direccion(19.43, -99.13, $cliente->id);
        $menu = $this->platillo();
        $this->disponible($menu, '2026-11-10');
        $paquete = Paquete::create(['nombre' => 'Un día', 'dias' => 1]);

        try {
            app(ContratacionDePaquetes::class)->pedir($cliente, $paquete, $lejos, [$menu]);
            $this->fail('Debía rechazar la dirección sin cobertura.');
        } catch (ReglaDeNegocioException $e) {
            $this->assertSame('sin_cobertura', $e->codigo);
        }
        $this->assertSame(0, DB::table('pedidos')->count());
        $this->assertSame(0, DB::table('disponibilidad_dia')->value('porciones_tomadas'));
    }

    public function test_la_base_tambien_rechaza_un_pedido_en_una_direccion_sin_cobertura(): void
    {
        $this->dosZonas();
        $lejos = $this->direccion(19.43, -99.13);
        $paquete = DB::table('paquetes')->insertGetId(['nombre' => 'Dos días', 'dias' => 2]);

        $this->rechaza('fuera de cobertura', fn () => DB::table('pedidos')->insert([
            'folio' => 'GDL-X', 'cliente_id' => $lejos->user_id, 'paquete_id' => $paquete, 'direccion_id' => $lejos->id,
            'fecha_inicio' => '2026-11-16', 'fecha_fin' => '2026-11-17', 'total' => 100, 'vence_pago_en' => '2026-11-15 18:00:00',
        ]));
    }

    public function test_un_pedido_en_una_direccion_con_cobertura_si_se_hace(): void
    {
        $this->dosZonas();
        $this->ahoraEs('2026-11-09 10:00');
        $cliente = User::findOrFail($this->usuario());
        $direccion = $this->direccion(20.65, -103.35, $cliente->id);
        $menu = $this->platillo();
        $this->disponible($menu, '2026-11-10');
        $paquete = Paquete::create(['nombre' => 'Un día', 'dias' => 1]);

        $pedido = app(ContratacionDePaquetes::class)->pedir($cliente, $paquete, $direccion, [$menu]);

        $this->assertSame('pendiente_pago', $pedido->estado);
    }
}
