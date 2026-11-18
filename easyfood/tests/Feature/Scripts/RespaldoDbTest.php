<?php

namespace Tests\Feature\Scripts;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/** scripts/respaldo-db.sh: respaldo comprimido, rotación de 7 días y nunca borrar lo anterior si el de hoy falla. Usa un pg_dump falso. */
class RespaldoDbTest extends TestCase
{
    private string $carpeta;

    private string $destino;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpeta = sys_get_temp_dir().'/respaldo-'.bin2hex(random_bytes(4));
        $this->destino = $this->carpeta.'/respaldos';
        mkdir($this->carpeta, 0700, true);

        file_put_contents($this->carpeta.'/env', "DB_HOST=host.docker.internal\nDB_PORT=5433\nDB_DATABASE=easylunch\nDB_USERNAME=usuario\nDB_PASSWORD=\"Cl4ve-Secreta/con=simbolos\"\n");
        // pg_dump falso: anota sus argumentos y su PGPASSWORD, escribe «contenido» en la salida y sale con el código que pida FAKE_SALIDA.
        file_put_contents($this->carpeta.'/pg_dump', <<<'SH'
            #!/bin/sh
            printf '%s\n' "$@" > "$FAKE_ARGS"
            printf '%s' "$PGPASSWORD" > "$FAKE_PGPASSWORD"
            [ "${FAKE_VACIO:-0}" = 1 ] || printf 'contenido-del-respaldo'
            exit "${FAKE_SALIDA:-0}"
            SH);
        chmod($this->carpeta.'/pg_dump', 0755);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->carpeta]))->run();
        parent::tearDown();
    }

    /** @param array<string, string> $extra */
    private function correr(array $extra = [], array $args = []): Process
    {
        $p = new Process(['sh', base_path('scripts/respaldo-db.sh'), ...$args], base_path(), $extra + [
            'ENV_FILE' => $this->carpeta.'/env', 'RESPALDOS_DIR' => $this->destino, 'PG_DUMP_CMD' => $this->carpeta.'/pg_dump',
            'FAKE_ARGS' => $this->carpeta.'/args', 'FAKE_PGPASSWORD' => $this->carpeta.'/pgpassword',
        ]);
        $p->run();

        return $p;
    }

    private function archivo(string $nombre, int $diasDeAntiguedad): string
    {
        @mkdir($this->destino, 0700, true);
        $ruta = "{$this->destino}/{$nombre}";
        file_put_contents($ruta, 'viejo');
        touch($ruta, time() - $diasDeAntiguedad * 86400 - 3600);

        return $ruta;
    }

    /** @return array<int, string> */
    private function respaldos(): array
    {
        return array_values(array_map('basename', glob("{$this->destino}/easylunch-*.dump") ?: []));
    }

    public function test_guarda_el_respaldo_con_permisos_privados_y_sin_dejar_temporales(): void
    {
        $p = $this->correr();

        $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput().$p->getOutput());
        $this->assertCount(1, $this->respaldos());
        $archivo = "{$this->destino}/{$this->respaldos()[0]}";
        $this->assertSame('contenido-del-respaldo', file_get_contents($archivo));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($archivo)), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->destino)), -4));
        $this->assertSame([], glob("{$this->destino}/*.parcial"));
        $this->assertDirectoryDoesNotExist("{$this->destino}/.en-curso", 'el bloqueo se libera al terminar');
    }

    public function test_pide_formato_comprimido_y_la_clave_viaja_por_entorno_nunca_en_la_linea_de_comandos(): void
    {
        $this->correr();

        $args = (string) file_get_contents($this->carpeta.'/args');
        $this->assertStringContainsString('--format=custom', $args);
        $this->assertStringContainsString('--compress=9', $args);
        $this->assertStringContainsString('--dbname=easylunch', $args);
        $this->assertStringContainsString('--host=127.0.0.1', $args, 'host.docker.internal es el propio servidor cuando el script corre fuera de Docker');
        $this->assertStringContainsString('--port=5433', $args);
        $this->assertStringNotContainsString('Cl4ve-Secreta', $args);
        $this->assertSame('Cl4ve-Secreta/con=simbolos', file_get_contents($this->carpeta.'/pgpassword'));
    }

    public function test_la_clave_nunca_se_imprime(): void
    {
        $p = $this->correr();

        $this->assertStringNotContainsString('Cl4ve-Secreta', $p->getOutput().$p->getErrorOutput());
    }

    public function test_borra_lo_que_pasa_de_los_dias_a_guardar_y_conserva_lo_reciente(): void
    {
        $this->archivo('easylunch-20261101-030000.dump', 10);
        $this->archivo('easylunch-20261110-030000.dump', 8);
        $siete = $this->archivo('easylunch-20261111-030000.dump', 6);
        $ayer = $this->archivo('easylunch-20261117-030000.dump', 1);
        $ajeno = $this->archivo('otra-base-20261101-030000.dump', 30);

        $p = $this->correr();

        $this->assertSame(0, $p->getExitCode());
        $this->assertFileDoesNotExist("{$this->destino}/easylunch-20261101-030000.dump");
        $this->assertFileDoesNotExist("{$this->destino}/easylunch-20261110-030000.dump");
        $this->assertFileExists($siete);
        $this->assertFileExists($ayer);
        $this->assertFileExists($ajeno, 'solo rota los respaldos de esta base');
        $this->assertCount(3, $this->respaldos());
    }

    public function test_los_dias_a_guardar_se_pueden_cambiar(): void
    {
        $this->archivo('easylunch-20261115-030000.dump', 3);
        $ayer = $this->archivo('easylunch-20261117-030000.dump', 1);

        $this->correr(['DIAS_A_GUARDAR' => '2']);

        $this->assertFileDoesNotExist("{$this->destino}/easylunch-20261115-030000.dump");
        $this->assertFileExists($ayer);
    }

    public function test_si_pg_dump_falla_no_guarda_nada_nuevo_y_no_borra_ningun_respaldo_anterior(): void
    {
        $muyViejo = $this->archivo('easylunch-20261001-030000.dump', 40);
        $reciente = $this->archivo('easylunch-20261117-030000.dump', 1);

        $p = $this->correr(['FAKE_SALIDA' => '1']);

        $this->assertNotSame(0, $p->getExitCode());
        $this->assertStringContainsString('los respaldos anteriores siguen intactos', $p->getErrorOutput());
        $this->assertFileExists($muyViejo, 'si hoy falla, lo anterior es lo único que hay: no se rota');
        $this->assertFileExists($reciente);
        $this->assertCount(2, $this->respaldos());
        $this->assertSame([], glob("{$this->destino}/*.parcial"));
        $this->assertDirectoryDoesNotExist("{$this->destino}/.en-curso");
    }

    public function test_un_respaldo_vacio_se_descarta(): void
    {
        $viejo = $this->archivo('easylunch-20261001-030000.dump', 40);

        $p = $this->correr(['FAKE_VACIO' => '1']);

        $this->assertNotSame(0, $p->getExitCode());
        $this->assertFileExists($viejo);
        $this->assertCount(1, $this->respaldos());
    }

    public function test_no_corre_dos_veces_a_la_vez(): void
    {
        mkdir("{$this->destino}/.en-curso", 0700, true);

        $p = $this->correr();

        $this->assertNotSame(0, $p->getExitCode());
        $this->assertStringContainsString('Ya hay un respaldo en curso', $p->getErrorOutput());
        $this->assertSame([], $this->respaldos());
        $this->assertDirectoryExists("{$this->destino}/.en-curso", 'no le quita el bloqueo a quien lo tiene');
    }

    public function test_pide_un_archivo_de_variables_valido_y_dias_numericos(): void
    {
        $sin = $this->correr(['ENV_FILE' => $this->carpeta.'/no-existe']);
        $this->assertNotSame(0, $sin->getExitCode());
        $this->assertStringContainsString('No encuentro', $sin->getErrorOutput());

        $mal = $this->correr(['DIAS_A_GUARDAR' => 'siete']);
        $this->assertNotSame(0, $mal->getExitCode());
        $this->assertStringContainsString('DIAS_A_GUARDAR', $mal->getErrorOutput());

        file_put_contents($this->carpeta.'/env-incompleto', "DB_HOST=127.0.0.1\n");
        $incompleto = $this->correr(['ENV_FILE' => $this->carpeta.'/env-incompleto']);
        $this->assertNotSame(0, $incompleto->getExitCode());
        $this->assertStringContainsString('Faltan DB_DATABASE', $incompleto->getErrorOutput());
    }
}
