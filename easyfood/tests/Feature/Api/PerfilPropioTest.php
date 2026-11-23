<?php

namespace Tests\Feature\Api;

use App\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActuaComoUsuario;
use Tests\TestCase;

/** PUT /api/auth/profile (de Alberto, rama_chida): cada usuario actualiza su nombre, teléfono, bio, fecha de nacimiento y foto. */
class PerfilPropioTest extends TestCase
{
    use ActuaComoUsuario;
    use RefreshDatabase;

    public function test_actualiza_sus_datos_y_crea_el_perfil_si_no_existia(): void
    {
        $usuario = $this->comoRol('customer');

        $this->putJson('/api/auth/profile', ['name' => 'Luisa Pérez', 'first_name' => 'Luisa', 'last_name' => 'Pérez', 'phone' => '33 1234 5678', 'birth_date' => '1995-04-02'])
            ->assertOk()->assertJsonPath('user.name', 'Luisa Pérez')->assertJsonPath('user.profile.phone', '33 1234 5678');

        $this->assertSame('Luisa Pérez', $usuario->fresh()->name);
        $this->assertSame(1, UserProfile::where('user_id', $usuario->id)->count());

        $this->putJson('/api/auth/profile', ['phone' => '33 0000 1111'])->assertOk();
        $this->assertSame(1, UserProfile::where('user_id', $usuario->id)->count(), 'no duplica el perfil');
        $this->assertSame('Luisa', UserProfile::where('user_id', $usuario->id)->value('first_name'), 'lo que no se manda se conserva');
    }

    public function test_guarda_la_foto_de_perfil_y_rechaza_archivos_que_no_son_imagen(): void
    {
        Storage::fake('public');
        $usuario = $this->comoRol('customer');

        $this->put('/api/auth/profile', ['first_name' => 'Luisa', 'last_name' => 'Pérez', 'avatar' => UploadedFile::fake()->createWithContent('yo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='))], ['Accept' => 'application/json'])->assertOk();
        $this->assertNotEmpty(Storage::disk('public')->allFiles('avatars'));
        $this->assertStringContainsString('/storage/avatars/', UserProfile::where('user_id', $usuario->id)->value('avatar_url'));

        $this->put('/api/auth/profile', ['avatar' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
    }

    public function test_sin_sesion_no_se_puede_actualizar_el_perfil(): void
    {
        $this->putJson('/api/auth/profile', ['name' => 'X'])->assertStatus(401);
    }
}
