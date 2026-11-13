<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RutaDeLaravel;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ActuaComoUsuario;
use Tests\Concerns\ArmaEscenarios;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/**
 * El mapa de la API v1: toda ruta existe con su nombre y su rol, y las que todavía no se construyen (las más complejas,
 * el dashboard y las imágenes) responden 501 con un mensaje claro en lugar de un 404.
 */
class RutasDeLaApiTest extends TestCase
{
    use ActuaComoUsuario;
    use ArmaEscenarios;
    use RefreshDatabase;
    use VerificaRestricciones;

    /** @return array<int, RutaDeLaApiTest|RutaDeLaravel> */
    private function rutasV1(): array
    {
        return array_values(array_filter(
            iterator_to_array(Route::getRoutes()),
            fn (RutaDeLaravel $r) => str_starts_with($r->uri(), 'api/v1/') && $r->uri() !== 'api/v1/salud',
        ));
    }

    private function rolDe(RutaDeLaravel $ruta): ?string
    {
        foreach ($ruta->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'role:')) {
                return explode(',', substr($middleware, 5))[0];
            }
        }

        return null;
    }

    private function pendiente(RutaDeLaravel $ruta): bool
    {
        return str_ends_with($ruta->getActionName(), 'NoImplementadoController');
    }

    private function direccion(RutaDeLaravel $ruta): string
    {
        return '/'.preg_replace(['/\{id\}/', '/\{[^}]+\}/'], ['1', 'x'], $ruta->uri());
    }

    public function test_la_api_v1_tiene_unos_90_endpoints_y_todos_tienen_nombre_en_espanol(): void
    {
        $rutas = $this->rutasV1();

        $this->assertGreaterThanOrEqual(90, count($rutas));
        foreach ($rutas as $ruta) {
            $this->assertNotNull($ruta->getName(), "{$ruta->uri()} no tiene nombre");
            $this->assertStringStartsWith('v1.', $ruta->getName());
        }
        $this->assertSame(count($rutas), count(array_unique(array_map(fn ($r) => $r->getName(), $rutas))), 'los nombres no se repiten');
    }

    public function test_los_endpoints_pendientes_son_los_complejos_el_dashboard_y_las_imagenes(): void
    {
        $pendientes = collect($this->rutasV1())->filter(fn ($r) => $this->pendiente($r))->map(fn ($r) => $r->getName())->sort()->values()->all();

        $this->assertSame([
            'v1.admin.dashboard', 'v1.admin.empresas.store', 'v1.admin.incidencias.candidatos', 'v1.admin.incidencias.reasignar',
            'v1.admin.platillos.historial-precios', 'v1.admin.platillos.imagen', 'v1.admin.repartidores.store', 'v1.admin.rutas.generar',
            'v1.comedor.platillos.imagen', 'v1.cuentas.invitaciones.aceptar', 'v1.cuentas.invitaciones.crear', 'v1.cuentas.invitaciones.mostrar',
            'v1.cuentas.invitaciones.reenviar', 'v1.cuentas.restablecer.aplicar', 'v1.cuentas.restablecer.solicitar', 'v1.cuentas.restablecer.validar',
            'v1.cuentas.usuarios-acceso', 'v1.empresa.empleados.store',
        ], $pendientes);
    }

    public function test_sin_iniciar_sesion_todo_lo_privado_responde_401_con_mensaje_en_espanol(): void
    {
        foreach ($this->rutasV1() as $ruta) {
            if (! in_array('auth:sanctum', $ruta->gatherMiddleware(), true)) {
                continue;
            }
            $respuesta = $this->json($ruta->methods()[0], $this->direccion($ruta));

            $respuesta->assertStatus(401);
            $this->assertSame('no_autenticado', $respuesta->json('codigo'), $ruta->uri());
            $this->assertSame('Necesitas iniciar sesión para continuar.', $respuesta->json('mensaje'));
        }
    }

    public function test_un_rol_equivocado_recibe_403(): void
    {
        $this->comoRol('customer');
        $sinNegar = [];
        foreach ($this->rutasV1() as $ruta) {
            $roles = collect($ruta->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'role:'));
            if (! $roles || in_array('customer', explode(',', substr($roles, 5)), true)) {
                continue;
            }
            if ($this->json($ruta->methods()[0], $this->direccion($ruta))->status() !== 403) {
                $sinNegar[] = "{$ruta->methods()[0]} {$ruta->uri()}";
            }
        }

        $this->assertSame([], $sinNegar, 'estas rutas debían negarle el paso a un cliente');
    }

    public function test_los_pendientes_responden_501_para_quien_tiene_el_rol_correcto(): void
    {
        foreach ($this->rutasV1() as $ruta) {
            if (! $this->pendiente($ruta) || ! ($rol = $this->rolDe($ruta))) {
                continue;
            }
            $this->comoRol($rol);

            $respuesta = $this->json($ruta->methods()[0], $this->direccion($ruta));

            $respuesta->assertStatus(501)->assertJson(['codigo' => 'no_implementado']);
            $this->assertStringContainsString('todavía no está construido', $respuesta->json('mensaje'));
        }
    }

    public function test_los_pendientes_publicos_tambien_responden_501(): void
    {
        foreach ($this->rutasV1() as $ruta) {
            if ($this->pendiente($ruta) && ! in_array('auth:sanctum', $ruta->gatherMiddleware(), true)) {
                $this->json($ruta->methods()[0], $this->direccion($ruta))->assertStatus(501);
            }
        }
    }

    public function test_el_soporte_atiende_clientes_tickets_y_incidencias_pero_no_el_dinero(): void
    {
        $this->comoRol('support');

        $this->getJson('/api/v1/admin/clientes')->assertOk();
        $this->getJson('/api/v1/admin/tickets')->assertOk();
        $this->getJson('/api/v1/admin/facturas?mes=2026-10')->assertStatus(403);
        $this->getJson('/api/v1/admin/configuracion')->assertStatus(403);
    }

    public function test_una_ruta_que_no_existe_responde_404_en_espanol(): void
    {
        $this->comoRol('admin');

        $this->getJson('/api/v1/admin/no-existe')->assertStatus(404)->assertJson(['codigo' => 'no_encontrado', 'mensaje' => 'No encontramos lo que buscas.']);
        $this->getJson('/api/v1/admin/empresas/999999')->assertStatus(404)->assertJson(['codigo' => 'no_encontrado']);
    }
}
