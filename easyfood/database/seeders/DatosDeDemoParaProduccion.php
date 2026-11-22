<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\ContratoComedor;
use App\Models\ContratoEmpresa;
use App\Models\Corte;
use App\Models\DisponibilidadDia;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\EntregaEmpresa;
use App\Models\Menu;
use App\Models\Paquete;
use App\Models\Provider;
use App\Models\Repartidor;
use App\Models\Ruta;
use App\Models\User;
use App\Services\Bitacora;
use App\Services\CalendarioDeServicio;
use App\Services\ContratacionDePaquetes;
use App\Services\EleccionDePlatillos;
use App\Services\GeneradorDeLiquidaciones;
use App\Services\GeneradorDeRutas;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Datos de DEMO para un servidor que sí está en producción: 3 comedores y 3 empresas con sus empleados, platillos publicados con
 * porciones, rutas aprobadas para la ventana pedida y al menos dos semanas de historial (pedidos, entregas, rutas, incidencias y
 * liquidaciones). Se carga con `php artisan easylunch:cargar-demo --confirmo`; este seeder NO se puede correr con `db:seed`
 * (el bloqueo de producción de {@see DatosDeEjemploSeeder} sigue intacto: aquí solo se reutiliza su lógica).
 *
 * Reglas:
 * - Todas las cuentas de demo son @demo.easylunch.test; nunca se toca ninguna otra (por ejemplo las *.prueba@easylunch.test).
 * - Se puede repetir sin duplicar ni borrar nada: si la demo ya está cargada, no hace nada. Todo va en una sola transacción.
 * - No toca lo que ya existe: reutiliza las zonas y los paquetes del servidor y solo crea lo que falta. Los contratos de la demo
 *   vencen dentro de varios meses (no disparan avisos de contratos por vencer a nadie).
 * - Orden con la API del repartidor: carga la demo DESPUÉS de migrar esa API. Si el historial ya existe cuando se aplica su migración, las
 *   restricciones nuevas de las paradas (evidencia y motivo) no validan las paradas ya entregadas y la migración falla.
 * - Las porciones llegan hasta 13 días después del fin de la ventana: un pedido de cliente llega el sábado y se come la semana siguiente.
 * - Para los días de la ventana cuyo corte sigue abierto deja las elecciones de los empleados, sus entregas y sus rutas aprobadas;
 *   los cortes los hace el sistema a su hora. Las rutas de clientes del sábado las arma el corte de individuales del viernes.
 */
class DatosDeDemoParaProduccion extends DatosDeEjemploSeeder
{
    public const DOMINIO = 'demo.easylunch.test';

    /**
     * Las zonas que usa la demo: las del servidor (Zapopan, Tlaquepaque y Guadalajara, importadas con su polígono real). Los rectángulos
     * solo sirven para `--crear-zonas` en un entorno sin zonas reales (desarrollo o pruebas).
     *
     * @var array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    public const ZONAS_DE_DEMO = ['Zapopan' => [-103.50, 20.66, -103.38, 20.80], 'Tlaquepaque' => [-103.34, 20.60, -103.26, 20.65], 'Guadalajara' => [-103.38, 20.65, -103.31, 20.70]];

    /** Si esta cuenta existe, la demo ya se cargó. */
    public const MARCADOR = 'comedor.norte@demo.easylunch.test';

    /** @var array<string, int> */
    private array $conteos = [];

    private User $admin;

    private string $desde;

    private string $hasta;

    public function run(CalendarioDeServicio $calendario, EleccionDePlatillos $elecciones, ContratacionDePaquetes $paquetes): void
    {
        throw new RuntimeException('Los datos de demo se cargan con: php artisan easylunch:cargar-demo --confirmo');
    }

    public function yaCargado(): bool
    {
        return User::query()->where('email', self::MARCADOR)->exists();
    }

    /**
     * @return array<string, int> lo que se creó, por concepto
     */
    public function cargar(string $contrasena, User $admin, string $desde, string $hasta, bool $crearZonas = false): array
    {
        $this->contrasena = $contrasena;
        $this->calendario = app(CalendarioDeServicio::class);
        $this->hoy = $this->calendario->ahora()->startOfDay();
        $this->admin = $admin;
        $this->desde = $desde;
        $this->hasta = $hasta;
        $this->conteos = [];

        $this->exigirQueNoChoque();
        $this->exigirZonas($crearZonas);

        DB::transaction(function () {
            // El historial inserta elecciones de días pasados, y en un servidor que ya opera esos días pueden tener su corte real:
            // el candado que lo impide se apaga solo dentro de esta transacción (si algo falla, todo se revierte, candado incluido).
            DB::unprepared('ALTER TABLE elecciones_empleado DISABLE TRIGGER elecciones_empleado_tras_corte');

            app(InitialDataSeeder::class)->run(); // los siete roles (insertOrIgnore)
            $this->crearZonasFaltantes();
            $comedores = $this->comedoresDeDemo();
            $empresas = $this->empresasDeDemo();
            $empleados = $this->empleadosDeDemo($empresas);
            $menus = $this->menusDeDemo($comedores);
            $repartidores = $this->repartidoresDeDemo();
            $this->disponibilidadDeDemo($menus);

            $this->historial($empleados, $menus);
            $this->rutasDelHistorial($repartidores, $empresas);
            $clientes = $this->clientesDeDemo();
            $this->pedidosDelHistorial($clientes, $menus, $repartidores);
            $this->calificacionesEIncidencias($empresas);
            $this->liquidaciones($comedores);

            $this->ventanaActiva($empleados, $menus, $repartidores, $clientes, $empresas);

            DB::unprepared('ALTER TABLE elecciones_empleado ENABLE TRIGGER elecciones_empleado_tras_corte');
            Bitacora::registrar($this->admin, 'datos_demo.cargados', 'configuracion', 'demo', 'Cargó los datos de demo (3 comedores, 3 empresas, historial y ventana activa).');
        });

        return $this->conteos;
    }

    /** Antes de crear nada: ningún nombre de la demo choca con una empresa o un comedor que ya exista (la base no permite repetirlos). */
    private function exigirQueNoChoque(): void
    {
        $empresas = ['Manufacturas Nexo', 'Pacífico Logística', 'Corporativo Alameda'];
        $comedores = ['Cocina La Estancia', 'Sazón del Centro', 'Fogón de Tlaquepaque'];
        $choques = array_merge(
            DB::table('empresas')->whereIn(DB::raw('lower(nombre)'), array_map('mb_strtolower', $empresas))->pluck('nombre')->all(),
            DB::table('providers')->where(fn ($q) => $q->whereIn(DB::raw('lower(commercial_name)'), array_map('mb_strtolower', $comedores)))->pluck('commercial_name')->all(),
            DB::table('users')->where('email', 'like', '%'.self::DOMINIO)->pluck('email')->all(),
        );
        if ($choques) {
            throw new RuntimeException('No se cargó nada: ya existen '.implode(', ', $choques).'. Cámbiales el nombre o la cuenta antes de cargar la demo.');
        }
    }

    private function contar(string $concepto, int $cuantos = 1): void
    {
        $this->conteos[$concepto] = ($this->conteos[$concepto] ?? 0) + $cuantos;
    }

    private function correo(string $local): string
    {
        return $local.'@'.self::DOMINIO;
    }

    // ------------------------------------------------------------------ zonas, comedores, empresas y personas

    /** @return array<int, string> las zonas de la demo que todavía no existen en el servidor */
    private function zonasFaltantes(): array
    {
        return array_values(array_filter(array_keys(self::ZONAS_DE_DEMO), fn ($nombre) => ! DB::table('zonas')->whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])->exists()));
    }

    /** La demo usa las zonas reales del servidor: si falta alguna se detiene antes de crear nada (salvo con --crear-zonas). */
    private function exigirZonas(bool $crearZonas): void
    {
        $faltan = $this->zonasFaltantes();
        if ($faltan && ! $crearZonas) {
            throw new RuntimeException('No se cargó nada: faltan las zonas '.implode(', ', $faltan).'. Impórtalas primero en el panel (Zonas → Importar varias), o usa --crear-zonas para crear unos rectángulos de ejemplo (solo para desarrollo).');
        }
    }

    /** Con --crear-zonas, las que falten se crean como rectángulos de ejemplo; las que ya existen no se tocan. */
    private function crearZonasFaltantes(): void
    {
        foreach ($this->zonasFaltantes() as $nombre) {
            $this->crearZona($nombre, self::ZONAS_DE_DEMO[$nombre]);
            $this->contar('zonas creadas');
        }
    }

    private function zona(string $nombre): string
    {
        return (string) DB::table('zonas')->whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])->value('nombre');
    }

    /** Un punto dentro de la zona (sea la de demo o la real del servidor): las direcciones de demo siempre tienen cobertura. */
    private function puntoDe(string $zona): object
    {
        return DB::selectOne('select ST_X(p::geometry) as lon, ST_Y(p::geometry) as lat from (select ST_PointOnSurface(area::geometry) as p from zonas where lower(nombre) = ? order by id limit 1) z', [mb_strtolower($zona)]);
    }

    /** @return array<int, Provider> */
    private function comedoresDeDemo(): array
    {
        $comedores = [];
        foreach ([
            ['norte', 'Laura', 'Gómez', 'Cocina La Estancia S.A. de C.V.', 'Cocina La Estancia', 'Zapopan', 20.72, -103.42],
            ['centro', 'Andrés', 'Valdés', 'Sazón del Centro S.A.', 'Sazón del Centro', 'Guadalajara', 20.675, -103.35],
            ['tlaquepaque', 'Rosa', 'Medina', 'Fogón de Tlaquepaque S.A. de C.V.', 'Fogón de Tlaquepaque', 'Tlaquepaque', 20.63, -103.30],
        ] as [$clave, $contacto, $apellido, $negocio, $marca, $zona, $lat, $lon]) {
            $correo = $this->correo('comedor.'.$clave);
            $usuario = $this->persona('provider', $contacto, $apellido, $correo);
            $comedor = Provider::create([
                'user_id' => $usuario->id, 'business_name' => $negocio, 'commercial_name' => $marca, 'phone' => '33 3000 '.random_int(1000, 9999), 'email' => $correo,
                'address' => "Calle {$marca} 100, {$zona}", 'postal_code' => '44100', 'zona' => $this->zona($zona), 'latitude' => $lat, 'longitude' => $lon, 'daily_capacity' => 400, 'is_active' => true,
            ]);
            ContratoComedor::create(['comedor_id' => $comedor->id, 'precio_por_platillo' => 49, 'compra_minima_mensual' => 200,
                'vigencia_inicio' => $this->hoy->subMonths(4)->toDateString(), 'vigencia_fin' => $this->hoy->addMonths(8)->toDateString()]);
            $comedores[] = $comedor;
            $this->contar('comedores');
        }

        return $comedores;
    }

    /** @return array<int, Empresa> */
    private function empresasDeDemo(): array
    {
        $empresas = [];
        foreach ([
            ['nexo', 'Mariana', 'Ortega', 'Manufacturas Nexo', 'Zapopan', 60, 420, '12:00', '12:20', '12:10'],
            ['pacifico', 'Ricardo', 'Salas', 'Pacífico Logística', 'Guadalajara', 50, 300, '13:00', '13:20', '13:10'],
            ['alameda', 'Lorena', 'Beltrán', 'Corporativo Alameda', 'Tlaquepaque', 55, 250, '13:30', '13:50', '13:40'],
        ] as [$clave, $nombre, $apellido, $razon, $zona, $subsidio, $minimo, $inicio, $fin, $hora]) {
            $usuario = $this->persona('company', $nombre, $apellido, $this->correo('rrhh.'.$clave));
            $empresa = Empresa::create(['usuario_id' => $usuario->id, 'nombre' => $razon, 'dominio' => "{$clave}.".self::DOMINIO, 'zona' => $this->zona($zona), 'direccion_entrega' => "Av. de la Industria 1450, {$zona}"]);
            ContratoEmpresa::create([
                'empresa_id' => $empresa->id, 'porcentaje_subsidio' => $subsidio, 'compra_minima_mensual' => $minimo, 'precio_por_platillo' => 130,
                'vigencia_inicio' => $this->hoy->subMonths(4)->toDateString(), 'vigencia_fin' => $this->hoy->addMonths(8)->toDateString(),
                'hora_entrega' => $hora, 'ventana_entrega_inicio' => $inicio, 'ventana_entrega_fin' => $fin,
            ]);
            $empresas[] = $empresa;
            $this->contar('empresas');
        }

        return $empresas;
    }

    /**
     * @param  array<int, Empresa>  $empresas
     * @return array<int, Empleado> cinco por empresa
     */
    private function empleadosDeDemo(array $empresas): array
    {
        $nombres = [
            ['Ana', 'Ruiz', 'Logística'], ['Beto', 'Salas', 'Producción'], ['Carla', 'Mendoza', 'Calidad'], ['Diego', 'Fuentes', 'Ventas'], ['Elena', 'Cruz', 'Almacén'],
        ];
        $empleados = [];
        foreach ($empresas as $i => $empresa) {
            foreach ($nombres as $n => [$nombre, $apellido, $departamento]) {
                // Nombres distintos por empresa para que se vea variado: rotan los apellidos.
                $apellidoDeEstaEmpresa = $nombres[($n + $i) % count($nombres)][1];
                $usuario = $this->persona('employee', $nombre, $apellidoDeEstaEmpresa, strtolower($nombre).'.'.strtolower($apellidoDeEstaEmpresa).'@'.$empresa->dominio);
                $empleados[] = Empleado::create(['usuario_id' => $usuario->id, 'empresa_id' => $empresa->id, 'departamento' => $departamento]);
                $this->contar('empleados');
            }
        }

        return $empleados;
    }

    // ------------------------------------------------------------------ platillos y porciones

    /**
     * @param  array<int, Provider>  $comedores
     * @return array{comida: array<int, Menu>, desayuno: array<int, Menu>, cena: array<int, Menu>}
     */
    private function menusDeDemo(array $comedores): array
    {
        $tipos = $this->catalogos();
        $crear = fn (Provider $c, string $tipo, string $nombre, string $descripcion, string $tiempo, int $cal, array $alergenos, array $etiquetas) => tap(Menu::create([
            'provider_id' => $c->id, 'category_id' => $tipos[$tipo]->id, 'name' => $nombre, 'description' => $descripcion, 'price' => 49, 'estado_revision' => 'publicado',
            'tiempo_comida' => $tiempo, 'calorias' => $cal, 'alergenos' => $alergenos, 'etiquetas' => $etiquetas, 'is_available' => true,
        ]), fn () => $this->contar('platillos publicados'));

        [$norte, $centro, $tlaquepaque] = $comedores;

        return [
            'comida' => [
                $crear($norte, 'PLATO_FUERTE', 'Pollo en mole con arroz', 'Pierna de pollo en mole poblano, arroz rojo y frijoles.', 'comida', 720, ['frutos secos', 'ajonjolí'], ['casero']),
                $crear($norte, 'PLATO_FUERTE', 'Milanesa de res con ensalada', 'Milanesa empanizada, ensalada fresca y papas.', 'comida', 680, ['gluten', 'huevo'], []),
                $crear($norte, 'ANTOJITO', 'Enchiladas verdes', 'Tres enchiladas de pollo bañadas en salsa verde.', 'comida', 610, ['lácteos'], ['picante']),
                $crear($centro, 'SOPA_Y_CALDO', 'Caldo de res', 'Caldo de res con verduras, arroz y tortillas.', 'comida', 540, [], ['ligero']),
                $crear($centro, 'PLATO_FUERTE', 'Chiles rellenos de queso', 'Dos chiles poblanos rellenos con caldillo de jitomate.', 'comida', 650, ['lácteos', 'huevo', 'gluten'], ['vegetariano']),
                $crear($centro, 'PLATO_FUERTE', 'Tinga de pollo con arroz', 'Tinga de pollo, arroz y frijoles de la olla.', 'comida', 630, [], ['casero']),
                $crear($tlaquepaque, 'PLATO_FUERTE', 'Birria de res', 'Birria de res con consomé, cebolla y limón.', 'comida', 700, [], ['picante']),
                $crear($tlaquepaque, 'ANTOJITO', 'Tacos dorados de papa', 'Cuatro tacos dorados de papa con crema y lechuga.', 'comida', 580, ['lácteos', 'gluten'], ['vegetariano']),
                $crear($tlaquepaque, 'ENSALADA', 'Ensalada de pollo y aguacate', 'Pollo a la plancha, aguacate, jitomate y vinagreta.', 'comida', 450, [], ['ligero']),
            ],
            'desayuno' => [
                $crear($norte, 'ANTOJITO', 'Chilaquiles con huevo', 'Chilaquiles rojos con huevo estrellado.', 'desayuno', 520, ['huevo', 'lácteos'], []),
                $crear($centro, 'ANTOJITO', 'Huevos a la mexicana', 'Huevos revueltos con jitomate, cebolla y chile, con frijoles.', 'desayuno', 430, ['huevo'], ['casero']),
                $crear($tlaquepaque, 'ANTOJITO', 'Molletes con pico de gallo', 'Molletes gratinados con frijoles y pico de gallo.', 'desayuno', 460, ['gluten', 'lácteos'], ['vegetariano']),
            ],
            'cena' => [
                $crear($norte, 'SANDWICH', 'Sándwich de pollo', 'Sándwich de pollo con aguacate y queso.', 'cena', 480, ['gluten', 'lácteos'], []),
                $crear($centro, 'SOPA_Y_CALDO', 'Sopa de lentejas', 'Sopa de lentejas con verduras y pan integral.', 'cena', 380, ['gluten'], ['vegetariano', 'ligero']),
                $crear($tlaquepaque, 'ENSALADA', 'Ensalada de atún', 'Atún, lechuga, elote y vinagreta de limón.', 'cena', 360, [], ['ligero']),
            ],
        ];
    }

    /** Las porciones de todos los platillos publicados: desde mañana (o desde el inicio de la ventana) hasta 13 días después de su fin. */
    private function disponibilidadDeDemo(array $menus): void
    {
        $publicados = array_merge($menus['comida'], $menus['desayuno'], $menus['cena']);
        $delDia = array_map(fn ($i) => $menus['comida'][$i]->id, [0, 3, 6]); // un platillo del día por comedor
        $inicio = CarbonImmutable::parse($this->desde)->max($this->hoy->addDay());
        $fin = CarbonImmutable::parse($this->hasta)->addDays(13);

        for ($dia = $inicio; $dia->lte($fin); $dia = $dia->addDay()) {
            if (! $this->calendario->esDiaDeServicio($dia)) {
                continue;
            }
            foreach ($publicados as $menu) {
                DisponibilidadDia::create(['menu_id' => $menu->id, 'fecha' => $dia->toDateString(), 'porciones_maximas' => 60, 'es_platillo_del_dia' => in_array($menu->id, $delDia, true)]);
                $this->contar('porciones por platillo y día');
            }
        }
    }

    // ------------------------------------------------------------------ repartidores y clientes

    /** @return array<string, Repartidor> uno por zona, por nombre de zona en minúsculas */
    private function repartidoresDeDemo(): array
    {
        $repartidores = [];
        foreach ([['zapopan', 'Héctor', 'Jiménez', 'Zapopan'], ['guadalajara', 'Paola', 'Reyes', 'Guadalajara'], ['tlaquepaque', 'Iván', 'Ortiz', 'Tlaquepaque']] as $i => [$clave, $nombre, $apellido, $zona]) {
            $usuario = $this->persona('driver', $nombre, $apellido, $this->correo('repartidor.'.$clave));
            $repartidores[$clave] = Repartidor::create(['usuario_id' => $usuario->id, 'numero_empleado' => 'DEMO-R'.($i + 1), 'placas' => 'DEMO-'.(100 + $i), 'zona_base' => $this->zona($zona)]);
            $this->contar('repartidores');
        }

        return $repartidores;
    }

    /** @return array<int, array{usuario: User, direccion: Address, zona: string}> */
    private function clientesDeDemo(): array
    {
        $clientes = [];
        foreach ([['cecilia', 'Cecilia', 'Navarro', 'Guadalajara', 'Av. Vallarta 1248'], ['daniel', 'Daniel', 'Ibarra', 'Zapopan', 'Av. Patria 1500'], ['elisa', 'Elisa', 'Montes', 'Tlaquepaque', 'Av. Revolución 800']] as [$clave, $nombre, $apellido, $zona, $calle]) {
            $usuario = $this->persona('customer', $nombre, $apellido, $this->correo('cliente.'.$clave));
            $punto = $this->puntoDe($zona);
            $direccion = Address::create(['user_id' => $usuario->id, 'label' => 'Casa', 'street_address' => $calle, 'postal_code' => '44100', 'latitude' => $punto->lat, 'longitude' => $punto->lon, 'delivery_instructions' => 'Timbre azul', 'is_default' => true]);
            $clientes[] = ['usuario' => $usuario, 'direccion' => $direccion, 'zona' => mb_strtolower($zona)];
            $this->contar('clientes');
        }

        return $clientes;
    }

    /** Los tres paquetes: usa los que ya existen en el servidor y crea solo los que faltan. @return array<int, Paquete> por días */
    private function paquetes(): array
    {
        $paquetes = [];
        foreach ([1 => ['Un día', 0, 'Un día, con 1, 2 o 3 comidas'], 2 => ['Dos días', 5, 'Dos días, con 1, 2 o 3 comidas · 5 % de descuento'], 6 => ['Semana', 12, 'De lunes a sábado, con 1, 2 o 3 comidas · 12 % de descuento']] as $dias => [$nombre, $descuento, $detalle]) {
            $paquetes[$dias] = Paquete::query()->where('dias', $dias)->where('activo', true)->first()
                ?? Paquete::create(['nombre' => $nombre, 'dias' => $dias, 'descuento_porcentaje' => $descuento, 'detalle' => $detalle, 'destacado' => $dias === 6]);
        }

        return $paquetes;
    }

    // ------------------------------------------------------------------ historial: rutas, pedidos, calificaciones, incidencias y liquidaciones

    private function evidencia(): string
    {
        return asset('demo/evidencia.png');
    }

    /** Si la API del repartidor ya está instalada (más columnas y estados), el historial cumple sus restricciones. @return array<string, mixed> */
    private function rutaCerrada(CarbonImmutable $inicio, CarbonImmutable $fin): array
    {
        return Schema::hasColumn('rutas', 'iniciada_en') ? ['estado' => 'completada', 'iniciada_en' => $inicio, 'completada_en' => $fin] : ['estado' => 'aprobada'];
    }

    /** @return array<string, mixed> */
    private function paradaCerrada(CarbonImmutable $momento, bool $entregada): array
    {
        if (! Schema::hasColumn('paradas', 'evidencia_foto_url')) {
            return ['estado' => $entregada ? 'entregado' : 'no_localizado'];
        }

        return ['estado' => $entregada ? 'entregado' : 'no_localizado', 'iniciada_en' => $momento->subMinutes(8), 'cerrada_en' => $momento]
            + ($entregada ? ['evidencia_foto_url' => $this->evidencia()] : ['motivo_no_entrega' => 'Nadie abrió la puerta']);
    }

    /**
     * Las entregas a empresas del historial salieron en rutas: una por zona y día, con su repartidor y sus paradas ya entregadas.
     *
     * @param  array<string, Repartidor>  $repartidores
     * @param  array<int, Empresa>  $empresas
     */
    private function rutasDelHistorial(array $repartidores, array $empresas): void
    {
        $capacidad = $this->calendario->capacidadDelAuto();
        $entregas = EntregaEmpresa::query()->with('empresa')->whereIn('empresa_id', array_map(fn ($e) => $e->id, $empresas))->where('estado', 'entregada')->whereNull('ruta_id')
            ->orderBy('fecha')->get()->groupBy(fn ($e) => $e->fecha->toDateString());

        foreach ($entregas as $fecha => $delDia) {
            $corte = Corte::query()->where('tipo', 'general')->where('sin_cambios', false)->where('estado', '<>', 'fallido')->whereDate('fecha_entrega', $fecha)->value('id');
            foreach ($delDia->groupBy(fn ($e) => mb_strtolower($e->empresa->zona)) as $zona => $lista) {
                $lista = $lista->sortBy('ventana_inicio')->values();
                $inicio = CarbonImmutable::parse($fecha.' '.$lista->first()->ventana_inicio);
                $fin = CarbonImmutable::parse($fecha.' '.$lista->max('ventana_fin'));
                $ruta = DB::table('rutas')->insertGetId([
                    'fecha' => $fecha, 'repartidor_id' => $repartidores[$zona]->id, 'corte_id' => $corte, 'zona' => $lista->first()->empresa->zona,
                    'ventana_inicio' => $lista->first()->ventana_inicio, 'ventana_fin' => $lista->max('ventana_fin'), 'capacidad_entregas' => $capacidad['entregas'], 'capacidad_platillos' => $capacidad['platillos'],
                    'entregas_asignadas' => $lista->count(), 'platillos_asignados' => $lista->sum('platillos'), 'hora_fin_estimada' => $lista->max('ventana_fin'), 'excede_bloque' => false,
                ] + $this->rutaCerrada($inicio->subMinutes(30), $fin));
                foreach ($lista as $orden => $entrega) {
                    DB::table('paradas')->insert([
                        'ruta_id' => $ruta, 'orden' => $orden + 1, 'entrega_empresa_id' => $entrega->id, 'nombre_destinatario' => $entrega->empresa->nombre, 'direccion' => $entrega->empresa->direccion_entrega,
                        'hora_estimada' => $entrega->ventana_inicio,
                    ] + $this->paradaCerrada(CarbonImmutable::parse($fecha.' '.$entrega->ventana_inicio)->addMinutes(5), true));
                    $entrega->update(['ruta_id' => $ruta]);
                }
                $this->contar('rutas del historial');
            }
        }
    }

    /**
     * Pedidos de clientes de los últimos sábados, ya entregados (todo el pedido llega junto el sábado y se come la semana siguiente), con
     * su ruta y sus paradas. Uno de ellos no se pudo entregar y dejó su incidencia.
     *
     * @param  array<int, array{usuario: User, direccion: Address, zona: string}>  $clientes
     * @param  array<string, Repartidor>  $repartidores
     */
    private function pedidosDelHistorial(array $clientes, array $menus, array $repartidores): void
    {
        $paquetes = $this->paquetes();
        $capacidad = $this->calendario->capacidadDelAuto();
        $sabados = [];
        for ($dia = $this->hoy->subDay(); count($sabados) < 3 && $dia->gte($this->hoy->subDays(35)); $dia = $dia->subDay()) {
            if ($dia->isSaturday() && $this->calendario->esDiaDeServicio($dia)) {
                array_unshift($sabados, $dia);
            }
        }

        $folio = 0;
        $produccion = []; // por sábado: menu_id => porciones
        foreach ($sabados as $s => $sabado) {
            $paradas = []; // por zona: filas para la ruta de ese sábado
            foreach ($clientes as $c => $cliente) {
                $paquete = $paquetes[[2, 1, 2][($c + $s) % 3]];
                $dias = $this->calendario->diasDeComidaDeUnPedido($paquete->dias, $sabado);
                $comidas = array_map(fn ($i) => $menus['comida'][($c * 3 + $s + $i) % count($menus['comida'])], array_keys($dias));
                $noSeEntrego = $s === 0 && $c === 1; // el más antiguo de un cliente
                $total = array_sum(array_map(fn ($m) => (float) $m->price, $comidas)) * (100 - (float) $paquete->descuento_porcentaje) / 100;

                $pedido = DB::table('pedidos')->insertGetId([
                    'folio' => 'GDL-'.$sabado->format('Ymd').'-8'.str_pad((string) ++$folio, 3, '0', STR_PAD_LEFT), 'cliente_id' => $cliente['usuario']->id, 'paquete_id' => $paquete->id,
                    'direccion_id' => $cliente['direccion']->id, 'fecha_entrega' => $sabado->toDateString(), 'fecha_inicio' => $dias[0]->toDateString(), 'fecha_fin' => end($dias)->toDateString(),
                    'total' => number_format($total, 2, '.', ''), 'estado' => $noSeEntrego ? 'recibido' : 'completado', 'metodo_pago' => 'tarjeta', 'ultimos_digitos' => '4242',
                    'pagado_en' => $sabado->subDays(3)->setTime(12, 0), 'comidas_por_dia' => 1, 'con_extra' => false,
                ]);
                $entregaId = DB::table('pedido_dias')->insertGetId(['pedido_id' => $pedido, 'fecha' => $sabado->toDateString(), 'estado' => $noSeEntrego ? 'no_localizado' : 'entregada']
                    + ($noSeEntrego ? [] : ['entregada_en' => $sabado->setTime(13, 15), 'evidencia_foto_url' => $this->evidencia()]));
                foreach ($dias as $i => $dia) {
                    DB::table('pedido_dia_platillos')->insert(['pedido_dia_id' => $entregaId, 'fecha' => $dia->toDateString(), 'tiempo_comida' => 'comida', 'menu_id' => $comidas[$i]->id, 'precio_congelado' => 49, 'es_extra' => false]);
                    $produccion[$sabado->toDateString()][$comidas[$i]->id] = ($produccion[$sabado->toDateString()][$comidas[$i]->id] ?? 0) + 1;
                }
                $paradas[$cliente['zona']][] = ['entrega' => $entregaId, 'cliente' => $cliente, 'platillos' => count($dias), 'noSeEntrego' => $noSeEntrego, 'pedido' => $pedido];
                if (! $noSeEntrego && $c !== 2) {
                    DB::table('calificaciones_entrega')->insert(['calificador_id' => $cliente['usuario']->id, 'pedido_dia_id' => $entregaId, 'puntualidad' => 4 + ($s + $c) % 2, 'trato_chofer' => 5, 'empaque' => 4]);
                }
                $this->contar('pedidos del historial');
            }

            foreach ($paradas as $zona => $lista) {
                $inicio = $sabado->setTime(12, 0);
                $ruta = DB::table('rutas')->insertGetId([
                    'fecha' => $sabado->toDateString(), 'repartidor_id' => $repartidores[$zona]->id, 'zona' => $this->zona($zona), 'ventana_inicio' => '12:00', 'ventana_fin' => '12:'.str_pad((string) (10 * count($lista)), 2, '0', STR_PAD_LEFT),
                    'capacidad_entregas' => $capacidad['entregas'], 'capacidad_platillos' => $capacidad['platillos'], 'entregas_asignadas' => count($lista), 'platillos_asignados' => array_sum(array_column($lista, 'platillos')),
                    'hora_fin_estimada' => '12:'.str_pad((string) (10 * count($lista)), 2, '0', STR_PAD_LEFT), 'excede_bloque' => false,
                ] + $this->rutaCerrada($inicio, $inicio->addMinutes(10 * count($lista) + 20)));
                foreach ($lista as $orden => $fila) {
                    $momento = $inicio->addMinutes(10 * ($orden + 1));
                    $parada = DB::table('paradas')->insertGetId([
                        'ruta_id' => $ruta, 'orden' => $orden + 1, 'pedido_dia_id' => $fila['entrega'], 'nombre_destinatario' => $fila['cliente']['usuario']->name, 'direccion' => $fila['cliente']['direccion']->street_address,
                        'hora_estimada' => $momento->format('H:i:s'),
                    ] + $this->paradaCerrada($momento, ! $fila['noSeEntrego']));
                    DB::update('update paradas set ubicacion = (select ubicacion from addresses where id = ?) where id = ?', [$fila['cliente']['direccion']->id, $parada]);
                    if ($fila['noSeEntrego']) {
                        DB::table('incidencias')->insert([
                            'fecha' => $sabado->toDateString(), 'tipo' => 'entrega_no_localizada', 'descripcion' => 'No se pudo entregar el pedido de '.$fila['cliente']['usuario']->name.': nadie abrió la puerta.',
                            'prioridad' => 'inmediata', 'estado' => 'resuelta', 'hora' => $momento->format('H:i:s'), 'ruta_id' => $ruta, 'pedido_dia_id' => $fila['entrega'],
                            'resuelta_en' => $sabado->addDay()->setTime(10, 0), 'nota' => 'Se reprogramó con la clienta.',
                        ]);
                        $this->contar('incidencias');
                    }
                }
                $this->contar('rutas del historial');
            }
        }

        // Las porciones de clientes de cada sábado se suman a las órdenes de producción de los comedores.
        foreach ($produccion as $fecha => $porMenu) {
            foreach ($porMenu as $menuId => $cantidad) {
                $existente = DB::table('ordenes_produccion')->where('menu_id', $menuId)->whereDate('fecha', $fecha)->first();
                if ($existente) {
                    DB::table('ordenes_produccion')->where('id', $existente->id)->update(['porciones_individuales' => $existente->porciones_individuales + $cantidad, 'porciones_totales' => $existente->porciones_totales + $cantidad]);
                } else {
                    DB::table('ordenes_produccion')->insert(['comedor_id' => Menu::query()->whereKey($menuId)->value('provider_id'), 'menu_id' => $menuId, 'fecha' => $fecha, 'porciones_totales' => $cantidad,
                        'porciones_empresas' => 0, 'porciones_individuales' => $cantidad, 'hora_recoleccion' => '10:30', 'estado' => 'lista']);
                }
            }
        }
    }

    /** Calificaciones de lo ya entregado y algunas quejas de servicio, solo de las entregas de las empresas de demo (nunca las de otras). */
    private function calificacionesEIncidencias(array $empresas): void
    {
        mt_srand(2026);
        $estrellas = fn () => [5, 5, 5, 4, 4, 4, 3, 2][mt_rand(0, 7)];
        $entregas = EntregaEmpresa::query()->whereIn('empresa_id', array_map(fn ($e) => $e->id, $empresas))->where('estado', 'entregada')->orderBy('fecha')->get();

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

        $tipos = [['comida_fria', 'La comida llegó fría.'], ['platillo_danado', 'El empaque llegó dañado.'], ['entrega_tardia', 'La entrega llegó fuera de la ventana.']];
        foreach ($entregas->filter(fn ($e, $i) => $i % 5 === 2)->values() as $n => $entrega) {
            [$tipo, $texto] = $tipos[$n % 3];
            $resuelta = CarbonImmutable::parse($entrega->fecha)->lt($this->hoy->subDays(3));
            DB::table('incidencias')->insert([
                'fecha' => $entrega->fecha, 'tipo' => $tipo, 'descripcion' => $texto, 'prioridad' => 'normal', 'estado' => $resuelta ? 'resuelta' : 'abierta',
                'entrega_empresa_id' => $entrega->id, 'resuelta_en' => $resuelta ? CarbonImmutable::parse($entrega->fecha)->addDay()->setTime(10, 0) : null,
            ]);
            $this->contar('incidencias');
        }
    }

    /** La liquidación del mes pasado de cada comedor (las primeras ya pagadas, la última pendiente). @param  array<int, Provider>  $comedores */
    private function liquidaciones(array $comedores): void
    {
        $mes = $this->hoy->subMonthNoOverflow()->startOfMonth();
        $generador = app(GeneradorDeLiquidaciones::class);
        foreach ($comedores as $i => $comedor) {
            $liquidacion = $generador->generar($comedor, $mes);
            if ($i < count($comedores) - 1) {
                $generador->marcarPagada($liquidacion);
            }
            $this->contar('liquidaciones');
        }
    }

    // ------------------------------------------------------------------ ventana activa

    /**
     * Lo que los portales ven vivo en la ventana pedida: por cada día de servicio cuyo corte sigue abierto, lo que eligieron los
     * empleados, la entrega a cada empresa y las rutas aprobadas; y los pedidos de clientes ya pagados para el próximo sábado.
     *
     * @param  array<int, Empleado>  $empleados
     * @param  array<string, Repartidor>  $repartidores
     * @param  array<int, array{usuario: User, direccion: Address, zona: string}>  $clientes
     * @param  array<int, Empresa>  $empresas
     */
    private function ventanaActiva(array $empleados, array $menus, array $repartidores, array $clientes, array $empresas): void
    {
        $elecciones = app(EleccionDePlatillos::class);
        $generador = app(GeneradorDeRutas::class);
        $contratos = ContratoEmpresa::query()->whereIn('empresa_id', array_map(fn ($e) => $e->id, $empresas))->get()->keyBy('empresa_id');

        for ($dia = CarbonImmutable::parse($this->desde)->max($this->hoy->addDay()); $dia->lte(CarbonImmutable::parse($this->hasta)); $dia = $dia->addDay()) {
            // Un día cuyo corte ya pasó (o que no es de servicio) lo atiende el sistema: no se toca.
            if (! $this->calendario->esDiaDeServicio($dia) || ! $this->calendario->corteAbierto($dia)) {
                continue;
            }
            $porEmpresa = [];
            foreach ($empleados as $n => $empleado) {
                $elecciones->elegir($empleado, $dia, 'comida', $menus['comida'][($n + $dia->dayOfYear) % count($menus['comida'])]);
                $porEmpresa[$empleado->empresa_id] = ($porEmpresa[$empleado->empresa_id] ?? 0) + 1;
                $this->contar('elecciones de empleados');
            }
            foreach ($porEmpresa as $empresaId => $platillos) {
                EntregaEmpresa::create([
                    'empresa_id' => $empresaId, 'fecha' => $dia->toDateString(), 'platillos' => $platillos, 'ventana_inicio' => $contratos[$empresaId]->ventana_entrega_inicio,
                    'ventana_fin' => $contratos[$empresaId]->ventana_entrega_fin, 'estado' => 'programada',
                ]);
                $this->contar('entregas a empresas');
            }

            // Las rutas se arman con la misma lógica del corte (sin crear el corte: las elecciones siguen abiertas hasta su hora) y se aprueban.
            $antes = Ruta::query()->whereDate('fecha', $dia->toDateString())->pluck('id')->all();
            $generador->generar(new Corte, $dia->toDateString(), true, false);
            $nuevas = Ruta::query()->whereDate('fecha', $dia->toDateString())->whereNotIn('id', $antes)->update(['estado' => 'aprobada']);
            $this->contar('rutas aprobadas', $nuevas);
        }

        $this->pedidosPagadosParaElProximoSabado($clientes, $menus);
    }

    /** Un pedido pagado por cliente para el próximo sábado (con la lógica real de pedidos); su ruta la arma el corte de individuales. */
    private function pedidosPagadosParaElProximoSabado(array $clientes, array $menus): void
    {
        $paquetes = $this->paquetes();
        $servicio = app(ContratacionDePaquetes::class);
        $comida = $menus['comida'];
        $pedidos = [
            [$paquetes[2], [[ 'comida' => $comida[0]], ['comida' => $comida[3]]]],
            [$paquetes[1], [['desayuno' => $menus['desayuno'][1], 'comida' => $comida[4]]]],
            [$paquetes[6], array_map(fn ($i) => ['comida' => $comida[$i % count($comida)]], range(0, 5))],
        ];
        foreach ($clientes as $c => $cliente) {
            [$paquete, $platillos] = $pedidos[$c];
            $servicio->pagar($servicio->pedir($cliente['usuario'], $paquete, $cliente['direccion'], $platillos), 'tarjeta', '4242');
            $this->contar('pedidos pagados para el próximo sábado');
        }
    }
}
