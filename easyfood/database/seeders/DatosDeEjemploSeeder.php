<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\ContratoComedor;
use App\Models\ContratoEmpresa;
use App\Models\Corte;
use App\Models\DiaSinServicio;
use App\Models\DisponibilidadDia;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\Incidencia;
use App\Models\MensajeContacto;
use App\Models\MensajeTicket;
use App\Models\Menu;
use App\Models\Paquete;
use App\Models\Provider;
use App\Models\Repartidor;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\Bitacora;
use App\Services\CalendarioDeServicio;
use App\Services\ContratacionDePaquetes;
use App\Services\EleccionDePlatillos;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Datos de EJEMPLO para probar la API a mano (colección de Bruno en /bruno) y ver cómo se mueve el sistema.
 * Solo desarrollo: se niega a correr en producción. Todas las cuentas usan la contraseña de SEED_PASSWORD (.env).
 *
 *   docker compose exec app php artisan db:seed --class=DatosDeEjemploSeeder
 *
 * Las fechas son relativas a hoy (el contrato de una empresa vence pronto, hay elecciones para la próxima entrega,
 * historial del mes pasado para facturar, etc.), así que el ejemplo siempre sirve. Las zonas son rectángulos APROXIMADOS
 * alrededor de Guadalajara, de ejemplo: los polígonos reales los carga el administrador.
 *
 * Cuentas (todas con SEED_PASSWORD):  admin@easylunch.test · soporte@easylunch.test · comedor@easylunch.test (Cocina Norte)
 * comedor.sur@easylunch.test · rrhh@nexo.test (Grupo Industrial Nexo) · rrhh@pacifico.test · ana@nexo.test (empleada)
 * cliente@easylunch.test · cliente2@easylunch.test · repartidor@easylunch.test · repartidor2@easylunch.test
 */
class DatosDeEjemploSeeder extends Seeder
{
    private const ROLES = ['customer' => 1, 'admin' => 2, 'driver' => 3, 'provider' => 4, 'support' => 5, 'company' => 6, 'employee' => 7];

    private string $contrasena;

    private CarbonImmutable $hoy;

    private CalendarioDeServicio $calendario;

    public function run(CalendarioDeServicio $calendario, EleccionDePlatillos $elecciones, ContratacionDePaquetes $paquetes): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Los datos de ejemplo no se cargan en producción.');
        }
        $this->contrasena = (string) config('easylunch.contrasena_de_ejemplo');
        if (strlen($this->contrasena) < 8) {
            throw new RuntimeException('Define SEED_PASSWORD (mínimo 8 caracteres) en tu .env antes de cargar los datos de ejemplo.');
        }
        if (User::where('email', 'admin@easylunch.test')->exists()) {
            $this->command?->warn('Los datos de ejemplo ya estaban cargados: no se hace nada. (Para empezar de cero: php artisan migrate:fresh)');

            return;
        }

        $this->calendario = $calendario;
        $this->hoy = $calendario->ahora()->startOfDay();

        DB::transaction(function () use ($elecciones, $paquetes) {
            $this->call(InitialDataSeeder::class);
            $this->zonas();
            $admin = $this->persona('admin', 'Alicia', 'Administradora', 'admin@easylunch.test');
            $this->persona('support', 'Sergio', 'Soporte', 'soporte@easylunch.test');

            [$norte, $sur] = $this->comedores();
            [$nexo, $pacifico] = $this->empresas();
            $empleados = $this->empleados($nexo, $pacifico);
            $menus = $this->menus($norte, $sur);
            $this->disponibilidad($menus);
            $this->historial($empleados, $menus);
            $this->eleccionesParaLaProximaEntrega($elecciones, $empleados, $menus);
            $this->repartidores();
            $this->clientes($paquetes, $menus);
            $this->variosDeSoporteYBitacora($admin);
        });

        $this->command?->info('Datos de ejemplo cargados. Entra con cualquiera de las cuentas @easylunch.test / @nexo.test / @pacifico.test y la contraseña de SEED_PASSWORD.');
    }

    // ------------------------------------------------------------------ personas y organizaciones

    private function persona(string $rol, string $nombre, string $apellido, string $correo, ?string $telefono = null): User
    {
        $usuario = User::create(['role_id' => self::ROLES[$rol], 'name' => "{$nombre} {$apellido}", 'email' => $correo, 'password' => $this->contrasena, 'status' => 'active']);
        UserProfile::create(['user_id' => $usuario->id, 'first_name' => $nombre, 'last_name' => $apellido, 'phone' => $telefono ?? '33 '.random_int(1000, 9999).' '.random_int(1000, 9999)]);

        return $usuario;
    }

    private function zonas(): void
    {
        // lon mínima, lat mínima, lon máxima, lat máxima (rectángulos aproximados, de ejemplo)
        $zonas = ['Centro' => [-103.38, 20.65, -103.31, 20.70], 'Zapopan' => [-103.50, 20.66, -103.38, 20.80], 'Tlaquepaque' => [-103.34, 20.60, -103.26, 20.65]];
        foreach ($zonas as $nombre => [$x1, $y1, $x2, $y2]) {
            DB::insert('insert into zonas (nombre, area) values (?, ST_Multi(ST_GeomFromText(?, 4326))::geography)', [
                $nombre, "POLYGON(({$x1} {$y1}, {$x2} {$y1}, {$x2} {$y2}, {$x1} {$y2}, {$x1} {$y1}))",
            ]);
        }
    }

    /** @return array{0: Provider, 1: Provider} */
    private function comedores(): array
    {
        $crear = function (string $correo, string $contacto, string $apellido, string $negocio, string $marca, string $zona, float $lat, float $lon, CarbonImmutable $fin) {
            $usuario = $this->persona('provider', $contacto, $apellido, $correo);
            $comedor = Provider::create([
                'user_id' => $usuario->id, 'business_name' => $negocio, 'commercial_name' => $marca, 'phone' => '33 3000 '.random_int(1000, 9999), 'email' => $correo,
                'address' => "Calle de ejemplo {$marca} 100, {$zona}", 'postal_code' => '44100', 'zona' => $zona, 'latitude' => $lat, 'longitude' => $lon, 'daily_capacity' => 400, 'is_active' => true,
            ]);
            ContratoComedor::create(['comedor_id' => $comedor->id, 'precio_por_platillo' => 49, 'compra_minima_mensual' => 200,
                'vigencia_inicio' => $fin->subMonths(6)->addDay()->toDateString(), 'vigencia_fin' => $fin->toDateString()]);

            return $comedor;
        };

        return [
            $crear('comedor@easylunch.test', 'Laura', 'Gómez', 'Cocina Norte S.A. de C.V.', 'Cocina Norte', 'Zapopan', 20.72, -103.42, $this->hoy->addMonths(4)),
            // El contrato de este comedor vence pronto: aparece en «contratos por vencer».
            $crear('comedor.sur@easylunch.test', 'Andrés', 'Valdés', 'Sabor Casero Centro S.A.', 'Sabor Casero Centro', 'Centro', 20.675, -103.35, $this->hoy->addDays(20)),
        ];
    }

    /** @return array{0: Empresa, 1: Empresa} */
    private function empresas(): array
    {
        $crear = function (string $correo, string $nombre, string $apellido, string $empresa, string $dominio, string $zona, array $contrato) {
            $usuario = $this->persona('company', $nombre, $apellido, $correo);
            $e = Empresa::create(['usuario_id' => $usuario->id, 'nombre' => $empresa, 'dominio' => $dominio, 'zona' => $zona, 'direccion_entrega' => "Av. de la Industria 1450, {$zona}"]);
            ContratoEmpresa::create($contrato + ['empresa_id' => $e->id, 'precio_por_platillo' => 130, 'hora_entrega' => '12:40', 'ventana_entrega_inicio' => '12:30', 'ventana_entrega_fin' => '12:50']);

            return $e;
        };
        $contrato = fn (CarbonImmutable $fin, int $subsidio, int $minimo) => [
            'porcentaje_subsidio' => $subsidio, 'compra_minima_mensual' => $minimo, 'vigencia_inicio' => $fin->subMonths(6)->addDay()->toDateString(), 'vigencia_fin' => $fin->toDateString(),
        ];

        return [
            $crear('rrhh@nexo.test', 'Mariana', 'Ortega', 'Grupo Industrial Nexo', 'nexo.test', 'Zapopan', $contrato($this->hoy->addMonths(4), 60, 420)),
            // Vence en 12 días: genera el aviso de contrato por vencer a la empresa y al administrador.
            $crear('rrhh@pacifico.test', 'Ricardo', 'Salas', 'Logística Pacífico', 'pacifico.test', 'Centro',
                $contrato($this->hoy->addDays(12), 50, 300) + ['hora_entrega' => '13:10', 'ventana_entrega_inicio' => '13:00', 'ventana_entrega_fin' => '13:20']),
        ];
    }

    /** @return array<int, Empleado> */
    private function empleados(Empresa $nexo, Empresa $pacifico): array
    {
        $personas = [
            [$nexo, 'ana@nexo.test', 'Ana', 'Ruiz', 'Logística'], [$nexo, 'beto@nexo.test', 'Beto', 'Salas', 'Producción'],
            [$nexo, 'carla@nexo.test', 'Carla', 'Mendoza', 'Calidad'], [$nexo, 'diego@nexo.test', 'Diego', 'Fuentes', 'Ventas'],
            [$pacifico, 'elena@pacifico.test', 'Elena', 'Cruz', 'Almacén'], [$pacifico, 'fabian@pacifico.test', 'Fabián', 'Núñez', 'Rutas'],
        ];

        return array_map(fn ($p) => Empleado::create([
            'usuario_id' => $this->persona('employee', $p[2], $p[3], $p[1])->id, 'empresa_id' => $p[0]->id, 'departamento' => $p[4],
        ]), $personas);
    }

    // ------------------------------------------------------------------ platillos y disponibilidad

    /** @return array<string, Menu> platillos por clave: ['norte_comida' => [...], ...] */
    private function menus(Provider $norte, Provider $sur): array
    {
        $crear = fn (Provider $c, string $nombre, string $descripcion, string $tiempo, int $cal, array $alergenos, array $etiquetas, string $estado = 'publicado', ?string $motivo = null) => Menu::create([
            'provider_id' => $c->id, 'name' => $nombre, 'description' => $descripcion, 'price' => 49, 'estado_revision' => $estado, 'motivo_rechazo' => $motivo,
            'tiempo_comida' => $tiempo, 'calorias' => $cal, 'alergenos' => $alergenos, 'etiquetas' => $etiquetas, 'is_available' => true,
        ]);

        return [
            'comida' => [
                $crear($norte, 'Pollo en mole con arroz', 'Pierna de pollo en mole poblano, arroz rojo y frijoles.', 'comida', 720, ['frutos secos', 'ajonjolí'], ['casero']),
                $crear($norte, 'Milanesa de res con ensalada', 'Milanesa empanizada, ensalada fresca y papas.', 'comida', 680, ['gluten', 'huevo'], []),
                $crear($norte, 'Enchiladas verdes', 'Tres enchiladas de pollo bañadas en salsa verde.', 'comida', 610, ['lácteos'], ['picante']),
                $crear($sur, 'Caldo de res', 'Caldo de res con verduras, arroz y tortillas.', 'comida', 540, [], ['ligero']),
                $crear($sur, 'Chiles rellenos de queso', 'Dos chiles poblanos rellenos con caldillo de jitomate.', 'comida', 650, ['lácteos', 'huevo', 'gluten'], ['vegetariano']),
            ],
            'desayuno' => [$crear($norte, 'Chilaquiles con huevo', 'Chilaquiles rojos con huevo estrellado.', 'desayuno', 520, ['huevo', 'lácteos'], [])],
            'cena' => [$crear($sur, 'Sopa de lentejas', 'Sopa de lentejas con verduras y pan integral.', 'cena', 380, ['gluten'], ['vegetariano', 'ligero'])],
            // Para probar la revisión del administrador y los estados del comedor:
            'revision' => $crear($norte, 'Tacos de cochinita pibil', 'Tres tacos con cebolla morada y habanero.', 'comida', 590, [], ['picante'], 'en_revision'),
            'borrador' => $crear($sur, 'Pozole rojo', 'Pozole con maíz cacahuazintle.', 'comida', 600, [], [], 'borrador'),
            'rechazado' => $crear($sur, 'Sándwich de jamón', 'Sándwich de jamón y queso.', 'comida', 450, ['gluten', 'lácteos'], [], 'rechazado', 'Falta la información de alérgenos y una foto.'),
        ];
    }

    private function disponibilidad(array $menus): void
    {
        $publicados = array_merge($menus['comida'], $menus['desayuno'], $menus['cena']);
        $delDia = [$menus['comida'][0]->id, $menus['comida'][3]->id]; // el comedor marca un platillo del día por tiempo de comida

        foreach ($this->proximosDiasDeServicio(8) as $fecha) {
            foreach ($publicados as $menu) {
                DisponibilidadDia::create(['menu_id' => $menu->id, 'fecha' => $fecha->toDateString(), 'porciones_maximas' => 40, 'es_platillo_del_dia' => in_array($menu->id, $delDia, true)]);
            }
        }
    }

    /** @return array<int, CarbonImmutable> */
    private function proximosDiasDeServicio(int $cuantos): array
    {
        $fechas = [];
        for ($dia = $this->hoy->addDay(); count($fechas) < $cuantos; $dia = $dia->addDay()) {
            if ($this->calendario->esDiaDeServicio($dia)) {
                $fechas[] = $dia;
            }
        }

        return $fechas;
    }

    // ------------------------------------------------------------------ historial (para facturar, liquidar y repetir el último platillo)

    /** @param  array<int, Empleado>  $empleados */
    private function historial(array $empleados, array $menus): void
    {
        $inicioMesPasado = $this->hoy->subMonthNoOverflow()->startOfMonth();
        $fechas = [];
        // Los primeros 12 días de servicio del mes pasado (para facturar y liquidar)…
        for ($dia = $inicioMesPasado; $dia->month === $inicioMesPasado->month && count($fechas) < 12; $dia = $dia->addDay()) {
            if ($this->calendario->esDiaDeServicio($dia)) {
                $fechas[] = $dia;
            }
        }
        // …y los últimos 3 días de servicio de este mes antes de hoy (historial para «último platillo» y el avance de cada comedor).
        $recientes = [];
        for ($dia = $this->hoy->subDay(); $dia->gte($this->hoy->startOfMonth()) && count($recientes) < 3; $dia = $dia->subDay()) {
            if ($this->calendario->esDiaDeServicio($dia)) {
                array_unshift($recientes, $dia);
            }
        }
        $fechas = array_merge($fechas, $recientes);

        $contratos = ContratoEmpresa::query()->get()->keyBy('empresa_id');
        $platillosComida = $menus['comida'];

        foreach ($fechas as $i => $fecha) {
            $porEmpresa = [];
            foreach ($empleados as $n => $empleado) {
                $menu = $platillosComida[($n + $i) % count($platillosComida)];
                $contrato = $contratos[$empleado->empresa_id];
                DB::table('elecciones_empleado')->insert([
                    'empleado_id' => $empleado->id, 'fecha' => $fecha->toDateString(), 'tiempo_comida' => 'comida', 'menu_id' => $menu->id,
                    'precio_congelado' => $contrato->precio_por_platillo, 'porcentaje_subsidio_congelado' => $contrato->porcentaje_subsidio,
                    'origen' => ($n + $i) % 4 === 0 ? 'por_defecto' : 'manual', 'cerrada_en' => $fecha->subDay()->setTime(18, 0)->toDateTimeString(),
                ]);
                $porEmpresa[$empleado->empresa_id][] = $menu;
            }
            foreach ($porEmpresa as $empresaId => $lista) {
                DB::table('entregas_empresa')->insert([
                    'empresa_id' => $empresaId, 'fecha' => $fecha->toDateString(), 'platillos' => count($lista), 'ventana_inicio' => $contratos[$empresaId]->ventana_entrega_inicio,
                    'ventana_fin' => $contratos[$empresaId]->ventana_entrega_fin, 'estado' => 'entregada',
                ]);
            }
            foreach (collect($porEmpresa)->flatten(1)->groupBy('id') as $lista) {
                DB::table('ordenes_produccion')->insert([
                    'comedor_id' => $lista[0]->provider_id, 'menu_id' => $lista[0]->id, 'fecha' => $fecha->toDateString(), 'porciones_totales' => $lista->count(),
                    'porciones_empresas' => $lista->count(), 'porciones_individuales' => 0, 'hora_recoleccion' => '11:00', 'estado' => 'lista',
                ]);
            }
            Corte::create(['fecha_entrega' => $fecha->toDateString(), 'ejecutado_en' => $fecha->subDay()->setTime(18, 0), 'estado' => 'correcto', 'sin_cambios' => false,
                'elecciones_cerradas' => count($empleados), 'ordenes_generadas' => count($porEmpresa), 'entregas_consolidadas' => count($porEmpresa)]);
        }
    }

    /** Algunos empleados ya eligieron para la próxima entrega; los demás quedarán con la regla «sin elección» al hacer el corte. */
    private function eleccionesParaLaProximaEntrega(EleccionDePlatillos $elecciones, array $empleados, array $menus): void
    {
        $fecha = $this->calendario->fechaDeEntregaParaPedidoNuevo();
        foreach ([0, 1, 4] as $n) { // Ana, Beto y Elena
            $elecciones->elegir($empleados[$n], $fecha, 'comida', $menus['comida'][$n % 3]);
        }
    }

    // ------------------------------------------------------------------ reparto y clientes

    private function repartidores(): void
    {
        foreach ([['repartidor@easylunch.test', 'Héctor', 'Jiménez', 'R-001', 'JAL-123-A', 'Zapopan'], ['repartidor2@easylunch.test', 'Paola', 'Reyes', 'R-002', 'JAL-456-B', 'Centro']] as [$correo, $nombre, $apellido, $numero, $placas, $zona]) {
            Repartidor::create(['usuario_id' => $this->persona('driver', $nombre, $apellido, $correo)->id, 'numero_empleado' => $numero, 'placas' => $placas, 'zona_base' => $zona]);
        }
    }

    private function clientes(ContratacionDePaquetes $paquetes, array $menus): void
    {
        $paquete = [
            Paquete::create(['nombre' => 'Un día', 'dias' => 1, 'descuento_porcentaje' => 0, 'detalle' => 'Prueba un día de servicio']),
            Paquete::create(['nombre' => 'Dos días', 'dias' => 2, 'descuento_porcentaje' => 5, 'detalle' => '5 % de descuento']),
            Paquete::create(['nombre' => 'Semana', 'dias' => 6, 'descuento_porcentaje' => 12, 'detalle' => 'De lunes a sábado, 12 % de descuento', 'destacado' => true]),
        ];

        $uno = $this->persona('customer', 'Cecilia', 'Navarro', 'cliente@easylunch.test');
        $dos = $this->persona('customer', 'Daniel', 'Ibarra', 'cliente2@easylunch.test');
        $casa = Address::create(['user_id' => $uno->id, 'label' => 'Casa', 'street_address' => 'Av. Vallarta 1248', 'internal_number' => '4B', 'postal_code' => '44100',
            'latitude' => 20.676, 'longitude' => -103.347, 'delivery_instructions' => 'Portón negro, casa verde', 'is_default' => true]);
        Address::create(['user_id' => $uno->id, 'label' => 'Oficina CDMX (sin cobertura)', 'street_address' => 'Paseo de la Reforma 222', 'latitude' => 19.4326, 'longitude' => -99.1332]);
        $trabajo = Address::create(['user_id' => $dos->id, 'label' => 'Trabajo', 'street_address' => 'Av. Patria 1500', 'postal_code' => '45110',
            'latitude' => 20.72, 'longitude' => -103.42, 'is_default' => true]);

        $platillos = $menus['comida'];
        // Un pedido ya pagado (entra al corte y a la ruta) y otro por pagar (para probar el pago y el tiempo restante).
        $pagado = $paquetes->pedir($uno, $paquete[1], $casa, [$platillos[0], $platillos[1]]);
        $paquetes->pagar($pagado, 'tarjeta', '4821');
        $paquetes->pedir($dos, $paquete[0], $trabajo, [$platillos[2]]);

        $ticket = Ticket::create(['folio' => 'T-0001', 'cliente_id' => $uno->id, 'pedido_id' => $pagado->id, 'asunto' => '¿Puedo cambiar mi platillo del segundo día?', 'mensajes_sin_leer' => 1]);
        MensajeTicket::create(['ticket_id' => $ticket->id, 'autor_id' => $uno->id, 'autor_tipo' => 'cliente', 'texto' => 'Hola, ¿puedo cambiar el platillo del segundo día? Soy alérgica al huevo.']);
    }

    private function variosDeSoporteYBitacora(User $admin): void
    {
        Incidencia::create(['fecha' => $this->hoy->toDateString(), 'tipo' => 'empresa_cerrada', 'descripcion' => 'La recepción de Logística Pacífico no abrió a la hora de la entrega.', 'prioridad' => 'inmediata', 'hora' => '12:45']);
        Incidencia::create(['fecha' => $this->hoy->toDateString(), 'tipo' => 'otro', 'descripcion' => 'Un empleado reportó una charola golpeada.', 'prioridad' => 'normal']);

        // Un festivo futuro (no domingo) para ver el calendario.
        $festivo = $this->hoy->addDays(14);
        $festivo = $festivo->isSunday() ? $festivo->addDay() : $festivo;
        DiaSinServicio::create(['fecha' => $festivo->toDateString(), 'tipo' => 'festivo', 'motivo' => 'Día festivo de ejemplo']);

        MensajeContacto::create(['nombre' => 'Patricia Méndez', 'correo' => 'patricia@autobajio.test', 'empresa' => 'Autopartes del Bajío', 'mensaje' => 'Quisiéramos una cotización para 80 empleados.']);

        Bitacora::registrar($admin, 'configuracion.actualizada', 'configuracion', 'operacion', 'Datos de ejemplo cargados.');
        Bitacora::registrar(null, 'corte.ejecutado', 'cortes', 'ejemplo', 'Corte automático de ejemplo.');
    }
}
