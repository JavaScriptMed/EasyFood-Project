<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SaludTest extends TestCase
{
    public function test_responde_ok_cuando_la_api_y_la_base_de_datos_funcionan(): void
    {
        $this->getJson('/api/v1/salud')
            ->assertOk()
            ->assertJsonPath('estado', 'ok')
            ->assertJsonPath('api.estado', 'ok')
            ->assertJsonPath('base_de_datos.estado', 'ok')
            ->assertJsonPath('api.zona_horaria', 'America/Mexico_City')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_responde_503_y_no_filtra_detalles_si_la_base_de_datos_falla(): void
    {
        DB::shouldReceive('connection')->andThrow(new \RuntimeException('clave-secreta-en-el-error'));

        $this->getJson('/api/v1/salud')
            ->assertStatus(503)
            ->assertJsonPath('estado', 'degradado')
            ->assertJsonPath('api.estado', 'ok')
            ->assertJsonPath('base_de_datos.estado', 'error')
            ->assertJsonMissing(['clave-secreta-en-el-error']);
    }

    public function test_cors_permite_el_origen_del_front_en_desarrollo(): void
    {
        $this->call('OPTIONS', '/api/v1/salud', server: [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
    }

    public function test_cors_rechaza_otros_origenes(): void
    {
        $this->call('OPTIONS', '/api/v1/salud', server: [
            'HTTP_ORIGIN' => 'http://sitio-desconocido.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
