<?php

namespace Tests\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Ayudas para probar restricciones de PostgreSQL: filas mínimas válidas y un "debe fallar con esta restricción". */
trait VerificaRestricciones
{
    /** Ejecuta $accion en un savepoint y comprueba que la base la rechace mencionando $restriccion. */
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

    private function usuario(): int
    {
        DB::table('roles')->insertOrIgnore(['id' => 1, 'name' => 'customer', 'display_name' => 'Cliente']);

        return DB::table('users')->insertGetId(['name' => 'Persona', 'email' => uniqid('u', true).'@ejemplo.mx', 'password' => 'x', 'role_id' => 1]);
    }

    private function comedor(): int
    {
        return DB::table('providers')->insertGetId(['business_name' => 'Comedor '.uniqid(), 'phone' => '33 0000 0000', 'address' => 'Calle 1']);
    }

    private function menu(?int $comedor = null, array $extra = []): int
    {
        return DB::table('menus')->insertGetId(array_merge(['provider_id' => $comedor ?? $this->comedor(), 'name' => 'Platillo '.uniqid(), 'price' => 130], $extra));
    }

    private function empresa(): int
    {
        return DB::table('empresas')->insertGetId(['usuario_id' => $this->usuario(), 'nombre' => 'Empresa '.uniqid(), 'dominio' => 'ejemplo.mx', 'zona' => 'Zapopan', 'direccion_entrega' => 'Av. 1']);
    }

    private function empleado(?int $empresa = null): int
    {
        return DB::table('empleados')->insertGetId(['usuario_id' => $this->usuario(), 'empresa_id' => $empresa ?? $this->empresa()]);
    }

    private function repartidor(): int
    {
        return DB::table('repartidores')->insertGetId(['usuario_id' => $this->usuario(), 'numero_empleado' => uniqid('R'), 'placas' => 'JAL-123-A', 'zona_base' => 'Centro']);
    }

    private function ruta(array $extra = []): int
    {
        return DB::table('rutas')->insertGetId(array_merge([
            'fecha' => '2026-11-16', 'repartidor_id' => $this->repartidor(), 'zona' => 'Zapopan', 'ventana_inicio' => '12:00', 'ventana_fin' => '14:00',
            'capacidad_entregas' => 30, 'capacidad_platillos' => 120,
        ], $extra));
    }

    private function entrega(array $extra = []): int
    {
        return DB::table('entregas_empresa')->insertGetId(array_merge(['empresa_id' => $this->empresa(), 'fecha' => '2026-11-16', 'platillos' => 20, 'ventana_inicio' => '12:30', 'ventana_fin' => '12:50'], $extra));
    }

    private function eleccion(int $empleado, int $menu, array $extra = []): int
    {
        return DB::table('elecciones_empleado')->insertGetId(array_merge([
            'empleado_id' => $empleado, 'fecha' => '2026-11-16', 'tiempo_comida' => 'comida', 'menu_id' => $menu,
            'precio_congelado' => 130, 'porcentaje_subsidio_congelado' => 60, 'origen' => 'manual',
        ], $extra));
    }

    private function pedido(array $extra = []): int
    {
        $cliente = $this->usuario();
        // Solo puede haber un paquete activo por cantidad de días: se reutiliza el de 2 días si ya existe.
        $paquete = DB::table('paquetes')->where('dias', 2)->value('id') ?? DB::table('paquetes')->insertGetId(['nombre' => 'Paquete de 2 días', 'dias' => 2]);
        $this->zonaDePruebas();
        $direccion = DB::table('addresses')->insertGetId(['user_id' => $cliente, 'street_address' => 'Av. Vallarta 1248', 'latitude' => 20.6767, 'longitude' => -103.3472]);

        return DB::table('pedidos')->insertGetId(array_merge([
            'folio' => uniqid('GDL-'), 'cliente_id' => $cliente, 'paquete_id' => $paquete, 'direccion_id' => $direccion,
            'fecha_inicio' => '2026-11-16', 'fecha_fin' => '2026-11-17', 'total' => 398, 'vence_pago_en' => '2026-11-15 18:00:00',
        ], $extra));
    }

    /**
     * Una zona activa que es un cuadro de ±0.1° alrededor de ($lon, $lat) (por defecto el centro de Guadalajara).
     * Las direcciones de prueba en esas coordenadas quedan con cobertura. Si la zona ya existe, no hace nada.
     */
    private function zonaDePruebas(string $nombre = 'Centro', float $lon = -103.35, float $lat = 20.67, float $mitad = 0.1): int
    {
        return $this->zonaConPoligono($nombre, [[$lon - $mitad, $lat - $mitad], [$lon + $mitad, $lat - $mitad], [$lon + $mitad, $lat + $mitad], [$lon - $mitad, $lat + $mitad]]);
    }

    /** @param array<int, array{0: float, 1: float}> $vertices [lon, lat] en orden; el polígono se cierra solo */
    private function zonaConPoligono(string $nombre, array $vertices): int
    {
        if ($id = DB::table('zonas')->where('nombre', $nombre)->value('id')) {
            return $id;
        }
        $vertices[] = $vertices[0];
        $wkt = 'POLYGON(('.implode(', ', array_map(fn ($v) => "{$v[0]} {$v[1]}", $vertices)).'))';

        return DB::selectOne('insert into zonas (nombre, area) values (?, ST_Multi(ST_GeomFromText(?, 4326))::geography) returning id', [$nombre, $wkt])->id;
    }

    private function pedidoDia(?int $pedido = null, array $extra = []): int
    {
        return DB::table('pedido_dias')->insertGetId(array_merge(['pedido_id' => $pedido ?? $this->pedido(), 'fecha' => '2026-11-16', 'menu_id' => $this->menu(), 'precio_congelado' => 119], $extra));
    }
}
