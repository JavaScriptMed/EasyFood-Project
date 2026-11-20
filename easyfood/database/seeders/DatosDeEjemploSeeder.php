<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\CatalogCategory;
use App\Models\CatalogItem;
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
            $this->indicadoresDelDashboard($empleados, $admin);
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

    /** @return array{0: Empresa, 1: Empresa, 2: Empresa} */
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
            // Firmó este mes (cuenta en «nuevos contratos B2B»); todavía no tiene empleados.
            $crear('rrhh@alameda.test', 'Lorena', 'Beltrán', 'Grupo Alameda', 'alameda.test', 'Centro', [
                'porcentaje_subsidio' => 50, 'compra_minima_mensual' => 150, 'vigencia_inicio' => $this->hoy->startOfMonth()->addDay()->toDateString(),
                'vigencia_fin' => $this->hoy->startOfMonth()->addDay()->addMonths(6)->subDay()->toDateString(),
            ]),
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
        $tipos = $this->catalogos();
        $crear = fn (Provider $c, string $tipo, string $nombre, string $descripcion, string $tiempo, int $cal, array $alergenos, array $etiquetas, string $estado = 'publicado', ?string $motivo = null) => Menu::create([
            'provider_id' => $c->id, 'category_id' => $tipos[$tipo]->id, 'name' => $nombre, 'description' => $descripcion, 'price' => 49, 'estado_revision' => $estado, 'motivo_rechazo' => $motivo,
            'tiempo_comida' => $tiempo, 'calorias' => $cal, 'alergenos' => $alergenos, 'etiquetas' => $etiquetas, 'is_available' => true,
        ]);

        return [
            'comida' => [
                $crear($norte, 'PLATO_FUERTE', 'Pollo en mole con arroz', 'Pierna de pollo en mole poblano, arroz rojo y frijoles.', 'comida', 720, ['frutos secos', 'ajonjolí'], ['casero']),
                $crear($norte, 'PLATO_FUERTE', 'Milanesa de res con ensalada', 'Milanesa empanizada, ensalada fresca y papas.', 'comida', 680, ['gluten', 'huevo'], []),
                $crear($norte, 'ANTOJITO', 'Enchiladas verdes', 'Tres enchiladas de pollo bañadas en salsa verde.', 'comida', 610, ['lácteos'], ['picante']),
                $crear($sur, 'SOPA_Y_CALDO', 'Caldo de res', 'Caldo de res con verduras, arroz y tortillas.', 'comida', 540, [], ['ligero']),
                $crear($sur, 'PLATO_FUERTE', 'Chiles rellenos de queso', 'Dos chiles poblanos rellenos con caldillo de jitomate.', 'comida', 650, ['lácteos', 'huevo', 'gluten'], ['vegetariano']),
            ],
            'desayuno' => [$crear($norte, 'ANTOJITO', 'Chilaquiles con huevo', 'Chilaquiles rojos con huevo estrellado.', 'desayuno', 520, ['huevo', 'lácteos'], [])],
            'cena' => [$crear($sur, 'SOPA_Y_CALDO', 'Sopa de lentejas', 'Sopa de lentejas con verduras y pan integral.', 'cena', 380, ['gluten'], ['vegetariano', 'ligero'])],
            // Para probar la revisión del administrador y los estados del comedor:
            'revision' => $crear($norte, 'ANTOJITO', 'Tacos de cochinita pibil', 'Tres tacos con cebolla morada y habanero.', 'comida', 590, [], ['picante'], 'en_revision'),
            'borrador' => $crear($sur, 'SOPA_Y_CALDO', 'Pozole rojo', 'Pozole con maíz cacahuazintle.', 'comida', 600, [], [], 'borrador'),
            'rechazado' => $crear($sur, 'SANDWICH', 'Sándwich de jamón', 'Sándwich de jamón y queso.', 'comida', 450, ['gluten', 'lácteos'], [], 'rechazado', 'Falta la información de alérgenos y una foto.'),
        ];
    }

    /**
     * Catálogos del equipo (catalog_categories / catalog_items).
     * Las categorías de platillo son las que se asignan a menus.category_id; los demás catálogos (tiempos de comida,
     * alérgenos y dietas) traen sus items y quedan disponibles para los formularios.
     *
     * @return array<string, CatalogCategory> categorías de platillo por código
     */
    private function catalogos(): array
    {
        $tipos = [];
        foreach (['PLATO_FUERTE' => 'Plato fuerte', 'SOPA_Y_CALDO' => 'Sopa y caldo', 'ANTOJITO' => 'Antojito', 'SANDWICH' => 'Sándwich', 'ENSALADA' => 'Ensalada', 'POSTRE' => 'Postre', 'BEBIDA' => 'Bebida'] as $codigo => $nombre) {
            $tipos[$codigo] = CatalogCategory::firstOrCreate(['code' => $codigo], ['name' => $nombre, 'is_active' => true]);
        }

        $catalogos = [
            'TIEMPOS_COMIDA' => ['Tiempos de comida', ['DESAYUNO' => 'Desayuno', 'COMIDA' => 'Comida', 'CENA' => 'Cena']],
            'ALERGENOS' => ['Alérgenos', ['GLUTEN' => 'Gluten', 'LACTEOS' => 'Lácteos', 'HUEVO' => 'Huevo', 'FRUTOS_SECOS' => 'Frutos secos', 'AJONJOLI' => 'Ajonjolí']],
            'DIETAS' => ['Dietas y etiquetas', ['VEGETARIANO' => 'Vegetariano', 'LIGERO' => 'Ligero', 'PICANTE' => 'Picante', 'CASERO' => 'Casero']],
        ];
        foreach ($catalogos as $codigo => [$nombre, $items]) {
            $categoria = CatalogCategory::firstOrCreate(['code' => $codigo], ['name' => $nombre, 'is_active' => true]);
            $orden = 0;
            foreach ($items as $clave => $etiqueta) {
                CatalogItem::firstOrCreate(['category_id' => $categoria->id, 'code' => $clave], ['label' => $etiqueta, 'sort_order' => ++$orden, 'is_active' => true]);
            }
        }

        return $tipos;
    }

    private function disponibilidad(array $menus): void
    {
        $publicados = array_merge($menus['comida'], $menus['desayuno'], $menus['cena']);
        $delDia = [$menus['comida'][0]->id, $menus['comida'][3]->id]; // el comedor marca un platillo del día por tiempo de comida

        foreach ($this->proximosDiasDeServicio(16) as $fecha) { // dos semanas: un pedido de clientes llega el sábado y se come la semana siguiente
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
        // …y las últimas 5 semanas de servicio, para las series semanales del dashboard.
        $ultimasSemanas = [];
        for ($dia = $this->hoy->subDay(); $dia->gte($this->hoy->subDays(35)); $dia = $dia->subDay()) {
            if ($this->calendario->esDiaDeServicio($dia)) {
                array_unshift($ultimasSemanas, $dia);
            }
        }
        $fechas = collect(array_merge($fechas, $recientes, $ultimasSemanas))->unique(fn ($f) => $f->toDateString())->sortBy(fn ($f) => $f->timestamp)->values()->all();

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

    // ------------------------------------------------------------------ datos para los indicadores del dashboard

    /** @param  array<int, Empleado>  $empleados */
    private function indicadoresDelDashboard(array $empleados, User $admin): void
    {
        mt_srand(2026); // las calificaciones de ejemplo salen siempre iguales
        $estrellas = fn () => [5, 5, 5, 4, 4, 4, 3, 2][mt_rand(0, 7)];

        // Calificaciones de lo ya entregado: la entrega (puntualidad, trato, empaque) y el platillo (sabor y calidad).
        $entregas = DB::table('entregas_empresa')->where('estado', 'entregada')->orderBy('fecha')->get();
        foreach ($entregas as $entrega) {
            $recibieron = DB::table('elecciones_empleado as el')->join('empleados as emp', 'emp.id', '=', 'el.empleado_id')
                ->where('emp.empresa_id', $entrega->empresa_id)->whereDate('el.fecha', $entrega->fecha)->get(['emp.usuario_id', 'el.menu_id']);
            foreach ($recibieron as $r) {
                if (mt_rand(1, 100) <= 65) {
                    DB::table('calificaciones_entrega')->insert(['calificador_id' => $r->usuario_id, 'entrega_empresa_id' => $entrega->id, 'puntualidad' => $estrellas(), 'trato_chofer' => $estrellas(), 'empaque' => $estrellas()]);
                }
                if (mt_rand(1, 100) <= 80) {
                    DB::table('calificaciones_platillo')->insert(['calificador_id' => $r->usuario_id, 'menu_id' => $r->menu_id, 'fecha' => $entrega->fecha, 'estrellas' => $estrellas()]);
                }
            }
        }

        // Quejas de servicio (comida fría, empaque dañado, entrega tardía): las de hace unos días ya se resolvieron.
        $tipos = [['comida_fria', 'La comida llegó fría.'], ['platillo_danado', 'El empaque llegó dañado.'], ['entrega_tardia', 'La entrega llegó fuera de la ventana.']];
        foreach ($entregas->filter(fn ($e, $i) => $i % 5 === 2)->values() as $n => $entrega) {
            [$tipo, $texto] = $tipos[$n % 3];
            $resuelta = CarbonImmutable::parse($entrega->fecha)->lt($this->hoy->subDays(3));
            DB::table('incidencias')->insert([
                'fecha' => $entrega->fecha, 'tipo' => $tipo, 'descripcion' => $texto, 'prioridad' => 'normal', 'estado' => $resuelta ? 'resuelta' : 'abierta',
                'entrega_empresa_id' => $entrega->id, 'resuelta_en' => $resuelta ? CarbonImmutable::parse($entrega->fecha)->addDay()->setTime(10, 0) : null,
            ]);
        }

        // Un par de quejas abiertas de la última entrega, para ver el conteo de la semana en curso.
        if ($ultima = $entregas->last()) {
            foreach ([['comida_fria', 'Un empleado reportó la comida fría.'], ['entrega_tardia', 'La entrega llegó 20 minutos después de la ventana.']] as [$tipo, $texto]) {
                DB::table('incidencias')->insert(['fecha' => $this->hoy->toDateString(), 'tipo' => $tipo, 'descripcion' => $texto, 'prioridad' => 'normal', 'entrega_empresa_id' => $ultima->id]);
            }
        }

        // Costos operativos de las últimas 6 semanas (reparto y empaque, y la nómina del mes pasado).
        for ($semana = 5; $semana >= 0; $semana--) {
            $lunes = $this->hoy->startOfWeek()->subWeeks($semana);
            $avance = $semana === 0 ? 0.3 : 1; // la semana en curso solo lleva una parte de sus gastos
            foreach ([['reparto', 'Combustible y mantenimiento de reparto', 1800 + 90 * (5 - $semana)], ['empaque', 'Charolas y bolsas', 560 + 40 * $semana]] as [$categoria, $descripcion, $monto]) {
                DB::table('costos_operativos')->insert(['fecha' => $lunes->addDay()->min($this->hoy)->toDateString(), 'categoria' => $categoria, 'descripcion' => $descripcion, 'monto' => round($monto * $avance), 'registrado_por' => $admin->id]);
            }
        }
        DB::table('costos_operativos')->insert(['fecha' => $this->hoy->subMonthNoOverflow()->endOfMonth()->toDateString(), 'categoria' => 'nomina', 'descripcion' => 'Coordinación de operación (mes pasado)', 'monto' => 2400, 'registrado_por' => $admin->id]);

        // Facturación B2B de dos meses atrás, ya cobrada una y pendiente la otra (para el semáforo del dashboard).
        $mes = $this->hoy->subMonthsNoOverflow(2)->startOfMonth();
        $emitida = $mes->addMonth()->addDay()->setTime(9, 0);
        foreach ([[$empleados[0]->empresa_id, 'EL-'.$mes->format('Ym').'-001', 450, 60, 'pagada'], [$empleados[4]->empresa_id, 'EL-'.$mes->format('Ym').'-002', 320, 50, 'emitida']] as [$empresaId, $folio, $platillos, $subsidio, $estado]) {
            $total = $platillos * 130;
            $empresa = (int) round($total * $subsidio / 100);
            DB::table('facturas_mensuales')->insert([
                'folio' => $folio, 'empresa_id' => $empresaId, 'mes' => $mes->toDateString(), 'platillos' => $platillos, 'parte_empresa' => $empresa, 'parte_empleados' => $total - $empresa, 'total' => $total,
                'estado' => $estado, 'emitida_en' => $emitida, 'pagada_en' => $estado === 'pagada' ? $emitida->addDays(6) : null,
            ]);
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
            Paquete::create(['nombre' => 'Un día', 'dias' => 1, 'descuento_porcentaje' => 0, 'detalle' => 'Un día, con 1, 2 o 3 comidas']),
            Paquete::create(['nombre' => 'Dos días', 'dias' => 2, 'descuento_porcentaje' => 5, 'detalle' => 'Dos días, con 1, 2 o 3 comidas · 5 % de descuento']),
            Paquete::create(['nombre' => 'Semana', 'dias' => 6, 'descuento_porcentaje' => 12, 'detalle' => 'De lunes a sábado, con 1, 2 o 3 comidas · 12 % de descuento', 'destacado' => true]),
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
        // Cecilia: 2 días con 2 comidas por día (desayuno y comida). Daniel: 1 día con las 3 comidas (desayuno, comida y cena) y un extra.
        $pagado = $paquetes->pedir($uno, $paquete[1], $casa, [
            ['desayuno' => $menus['desayuno'][0], 'comida' => $platillos[0]],
            ['desayuno' => $menus['desayuno'][0], 'comida' => $platillos[1]],
        ]);
        $paquetes->pagar($pagado, 'tarjeta', '4821');
        $paquetes->pedir($dos, $paquete[0], $trabajo, [['desayuno' => $menus['desayuno'][0], 'comida' => $platillos[2], 'cena' => $menus['cena'][0], 'extra' => $platillos[1]]]);

        // Más pedidos ya pagados (para el «paquete más popular»): 2 de dos días, 1 de un día y 1 semana.
        $pagarPedido = fn ($cliente, $direccion, Paquete $paq, array $dias) => $paquetes->pagar($paquetes->pedir($cliente, $paq, $direccion, $dias), 'tarjeta', '4242');
        $pagarPedido($dos, $trabajo, $paquete[1], [[$platillos[0]], [$platillos[1]]]);
        $pagarPedido($uno, $casa, $paquete[0], [$platillos[2]]);
        $pagarPedido($uno, $casa, $paquete[2], array_map(fn ($i) => $platillos[$i % 3], range(0, 5)));

        // Pedidos de las últimas semanas ya entregados (cuentan en el balance de utilidad y en las calificaciones de la entrega).
        foreach ([[$uno, $casa, $paquete[1], 10], [$dos, $trabajo, $paquete[0], 17], [$uno, $casa, $paquete[1], 24]] as $n => [$cliente, $direccion, $paq, $haceDias]) {
            $dias = $paq->dias;
            $fechas = [];
            for ($d = $this->hoy->subDays($haceDias); count($fechas) < $dias; $d = $d->addDay()) {
                if ($this->calendario->esDiaDeServicio($d)) {
                    $fechas[] = $d;
                }
            }
            $total = number_format(count($fechas) * 49 * (100 - (float) $paq->descuento_porcentaje) / 100, 2, '.', '');
            $pedidoId = DB::table('pedidos')->insertGetId([
                'folio' => 'GDL-'.$fechas[0]->format('Ymd').'-9'.str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT), 'cliente_id' => $cliente->id, 'paquete_id' => $paq->id, 'direccion_id' => $direccion->id,
                'fecha_inicio' => $fechas[0]->toDateString(), 'fecha_fin' => end($fechas)->toDateString(), 'total' => $total, 'estado' => 'completado', 'metodo_pago' => 'tarjeta', 'ultimos_digitos' => '4242',
                'pagado_en' => $fechas[0]->subDay()->setTime(12, 0),
            ]);
            foreach ($fechas as $fecha) {
                $diaId = DB::table('pedido_dias')->insertGetId(['pedido_id' => $pedidoId, 'fecha' => $fecha->toDateString(), 'estado' => 'entregada', 'entregada_en' => $fecha->setTime(13, 15)]);
                DB::table('pedido_dia_platillos')->insert(['pedido_dia_id' => $diaId, 'tiempo_comida' => 'comida', 'menu_id' => $platillos[$n % 3]->id, 'precio_congelado' => 49]);
                if ($n !== 1) {
                    DB::table('calificaciones_entrega')->insert(['calificador_id' => $cliente->id, 'pedido_dia_id' => $diaId, 'puntualidad' => 4 + $n % 2, 'trato_chofer' => 5, 'empaque' => 4]);
                }
            }
        }

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
