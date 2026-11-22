<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Api\ReglasDeContrasena;
use Carbon\CarbonImmutable;
use Database\Seeders\DatosDeDemoParaProduccion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CargarDatosDeDemo extends Command
{
    protected $signature = 'easylunch:cargar-demo {--confirmo : Confirma que quieres cargar datos de demo en ESTE servidor} {--clave= : Contraseña de las cuentas de demo (si no, se pide sin mostrarla)} {--desde=2026-11-24 : Primer día de la ventana activa} {--hasta=2026-12-06 : Último día de la ventana activa} {--crear-zonas : Si faltan Zapopan, Tlaquepaque o Guadalajara, crea rectángulos de ejemplo (solo desarrollo; en el servidor importa las zonas reales)}';

    protected $description = 'Carga datos de demo (3 comedores, 3 empresas con empleados, platillos con porciones, historial y rutas activas). Corre en producción solo con --confirmo; se puede repetir sin duplicar nada';

    public function handle(DatosDeDemoParaProduccion $demo): int
    {
        $desde = (string) $this->option('desde');
        $hasta = (string) $this->option('hasta');
        if (Validator::make(compact('desde', 'hasta'), ['desde' => 'required|date_format:Y-m-d', 'hasta' => 'required|date_format:Y-m-d|after_or_equal:desde'])->fails()
            || CarbonImmutable::parse($desde)->diffInDays(CarbonImmutable::parse($hasta)) > 60) {
            $this->error('Las fechas deben ser AAAA-MM-DD, con --hasta igual o posterior a --desde y a lo más 60 días de ventana.');

            return self::FAILURE;
        }

        if (! $this->option('confirmo')) {
            $this->warn('Esto carga datos de demo en el servidor «'.config('app.env').'» (base «'.config('database.connections.'.config('database.default').'.database').'»):');
            $this->line('  · usa las zonas Zapopan, Tlaquepaque y Guadalajara (tienen que existir; impórtalas antes);');
            $this->line('  · 3 comedores y 3 empresas con 5 empleados cada una, platillos publicados y sus porciones;');
            $this->line('  · historial de varias semanas: pedidos, entregas, rutas, incidencias y liquidaciones;');
            $this->line("  · elecciones, entregas y rutas aprobadas del {$desde} al {$hasta}.");
            $this->line('No toca ni borra nada que ya exista, y se puede repetir. Para continuar agrega --confirmo.');

            return self::FAILURE;
        }

        $admin = User::query()->whereHas('role', fn ($q) => $q->where('name', 'admin'))->orderBy('id')->first();
        if (! $admin) {
            $this->error('Todavía no hay un administrador. Crea el primero con: php artisan easylunch:crear-admin correo@ejemplo.com');

            return self::FAILURE;
        }

        if ($demo->yaCargado()) {
            $this->info('Los datos de demo ya estaban cargados: no se hizo nada. (No se duplica ni se borra nada.)');

            return self::SUCCESS;
        }

        $contrasena = (string) ($this->option('clave') ?: config('easylunch.contrasena_de_ejemplo') ?: ($this->input->isInteractive() ? $this->secret('Contraseña para las cuentas de demo (mínimo 8 caracteres, con letras y números)') : ''));
        $errores = Validator::make(['contrasena' => $contrasena], ['contrasena' => ReglasDeContrasena::REGLAS], ReglasDeContrasena::MENSAJES)->errors();
        if ($errores->isNotEmpty()) {
            $this->error($errores->first().' Pásala con --clave o defínela en SEED_PASSWORD.');

            return self::FAILURE;
        }

        try {
            $conteos = $demo->cargar($contrasena, $admin, $desde, $hasta, (bool) $this->option('crear-zonas'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Datos de demo cargados.');
        $this->table(['Concepto', 'Cantidad'], collect($conteos)->map(fn ($n, $concepto) => [$concepto, $n])->values()->all());
        $this->line('Cuentas de demo (@'.DatosDeDemoParaProduccion::DOMINIO.'): comedor.norte, rrhh.nexo, repartidor.guadalajara (también .zapopan y .tlaquepaque), cliente.cecilia… con la contraseña que diste.');

        return self::SUCCESS;
    }
}
