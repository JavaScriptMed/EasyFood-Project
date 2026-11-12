# Colección de Bruno · EasyLunch API v1

Peticiones listas para probar el backend a mano y ver que funciona: **128 peticiones y 230 pruebas automáticas** (cada petición revisa su respuesta). Se hicieron con [Bruno](https://www.usebruno.com/) (gratis, guarda todo en archivos de texto, así que viaja con el repositorio).

## 1. Prepara la base de datos con datos de ejemplo

```bash
# 1) Elige una contraseña para las cuentas de ejemplo y ponla en tu .env (no se sube al repositorio)
SEED_PASSWORD=la-que-tu-quieras        # mínimo 8 caracteres

# 2) Levanta Docker, crea las tablas y carga los datos de ejemplo
docker compose up -d
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class=DatosDeEjemploSeeder
```

Para empezar de cero otra vez: `docker compose exec app php artisan migrate:fresh` y vuelve a cargar los datos. El seeder se niega a correr en producción.

### Qué trae

| Qué | Datos de ejemplo |
|---|---|
| Cuentas (todas con `SEED_PASSWORD`) | `admin@easylunch.test` · `soporte@easylunch.test` · `comedor@easylunch.test` (Cocina Norte) · `comedor.sur@easylunch.test` (Sabor Casero Centro) · `rrhh@nexo.test` (Grupo Industrial Nexo) · `rrhh@pacifico.test` · `ana@nexo.test` y 5 empleados más · `cliente@easylunch.test` · `cliente2@easylunch.test` · `repartidor@easylunch.test` · `repartidor2@easylunch.test` |
| Zonas | Centro, Zapopan y Tlaquepaque (rectángulos **aproximados**, solo de ejemplo) |
| Contratos | Nexo (subsidio 60 %, mínimo 420) y Pacífico (50 %, mínimo 300, **vence en 12 días**); comedores a $49 con mínimo de 200 (Sabor Casero **vence en 20 días**) |
| Platillos | 9 publicados, 1 en revisión, 1 borrador y 1 rechazado; con porciones y «platillo del día» para los próximos 8 días de servicio |
| Historial | 12 días de servicio del mes pasado entregados (para facturar y liquidar) y 3 días recientes (para «último platillo») |
| Próxima entrega | Ana, Beto y Elena ya eligieron; los demás los resuelve la regla «sin elección» al hacer el corte |
| Clientes | 3 paquetes (1, 2 y 6 días), 3 direcciones (una **sin cobertura**), un pedido pagado y otro por pagar, un ticket |
| Soporte | 2 incidencias abiertas, un festivo futuro, un mensaje de contacto |

Las fechas son relativas a hoy, así que el ejemplo siempre sirve.

## 2. Abre la colección en Bruno

1. Bruno → **Open Collection** → elige la carpeta `easyfood/bruno`.
2. Arriba a la derecha elige el entorno **Local** (⚙ *Configure* para ver sus variables).
3. En ese entorno escribe la contraseña en la variable secreta **`contrasena`** (la misma de `SEED_PASSWORD`).
4. Si tu API no está en `http://localhost:8000`, cambia `baseUrl` (el puerto sale de `APP_PORT` en tu `.env`).

## 3. Córrela en orden

Clic derecho en la colección → **Run**, o corre carpeta por carpeta. **Empieza siempre por «Sesión»**: inicia sesión con cada rol y guarda los tokens. Las carpetas van en el orden de un día real de operación:

| # | Carpeta | Qué ves |
|---|---|---|
| 00 | Sesión | Salud del API y login de cada rol |
| 01 | Administración · catálogo | Empresas, comedores, contratos por vencer, crear/editar/aprobar/rechazar platillos |
| 02 | Administración · configuración y calendario | Hora de corte, capacidad del auto, días sin servicio y cierres de empresa |
| 03 | Portal de empleados | Menú, elegir, cambiar y quitar platillo; el precio y el subsidio se congelan al elegir |
| 04 | App móvil · clientes | Paquetes, direcciones (zona automática y «sin cobertura») y pedidos con pago simulado |
| 05 | Portal de comedores | Perfil, platillos, porciones por día, pagos |
| 06 | **Corte y reparto** | **Ejecutar el corte**: cierra elecciones, órdenes de producción, entregas por empresa, rutas por zona e incidencias |
| 07 | Administración · dinero | Facturas a empresas y liquidaciones a comedores (total, partes y diferencia por mínimo) |
| 08 | Portal de empresas | Empleados (activar/desactivar) y facturas propias |
| 09 | Administración · clientes y soporte | Clientes, pedidos, tickets |
| 10 | Administración · bitácora | Todo lo que hicieron las carpetas anteriores |
| 11 | Público y errores de ejemplo | Contacto y ejemplos de 401, 403, 404 y 422 |
| 12 | Pendientes (501) | Endpoints configurados pero **todavía no construidos**: deben responder 501 |

Cada petición tiene su descripción (pestaña **Docs**) y sus pruebas (pestaña **Tests**; ✓ verde = respondió como se espera). Las respuestas salen en español y con la forma `{ mensaje, codigo, errores }`.

También desde la terminal (sin abrir Bruno), dentro de `easyfood/bruno`:

```bash
npx @usebruno/cli run --env Local --env-var contrasena=TU_CONTRASENA -r
```

## 4. Mira cómo cambian los datos

Conéctate a PostgreSQL con DBeaver o TablePlus: `127.0.0.1:5432`, base `easylunch`, usuario y contraseña de tu `.env`. Tablas para mirar mientras corres cada carpeta:

- **Elegir platillo** → `elecciones_empleado` (`precio_congelado`, `porcentaje_subsidio_congelado`) y `disponibilidad_dia.porciones_tomadas`.
- **Dirección** → `addresses` (`zona`, `sin_cobertura`, `ubicacion`).
- **Pedido** → `pedidos` (`vence_pago_en`) y `pedido_dias`.
- **Corte** → `cortes`, `ordenes_produccion`, `entregas_empresa`, `rutas`, `paradas`, `incidencias`.
- **Dinero** → `facturas_mensuales`, `liquidaciones_comedor`.
- **Todo lo que hace el panel** → `registros_auditoria` (bitácora).

## Cosas que conviene saber

- **El corte es a las 18:00 (hora de Guadalajara).** Se entrega de lunes a sábado. La colección calcula sola la próxima fecha con el corte abierto con la hora de tu computadora (supone que estás en hora de México). Si ya pasaron las 18:00, «mañana» ya está cerrado y se usa el siguiente día de servicio.
- **«Ejecutar corte ahora» cambia datos de verdad**: cierra la siguiente fecha. Si lo repites para la misma fecha responde «sin cambios»; si no pasas fecha, corta la siguiente que falte. Para volver a probar el flujo completo recarga los datos.
- **Facturas y liquidaciones**: «Emitir» y «Pagar» responden 200 la primera vez y 422 después (una factura emitida ya no cambia; es lo correcto, por eso esas pruebas aceptan ambos).
- **Límites**: el contacto y los endpoints públicos de `/cuentas` admiten 10 peticiones por minuto; si repites la carpeta «Pendientes» enseguida verás 429. Espera un minuto.
- **Roles**: `admin` entra a todo el panel; `soporte` solo a clientes, pedidos, tickets e incidencias.
- Los tokens se guardan como variables de la sesión de Bruno (no en el archivo); si cierras Bruno, vuelve a correr «Sesión».
