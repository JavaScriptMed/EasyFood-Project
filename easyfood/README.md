# EasyLunch · backend

Laravel 13 + Sanctum + PostgreSQL con PostGIS. API JSON en `/api/v1`. Las reglas del proyecto están en `claude.md`.

## Entorno con Docker

Servicios: `app` (PHP-FPM), `nginx` (http://localhost:8000) y `db` (PostgreSQL 17 + PostGIS).

```bash
cp .env.example .env
# En .env: escribe tu DB_PASSWORD y, si hace falta, HOST_UID / HOST_GID (comandos `id -u` e `id -g`).

docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

- Salud de la API y de la base de datos: http://localhost:8000/api/v1/salud
- Base de datos desde tu equipo (DBeaver, TablePlus…): `127.0.0.1:5432`, con el usuario y la clave de tu `.env`.
- Apagar: `docker compose down` (los datos se conservan en el volumen `pgdata`; `docker compose down -v` los borra).
- Si ya tenías el volumen de la base de datos creado antes de esta configuración, crea la base de pruebas una vez:
  `docker compose exec db psql -U <DB_USERNAME> -d postgres -c "CREATE DATABASE easylunch_pruebas"`

**El backend requiere PostgreSQL.** Las migraciones usan CHECK, índices parciales, triggers y PostGIS; SQLite ya no está configurado en el proyecto, y con cualquier otro motor la primera migración falla con un mensaje que lo explica.

## Pruebas

```bash
docker compose exec app php artisan test
```

Corren contra la base `easylunch_pruebas` (nunca contra la de desarrollo: se borra y se recrea en cada corrida). Fuera de Docker, apunta `DB_HOST` a tu PostgreSQL.

## Mapa de tablas

| Área | Tablas |
|---|---|
| Cuentas | `users`, `roles`, `user_profiles`, `addresses` (del equipo), `invitaciones` |
| Comedores | `providers` (= comedores), `contratos_comedor`, `menus` (= platillos: estado de revisión, alérgenos, tiempo de comida) |
| Empresas | `empresas`, `contratos_empresa`, `empleados` (los de las empresas cliente), `dias_sin_servicio` |
| Operación diaria | `disponibilidad_dia`, `elecciones_empleado`, `cortes`, `ordenes_produccion`, `entregas_empresa` |
| Reparto | `repartidores` (nuestro personal), `rutas`, `paradas`, `incidencias` |
| Clientes individuales | `paquetes`, `pedidos`, `pedido_dias`, `tickets`, `mensajes_ticket` |
| Dinero | `facturas_mensuales`, `liquidaciones_comedor` |
| Sistema | `configuracion`, `registros_auditoria` (bitácora, solo se agrega), `mensajes_contacto` |

Las reglas de negocio importantes están como restricciones de PostgreSQL (CHECK, índices parciales y triggers) y cada una tiene su prueba en `tests/Feature/Esquema`.
## Modelos Eloquent

Hay un modelo por tabla en `app/Models`. Los de tablas en español extienden `ModeloEasyLunch` (marcas `creado_en` / `actualizado_en`) y los que tienen `activo` usan el trait `Desactivable` (`activos()`, `desactivar()`, `reactivar()`; el DELETE lo impide la base). Los de las tablas del equipo conservan su nombre en inglés (`Provider` = comedor, `Menu` = platillo, `Address`, `CatalogItem`).
Montos y porcentajes salen como texto con 2 decimales (`decimal:2`), las fechas como `YYYY-MM-DD`, y `alergenos` / `etiquetas` (text[]) como arreglos de PHP con `App\Casts\ArregloPostgres`. Las ubicaciones PostGIS (`paradas.ubicacion`, `repartidores.ultima_ubicacion`) no se leen como atributo: se escriben y consultan con SQL de PostGIS.

Al sembrar datos hay que ejecutar `php artisan db:seed --class=InitialDataSeeder` para crear los siete roles.

## Convenciones

- Zona horaria `America/Mexico_City` e idioma `es` (mensajes en `lang/es`).
- CORS permite el front en desarrollo (`CORS_ALLOWED_ORIGINS`, por defecto `http://localhost:3000`).
- Autenticación con tokens Bearer de Sanctum.
- Tablas, columnas y rutas nuevas en español y snake_case; montos en `numeric(10,2)` y fechas en `timestamptz`.
- Nada se borra: las entidades con desactivación lógica usan `activo` y `desactivado_en`.

---

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
