<?php

namespace Tests\Feature\Modelos;

use App\Models\Address;
use App\Models\Configuracion;
use App\Models\ContratoEmpresa;
use App\Models\Corte;
use App\Models\DisponibilidadDia;
use App\Models\EleccionEmpleado;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\Invitacion;
use App\Models\Menu;
use App\Models\Paquete;
use App\Models\Pedido;
use App\Models\Provider;
use App\Models\RegistroAuditoria;
use App\Models\Repartidor;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * Los modelos Eloquent deben coincidir con las tablas reales: nombre de tabla, columnas asignables, marcas de
 * tiempo en español, relaciones y casts. Corren contra «easylunch_pruebas».
 */
class ModelosEloquentTest extends TestCase
{
    use RefreshDatabase;
    use VerificaRestricciones;

    /** @return array<int, class-string<Model>> */
    private function modelos(): array
    {
        $clases = [];
        foreach (glob(app_path('Models/*.php')) as $archivo) {
            $clase = 'App\\Models\\'.basename($archivo, '.php');
            if ((new ReflectionClass($clase))->isInstantiable() && is_subclass_of($clase, Model::class) && $clase !== User::class) {
                $clases[] = $clase;
            }
        }
        $clases[] = User::class;

        return $clases;
    }

    public function test_cada_modelo_apunta_a_una_tabla_y_sus_columnas_asignables_existen(): void
    {
        foreach ($this->modelos() as $clase) {
            $modelo = new $clase;
            $this->assertTrue(Schema::hasTable($modelo->getTable()), "{$clase}: no existe la tabla {$modelo->getTable()}");
            $faltantes = array_values(array_filter($modelo->getFillable(), fn ($c) => ! Schema::hasColumn($modelo->getTable(), $c)));
            $this->assertSame([], $faltantes, "{$clase}: columnas que no existen en {$modelo->getTable()}");
        }
    }

    public function test_hay_un_modelo_por_cada_tabla_de_negocio(): void
    {
        $tablas = array_map(fn ($c) => (new $c)->getTable(), $this->modelos());
        $esperadas = [
            'empresas', 'contratos_comedor', 'contratos_empresa', 'empleados', 'configuracion', 'disponibilidad_dia', 'cortes',
            'elecciones_empleado', 'ordenes_produccion', 'repartidores', 'rutas', 'entregas_empresa', 'paquetes', 'pedidos',
            'pedido_dias', 'paradas', 'incidencias', 'facturas_mensuales', 'liquidaciones_comedor', 'dias_sin_servicio',
            'invitaciones', 'tickets', 'mensajes_ticket', 'registros_auditoria', 'mensajes_contacto', 'zonas', 'avisos',
            'providers', 'menus', 'addresses', 'catalog_items',
        ];
        $this->assertSame([], array_values(array_diff($esperadas, $tablas)));
    }

    public function test_toda_relacion_declarada_genera_una_consulta_valida(): void
    {
        $revisadas = 0;
        foreach ($this->modelos() as $clase) {
            $metodos = (new ReflectionClass($clase))->getMethods(ReflectionMethod::IS_PUBLIC);
            foreach ($metodos as $metodo) {
                $tipo = $metodo->getReturnType();
                if ($metodo->class !== $clase || ! $tipo instanceof \ReflectionNamedType || ! is_subclass_of($tipo->getName(), Relation::class)) {
                    continue;
                }
                // has() arma un EXISTS con las llaves de la relación: falla si una columna no existe.
                $clase::query()->has($metodo->getName())->get();
                $revisadas++;
            }
        }
        $this->assertGreaterThan(60, $revisadas);
    }

    public function test_las_marcas_de_tiempo_se_llaman_creado_en_y_actualizado_en(): void
    {
        $this->assertSame('creado_en', (new Empresa)->getCreatedAtColumn());
        $this->assertSame('actualizado_en', (new Empresa)->getUpdatedAtColumn());

        $empresa = Empresa::create(['usuario_id' => $this->usuario(), 'nombre' => 'Nexo', 'dominio' => 'nexo.mx', 'zona' => 'Zapopan', 'direccion_entrega' => 'Av. 1']);
        $this->assertInstanceOf(CarbonInterface::class, $empresa->fresh()->creado_en);
        $this->assertArrayNotHasKey('created_at', $empresa->fresh()->getAttributes());
    }

    public function test_desactivar_conserva_el_registro_y_el_borrado_sigue_prohibido(): void
    {
        $empresa = Empresa::create(['usuario_id' => $this->usuario(), 'nombre' => 'Nexo', 'dominio' => 'nexo.mx', 'zona' => 'Zapopan', 'direccion_entrega' => 'Av. 1']);
        $this->assertTrue(Empresa::activos()->whereKey($empresa->id)->exists());

        $empresa->desactivar();
        $this->assertFalse(Empresa::activos()->whereKey($empresa->id)->exists());
        $this->assertTrue(Empresa::inactivos()->whereKey($empresa->id)->exists());
        $this->assertNotNull($empresa->fresh()->desactivado_en);

        $empresa->reactivar();
        $this->assertTrue($empresa->fresh()->activo);
        $this->assertNull($empresa->fresh()->desactivado_en);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('desactívalo con activo = false');
        $empresa->delete();
    }

    public function test_los_contratos_devuelven_fechas_y_montos_con_el_formato_del_front(): void
    {
        $empresa = $this->empresa();
        $contrato = ContratoEmpresa::create([
            'empresa_id' => $empresa, 'porcentaje_subsidio' => 60, 'compra_minima_mensual' => 420, 'precio_por_platillo' => 130,
            'vigencia_inicio' => '2026-07-01', 'vigencia_fin' => '2026-12-31', 'hora_entrega' => '12:40',
            'ventana_entrega_inicio' => '12:30', 'ventana_entrega_fin' => '12:50',
        ])->fresh();

        $this->assertInstanceOf(CarbonInterface::class, $contrato->vigencia_inicio);
        $this->assertSame('2026-07-01', $contrato->toArray()['vigencia_inicio']);
        $this->assertSame('130.00', $contrato->precio_por_platillo);
        $this->assertSame('60.00', $contrato->porcentaje_subsidio);
        $this->assertSame(420, $contrato->compra_minima_mensual);
        $this->assertTrue($contrato->activo);
        $this->assertSame($empresa, $contrato->empresa->id);
    }

    public function test_la_configuracion_se_busca_por_clave_y_guarda_quien_la_cambio(): void
    {
        $this->assertSame('18:00', Configuracion::find('hora_corte')->valor);

        $usuario = $this->usuario();
        $ajuste = Configuracion::findOrFail('hora_corte');
        $ajuste->update(['valor' => '17:30', 'actualizado_por' => $usuario]);

        $this->assertSame('17:30', Configuracion::find('hora_corte')->valor);
        $this->assertSame($usuario, Configuracion::find('hora_corte')->actualizadoPor->id);
        $this->assertNotNull(Configuracion::find('hora_corte')->actualizado_en);
    }

    public function test_alergenos_y_etiquetas_viajan_como_arreglos_de_php(): void
    {
        $comedor = Provider::create(['business_name' => 'Cocina Norte', 'phone' => '33 1458 2201', 'address' => 'Calle 1', 'zona' => 'Zapopan Norte']);
        $menu = Menu::create([
            'provider_id' => $comedor->id, 'name' => 'Pollo en mole', 'price' => 49,
            'alergenos' => ['gluten', 'frutos secos', 'a"b\\c', 'NULL'], 'etiquetas' => [], 'calorias' => 650,
        ])->fresh();

        $this->assertSame(['gluten', 'frutos secos', 'a"b\\c', 'NULL'], $menu->alergenos);
        $this->assertSame([], $menu->etiquetas);
        $this->assertSame(650, $menu->calorias);
        $this->assertSame('borrador', $menu->estado_revision);
        $this->assertSame('Zapopan Norte', $menu->provider->zona);

        $menu->update(['alergenos' => ['lácteos']]);
        $this->assertSame(['lácteos'], $menu->fresh()->alergenos);
    }

    public function test_la_cadena_empresa_empleado_eleccion_platillo_comedor_se_recorre(): void
    {
        $empresa = Empresa::findOrFail($this->empresa());
        $empleado = Empleado::create(['usuario_id' => $this->usuario(), 'empresa_id' => $empresa->id, 'departamento' => 'Logística']);
        $menu = Menu::findOrFail($this->menu());
        $eleccion = EleccionEmpleado::create([
            'empleado_id' => $empleado->id, 'fecha' => '2026-11-16', 'tiempo_comida' => 'comida', 'menu_id' => $menu->id,
            'precio_congelado' => 130, 'porcentaje_subsidio_congelado' => 60, 'origen' => 'manual',
        ]);

        $this->assertTrue($empresa->empleados->contains($empleado));
        $this->assertTrue($empleado->elecciones->contains($eleccion));
        $this->assertSame($menu->provider_id, $eleccion->menu->provider->id);
        $this->assertSame($empleado->usuario_id, User::findOrFail($empleado->usuario_id)->empleado->usuario_id);
        $this->assertSame('2026-11-16', $eleccion->fresh()->fecha->toDateString());
    }

    public function test_el_comedor_ve_su_disponibilidad_por_dia_a_traves_de_sus_platillos(): void
    {
        $menu = Menu::findOrFail($this->menu());
        DisponibilidadDia::create(['menu_id' => $menu->id, 'fecha' => '2026-11-16', 'porciones_maximas' => 40]);

        $this->assertSame(40, $menu->disponibilidad()->first()->porciones_maximas);
        $this->assertSame(0, $menu->disponibilidad()->first()->fresh()->porciones_tomadas);
    }

    public function test_el_hash_de_la_invitacion_no_sale_en_el_json(): void
    {
        $empresa = $this->empresa();
        DB::table('roles')->insertOrIgnore(['id' => 6, 'name' => 'company', 'display_name' => 'Empresa']);
        $invitacion = Invitacion::create([
            'usuario_id' => $this->usuario(), 'correo' => 'rh@nexo.mx', 'rol_id' => 6, 'organizacion_tipo' => 'empresa',
            'empresa_id' => $empresa, 'token_hash' => str_repeat('a', 64), 'enviada_en' => now(), 'vence_en' => now()->addDays(7),
        ])->fresh();

        $this->assertArrayNotHasKey('token_hash', $invitacion->toArray());
        $this->assertSame($empresa, $invitacion->empresa->id);
        $this->assertSame('company', $invitacion->rol->name);
    }

    public function test_un_repartidor_con_ubicacion_postgis_se_lee_sin_exponerla(): void
    {
        $repartidor = Repartidor::create(['usuario_id' => $this->usuario(), 'numero_empleado' => 'R-001', 'placas' => 'JAL-123-A', 'zona_base' => 'Centro']);
        DB::statement('UPDATE repartidores SET ultima_ubicacion = ST_SetSRID(ST_MakePoint(-103.35, 20.67), 4326)::geography WHERE id = ?', [$repartidor->id]);

        $this->assertArrayNotHasKey('ultima_ubicacion', Repartidor::find($repartidor->id)->toArray());
        $this->assertTrue(Repartidor::find($repartidor->id)->activo);
    }

    public function test_la_bitacora_solo_tiene_fecha_de_creacion_y_se_agrega_con_el_modelo(): void
    {
        $registro = RegistroAuditoria::create(['usuario_id' => $this->usuario(), 'accion' => 'contrato.actualizado', 'entidad' => 'contratos_empresa', 'entidad_id' => '1', 'detalle' => 'Subsidio de 50 a 60']);

        $this->assertNotNull($registro->fresh()->creado_en);
        $this->assertNull(RegistroAuditoria::UPDATED_AT);
    }

    public function test_un_corte_no_lleva_marcas_de_tiempo_y_ejecuta_por_un_usuario(): void
    {
        $usuario = $this->usuario();
        $corte = Corte::create(['fecha_entrega' => '2026-11-16', 'ejecutado_por' => $usuario, 'estado' => 'correcto', 'sin_cambios' => false]);

        $this->assertFalse($corte->usesTimestamps());
        $this->assertSame($usuario, $corte->fresh()->ejecutadoPor->id);
        $this->assertFalse($corte->fresh()->sin_cambios);
    }

    public function test_el_cliente_ve_sus_pedidos_con_paquete_y_direccion(): void
    {
        $cliente = User::findOrFail($this->usuario());
        $this->zonaDePruebas();
        $direccion = Address::create(['user_id' => $cliente->id, 'street_address' => 'Av. Vallarta 100', 'latitude' => 20.67, 'longitude' => -103.35]);
        $paquete = Paquete::create(['nombre' => 'Semana', 'dias' => 6, 'descuento_porcentaje' => 10]);
        $pedido = Pedido::create([
            'folio' => 'PED-0001', 'cliente_id' => $cliente->id, 'paquete_id' => $paquete->id, 'direccion_id' => $direccion->id,
            'fecha_inicio' => '2026-11-16', 'fecha_fin' => '2026-11-21', 'total' => 588, 'estado' => 'pendiente_pago', 'vence_pago_en' => '2026-11-15 18:00:00',
        ]);

        $this->assertTrue($cliente->pedidos->contains($pedido));
        $this->assertSame($paquete->id, $pedido->paquete->id);
        $this->assertSame($direccion->id, $pedido->direccion->id);
        $this->assertSame('588.00', $pedido->fresh()->total);
    }
}
