<?php

namespace Tests\Feature\Esquema;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Las restricciones viven en PostgreSQL (CHECK, índices parciales, triggers), así que estas pruebas
 * corren contra la base «easylunch_pruebas» (ver phpunit.xml): docker compose exec app php artisan test
 */
class RestriccionesDeEsquemaTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- ayudas

    /** Ejecuta $accion dentro de un savepoint y comprueba que la base la rechace con la restricción esperada. */
    private function rechaza(string $restriccion, callable $accion): void
    {
        try {
            DB::transaction($accion);
        } catch (QueryException $e) {
            $this->assertStringContainsString($restriccion, $e->getMessage());

            return;
        }

        $this->fail("La base aceptó algo que debía rechazar con «{$restriccion}».");
    }

    /** Usuario de la tabla `users` (la del login existente). Su rol es un registro de `roles`; aquí basta con que exista. */
    private function usuario(): int
    {
        DB::table('roles')->insertOrIgnore(['id' => 1, 'name' => 'customer', 'display_name' => 'Cliente']);

        return DB::table('users')->insertGetId([
            'name' => 'Persona de prueba',
            'email' => uniqid('u', true).'@ejemplo.mx',
            'password' => 'no-importa-en-estas-pruebas',
            'role_id' => 1,
        ]);
    }

    private function empresa(): int
    {
        return DB::table('empresas')->insertGetId([
            'usuario_id' => $this->usuario(),
            'nombre' => 'Empresa '.uniqid(),
            'dominio' => 'ejemplo.mx',
            'zona' => 'Zapopan',
            'direccion_entrega' => 'Av. Industria 1450',
        ]);
    }

    /** Un comedor es un registro de `providers` (tabla del equipo). */
    private function comedor(): int
    {
        return DB::table('providers')->insertGetId([
            'business_name' => 'Comedor '.uniqid(),
            'phone' => '33 1458 2201',
            'address' => 'Av. Industria 1450, Zapopan',
        ]);
    }

    private function datosContratoEmpresa(int $empresaId, array $cambios = []): array
    {
        return $cambios + [
            'empresa_id' => $empresaId,
            'porcentaje_subsidio' => 60,
            'compra_minima_mensual' => 420,
            'precio_por_platillo' => 130,
            'vigencia_inicio' => '2026-07-01',
            'vigencia_fin' => '2026-12-31',
            'hora_entrega' => '12:40',
            'ventana_entrega_inicio' => '12:30',
            'ventana_entrega_fin' => '12:50',
        ];
    }

    // ---------------------------------------------------------------- contratos

    public function test_el_subsidio_va_de_0_a_100(): void
    {
        $empresa = $this->empresa();

        $this->rechaza('contratos_empresa_subsidio_entre_0_y_100', fn () => DB::table('contratos_empresa')->insert($this->datosContratoEmpresa($empresa, ['porcentaje_subsidio' => 100.01])));
        $this->rechaza('contratos_empresa_subsidio_entre_0_y_100', fn () => DB::table('contratos_empresa')->insert($this->datosContratoEmpresa($empresa, ['porcentaje_subsidio' => -1])));

        // Los extremos sí valen: 0 % (sin subsidio) y 100 % (la empresa paga todo).
        foreach ([0, 100] as $porcentaje) {
            $otra = $this->empresa();
            DB::table('contratos_empresa')->insert($this->datosContratoEmpresa($otra, ['porcentaje_subsidio' => $porcentaje]));
            $this->assertDatabaseHas('contratos_empresa', ['empresa_id' => $otra, 'porcentaje_subsidio' => $porcentaje]);
        }
    }

    public function test_la_compra_minima_debe_ser_mayor_a_cero(): void
    {
        $this->rechaza('contratos_empresa_compra_minima_positiva', fn () => DB::table('contratos_empresa')->insert($this->datosContratoEmpresa($this->empresa(), ['compra_minima_mensual' => 0])));
        $this->rechaza('contratos_comedor_compra_minima_positiva', fn () => DB::table('contratos_comedor')->insert([
            'comedor_id' => $this->comedor(), 'compra_minima_mensual' => 0, 'vigencia_inicio' => '2026-07-01', 'vigencia_fin' => '2026-12-31',
        ]));
    }

    public function test_la_vigencia_fin_debe_ser_posterior_a_la_de_inicio(): void
    {
        $this->rechaza('contratos_empresa_vigencia_valida', fn () => DB::table('contratos_empresa')->insert($this->datosContratoEmpresa($this->empresa(), ['vigencia_fin' => '2026-07-01'])));
        $this->rechaza('contratos_empresa_vigencia_valida', fn () => DB::table('contratos_empresa')->insert($this->datosContratoEmpresa($this->empresa(), ['vigencia_fin' => '2026-06-30'])));
        $this->rechaza('contratos_comedor_vigencia_valida', fn () => DB::table('contratos_comedor')->insert([
            'comedor_id' => $this->comedor(), 'vigencia_inicio' => '2026-07-01', 'vigencia_fin' => '2026-07-01',
        ]));
    }

    public function test_el_contrato_de_comedor_arranca_con_49_pesos_y_200_platillos(): void
    {
        DB::table('contratos_comedor')->insert(['comedor_id' => $this->comedor(), 'vigencia_inicio' => '2026-07-01', 'vigencia_fin' => '2026-12-31']);

        $this->assertDatabaseHas('contratos_comedor', ['precio_por_platillo' => '49.00', 'compra_minima_mensual' => 200]);
    }

    public function test_la_hora_de_entrega_cae_dentro_de_la_ventana(): void
    {
        $this->rechaza('contratos_empresa_hora_dentro_de_ventana', fn () => DB::table('contratos_empresa')->insert($this->datosContratoEmpresa($this->empresa(), ['hora_entrega' => '15:00'])));
        // Ventana invertida: la hora nunca puede caer dentro, así que también salta la anterior (basta con que falle).
        $this->rechaza('contratos_empresa_hora_dentro_de_ventana', fn () => DB::table('contratos_empresa')->insert($this->datosContratoEmpresa($this->empresa(), ['ventana_entrega_inicio' => '13:00', 'ventana_entrega_fin' => '12:00', 'hora_entrega' => '12:30'])));
        // Ventana sin duración (inicio = fin = hora): solo la rechaza la regla de ventana válida.
        $this->rechaza('contratos_empresa_ventana_valida', fn () => DB::table('contratos_empresa')->insert($this->datosContratoEmpresa($this->empresa(), ['ventana_entrega_inicio' => '12:30', 'ventana_entrega_fin' => '12:30', 'hora_entrega' => '12:30'])));
    }

    public function test_una_empresa_solo_puede_tener_un_contrato_activo_a_la_vez(): void
    {
        $empresa = $this->empresa();
        $contrato = DB::table('contratos_empresa')->insertGetId($this->datosContratoEmpresa($empresa));

        $this->rechaza('contratos_empresa_un_activo_por_empresa', fn () => DB::table('contratos_empresa')->insert($this->datosContratoEmpresa($empresa, ['vigencia_inicio' => '2027-01-01', 'vigencia_fin' => '2027-06-30'])));

        // Desactivado el anterior, ya se puede registrar el siguiente.
        DB::table('contratos_empresa')->where('id', $contrato)->update(['activo' => false, 'desactivado_en' => now()]);
        DB::table('contratos_empresa')->insert($this->datosContratoEmpresa($empresa, ['vigencia_inicio' => '2027-01-01', 'vigencia_fin' => '2027-06-30']));
        $this->assertSame(2, DB::table('contratos_empresa')->where('empresa_id', $empresa)->count());
    }

    // ---------------------------------------------------------------- desactivación lógica y claves foráneas

    public function test_no_se_puede_borrar_y_la_desactivacion_exige_su_fecha(): void
    {
        $empresa = $this->empresa();
        $empleado = DB::table('empleados')->insertGetId(['usuario_id' => $this->usuario(), 'empresa_id' => $empresa]);

        $this->rechaza('No se puede borrar un registro de empleados', fn () => DB::table('empleados')->where('id', $empleado)->delete());
        $this->rechaza('No se puede borrar un registro de empresas', fn () => DB::table('empresas')->where('id', $empresa)->delete());

        // Inactivo sin fecha, o activo con fecha: incoherente.
        $this->rechaza('empleados_desactivacion_coherente', fn () => DB::table('empleados')->where('id', $empleado)->update(['activo' => false]));
        $this->rechaza('empleados_desactivacion_coherente', fn () => DB::table('empleados')->where('id', $empleado)->update(['desactivado_en' => now()]));

        DB::table('empleados')->where('id', $empleado)->update(['activo' => false, 'desactivado_en' => now()]);
        $this->assertDatabaseHas('empleados', ['id' => $empleado, 'activo' => false]);
        $this->assertDatabaseCount('empleados', 1); // sigue existiendo: solo está inactivo
    }

    public function test_una_persona_es_empleado_de_una_sola_empresa(): void
    {
        $usuario = $this->usuario();
        DB::table('empleados')->insert(['usuario_id' => $usuario, 'empresa_id' => $this->empresa()]);

        $this->rechaza('empleados_usuario_id_unique', fn () => DB::table('empleados')->insert(['usuario_id' => $usuario, 'empresa_id' => $this->empresa()]));
    }

    public function test_las_claves_foraneas_no_aceptan_referencias_inexistentes(): void
    {
        $this->rechaza('empleados_empresa_id_foreign', fn () => DB::table('empleados')->insert(['usuario_id' => $this->usuario(), 'empresa_id' => 999999]));
        $this->rechaza('contratos_comedor_comedor_id_foreign', fn () => DB::table('contratos_comedor')->insert(['comedor_id' => 999999, 'vigencia_inicio' => '2026-07-01', 'vigencia_fin' => '2026-12-31']));
    }

    // ---------------------------------------------------------------- configuracion

    public function test_la_configuracion_nace_con_los_valores_confirmados(): void
    {
        $this->assertEquals([
            'hora_corte' => '18:00',
            'regla_sin_eleccion' => 'ultimo_platillo',
            'dias_entrega' => 'lunes_viernes',
            'capacidad_auto_entregas' => '30',
            'capacidad_auto_platillos' => '120',
        ], DB::table('configuracion')->pluck('valor', 'clave')->all());
    }

    public function test_la_configuracion_solo_acepta_sus_cinco_claves_con_valores_validos(): void
    {
        // Valores válidos distintos a los iniciales.
        DB::table('configuracion')->where('clave', 'hora_corte')->update(['valor' => '17:30']);
        DB::table('configuracion')->where('clave', 'regla_sin_eleccion')->update(['valor' => 'no_entregar']);
        DB::table('configuracion')->where('clave', 'dias_entrega')->update(['valor' => 'lunes_sabado']);
        $this->assertDatabaseHas('configuracion', ['clave' => 'hora_corte', 'valor' => '17:30']);

        $this->rechaza('configuracion_pkey', fn () => DB::table('configuracion')->insert(['clave' => 'hora_corte', 'valor' => '19:00']));
        $this->rechaza('configuracion_clave_conocida', fn () => DB::table('configuracion')->insert(['clave' => 'otra_cosa', 'valor' => 'x']));
        $this->rechaza('configuracion_hora_corte_valida', fn () => DB::table('configuracion')->where('clave', 'hora_corte')->update(['valor' => '25:00']));
        $this->rechaza('configuracion_hora_corte_valida', fn () => DB::table('configuracion')->where('clave', 'hora_corte')->update(['valor' => '6 pm']));
        $this->rechaza('configuracion_regla_sin_eleccion_valida', fn () => DB::table('configuracion')->where('clave', 'regla_sin_eleccion')->update(['valor' => 'adivinar']));
        $this->rechaza('configuracion_dias_entrega_validos', fn () => DB::table('configuracion')->where('clave', 'dias_entrega')->update(['valor' => 'domingos']));
        $this->rechaza('configuracion_capacidad_positiva', fn () => DB::table('configuracion')->where('clave', 'capacidad_auto_entregas')->update(['valor' => '0']));
        $this->rechaza('configuracion_capacidad_positiva', fn () => DB::table('configuracion')->where('clave', 'capacidad_auto_platillos')->update(['valor' => '-5']));
    }

    public function test_el_autor_de_un_cambio_de_configuracion_no_bloquea_ni_borra_el_dato(): void
    {
        // El FK es ON DELETE SET NULL; como los usuarios no se borran, se comprueba la definición.
        $regla = DB::selectOne("select confdeltype from pg_constraint where conname = 'configuracion_actualizado_por_foreign'");
        $this->assertSame('n', $regla->confdeltype);
    }

    // ---------------------------------------------------------------- montos

    public function test_los_montos_van_en_numeric_10_2(): void
    {
        $columnas = [['menus', 'price'], ['contratos_comedor', 'precio_por_platillo'], ['contratos_empresa', 'precio_por_platillo']];

        foreach ($columnas as [$tabla, $columna]) {
            $definicion = DB::selectOne('select numeric_precision, numeric_scale from information_schema.columns where table_name = ? and column_name = ?', [$tabla, $columna]);
            $this->assertSame([10, 2], [(int) $definicion->numeric_precision, (int) $definicion->numeric_scale], "{$tabla}.{$columna}");
        }
    }

    public function test_las_coordenadas_de_proveedores_conservan_su_precision(): void
    {
        $lat = DB::selectOne("select numeric_precision, numeric_scale from information_schema.columns where table_name = 'providers' and column_name = 'latitude'");
        $this->assertSame([10, 8], [(int) $lat->numeric_precision, (int) $lat->numeric_scale]);
    }

    // ---------------------------------------------------------------- comedores = providers

    public function test_los_contratos_de_comedor_apuntan_a_providers_y_ya_no_existe_comedores(): void
    {
        $this->assertFalse(Schema::hasTable('comedores'));

        $destino = DB::selectOne("select ccu.table_name from information_schema.referential_constraints rc join information_schema.constraint_column_usage ccu on ccu.constraint_name = rc.unique_constraint_name where rc.constraint_name = 'contratos_comedor_comedor_id_foreign'");
        $this->assertSame('providers', $destino->table_name);
    }

    public function test_no_se_puede_borrar_fisicamente_un_comedor_con_contrato(): void
    {
        $comedor = $this->comedor();
        DB::table('contratos_comedor')->insert(['comedor_id' => $comedor, 'vigencia_inicio' => '2026-07-01', 'vigencia_fin' => '2026-12-31']);

        $this->rechaza('contratos_comedor_comedor_id_foreign', fn () => DB::table('providers')->where('id', $comedor)->delete());
    }
}
