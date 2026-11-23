<?php

namespace Tests\Feature\Comandos;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ActuaComoUsuario;
use Tests\TestCase;

/** php artisan easylunch:admin-demo: un administrador con contraseña conocida, solo fuera de producción. */
class CrearAdminDeDemoTest extends TestCase
{
    use ActuaComoUsuario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usuarioDeRol('admin'); // deja los roles creados
    }

    public function test_crea_un_administrador_que_puede_iniciar_sesion(): void
    {
        $this->artisan('easylunch:admin-demo')->expectsOutputToContain('demo@easylunch.test')->assertSuccessful();

        $admin = User::where('email', 'demo@easylunch.test')->firstOrFail();
        $this->assertSame(['admin', 'active'], [$admin->role->name, $admin->status]);
        $this->assertNotNull($admin->profile);
        $this->postJson('/api/auth/login', ['email' => 'demo@easylunch.test', 'password' => 'Admin1234'])->assertOk();
    }

    public function test_correrlo_otra_vez_no_duplica_y_restablece_la_contrasena(): void
    {
        $this->artisan('easylunch:admin-demo', ['correo' => 'Otro@Ejemplo.test', 'contrasena' => 'Primera1234'])->assertSuccessful();
        User::where('email', 'otro@ejemplo.test')->update(['status' => 'inactive']);

        $this->artisan('easylunch:admin-demo', ['correo' => 'otro@ejemplo.test', 'contrasena' => 'Segunda1234'])->assertSuccessful();

        $this->assertSame(1, User::where('email', 'otro@ejemplo.test')->count());
        $admin = User::where('email', 'otro@ejemplo.test')->firstOrFail();
        $this->assertSame('active', $admin->status);
        $this->assertTrue(Hash::check('Segunda1234', $admin->password));
        $this->assertSame(1, DB::table('user_profiles')->where('user_id', $admin->id)->count());
    }

    public function test_en_produccion_se_niega_y_no_crea_nada(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('easylunch:admin-demo')->expectsOutputToContain('no se ejecuta en producción')->assertFailed();

        $this->assertNull(User::where('email', 'demo@easylunch.test')->first());
    }
}
