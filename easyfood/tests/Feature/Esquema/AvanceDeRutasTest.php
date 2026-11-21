<?php

namespace Tests\Feature\Esquema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\VerificaRestricciones;
use Tests\TestCase;

/** La base del avance de una ruta y de sus paradas (API del repartidor). */
class AvanceDeRutasTest extends TestCase
{
    use RefreshDatabase;
    use VerificaRestricciones;

    private function parada(int $ruta, array $extra = []): int
    {
        return DB::table('paradas')->insertGetId($extra + ['ruta_id' => $ruta, 'orden' => 1, 'entrega_empresa_id' => $this->entrega(), 'nombre_destinatario' => 'Nexo', 'direccion' => 'Av. 1', 'hora_estimada' => '12:30']);
    }

    public function test_una_ruta_en_curso_trae_su_inicio_y_una_completada_su_fin(): void
    {
        $inicio = now();
        $this->assertGreaterThan(0, $this->ruta(['estado' => 'en_curso', 'iniciada_en' => $inicio]));
        $this->assertGreaterThan(0, $this->ruta(['estado' => 'completada', 'iniciada_en' => $inicio, 'completada_en' => $inicio]));
        $this->rechaza('rutas_avance_coherente', fn () => $this->ruta(['estado' => 'en_curso']));
        $this->rechaza('rutas_avance_coherente', fn () => $this->ruta(['estado' => 'completada', 'iniciada_en' => $inicio]));
        $this->rechaza('rutas_avance_coherente', fn () => $this->ruta(['estado' => 'aprobada', 'iniciada_en' => $inicio]));
        $this->rechaza('rutas_estado_valido', fn () => $this->ruta(['estado' => 'perdida']));
    }

    public function test_una_parada_entregada_lleva_foto_una_no_localizada_lleva_motivo_y_una_en_camino_su_inicio(): void
    {
        $ruta = $this->ruta();
        $ahora = now();

        $this->assertGreaterThan(0, $this->parada($ruta));
        $this->assertGreaterThan(0, $this->parada($ruta, ['orden' => 2, 'entrega_empresa_id' => $this->entrega(), 'estado' => 'en_camino', 'iniciada_en' => $ahora]));
        $this->assertGreaterThan(0, $this->parada($ruta, ['orden' => 3, 'entrega_empresa_id' => $this->entrega(), 'estado' => 'entregado', 'iniciada_en' => $ahora, 'cerrada_en' => $ahora, 'evidencia_foto_url' => 'http://x/f.jpg']));
        $this->assertGreaterThan(0, $this->parada($ruta, ['orden' => 4, 'entrega_empresa_id' => $this->entrega(), 'estado' => 'no_localizado', 'iniciada_en' => $ahora, 'cerrada_en' => $ahora, 'motivo_no_entrega' => 'Cerrado']));

        $nueva = fn (int $orden, array $extra) => $this->parada($ruta, ['orden' => $orden, 'entrega_empresa_id' => $this->entrega()] + $extra);
        $this->rechaza('paradas_entregada_con_evidencia', fn () => $nueva(5, ['estado' => 'entregado', 'iniciada_en' => $ahora, 'cerrada_en' => $ahora]));
        $this->rechaza('paradas_no_entregada_con_motivo', fn () => $nueva(6, ['estado' => 'no_localizado', 'iniciada_en' => $ahora, 'cerrada_en' => $ahora, 'motivo_no_entrega' => '  ']));
        $this->rechaza('paradas_inicio_coherente', fn () => $nueva(7, ['estado' => 'en_camino']));
        $this->rechaza('paradas_inicio_coherente', fn () => $nueva(8, ['estado' => 'pendiente', 'iniciada_en' => $ahora]));
        $this->rechaza('paradas_cierre_coherente', fn () => $nueva(9, ['estado' => 'entregado', 'iniciada_en' => $ahora, 'evidencia_foto_url' => 'http://x/f.jpg']));
        $this->rechaza('paradas_estado_valido', fn () => $nueva(10, ['estado' => 'perdida']));
    }
}
