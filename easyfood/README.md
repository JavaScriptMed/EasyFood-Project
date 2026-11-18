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

Corren contra la base `easylunch_pruebas` (nunca contra la de desarrollo: se borra y se recrea en cada corrida). `ConcurrenciaTest` lanza procesos de PHP en paralelo y rehace esa base con `migrate:fresh` al terminar (tarda unos 15 s). Fuera de Docker, apunta `DB_HOST` a tu PostgreSQL.

## Mapa de tablas

| Área | Tablas |
|---|---|
| Cuentas | `users`, `roles`, `user_profiles`, `addresses` (del equipo), `invitaciones` |
| Comedores | `providers` (= comedores), `contratos_comedor`, `menus` (= platillos: estado de revisión, alérgenos, tiempo de comida) |
| Empresas | `empresas`, `contratos_empresa`, `empleados` (los de las empresas cliente), `dias_sin_servicio` |
| Operación diaria | `disponibilidad_dia`, `elecciones_empleado`, `cortes`, `ordenes_produccion`, `entregas_empresa` |
| Reparto | `repartidores` (nuestro personal), `rutas`, `paradas`, `incidencias` |
| Clientes individuales | `paquetes`, `pedidos` (`comidas_por_dia`: 1 a 3; `con_extra`), `pedido_dias` (la entrega de un día), `pedido_dia_platillos` (un platillo por tiempo de comida, con su precio congelado), `tickets`, `mensajes_ticket` |
| Dinero | `facturas_mensuales`, `liquidaciones_comedor` |
| Sistema | `configuracion`, `registros_auditoria` (bitácora, solo se agrega), `mensajes_contacto`, `zonas`, `avisos`, `contadores_folio` |

Las reglas de negocio importantes están como restricciones de PostgreSQL (CHECK, índices parciales y triggers) y cada una tiene su prueba en `tests/Feature/Esquema`.
## API v1

Rutas en `routes/v1.php` (prefijo `/api/v1`), JSON en español y token Bearer de Sanctum (`POST /api/auth/login`). Los errores de `/api/v1/*` tienen siempre la forma `{ "mensaje": "…", "codigo": "…", "errores": { campo: […] } }` (`codigo` en reglas de negocio, `errores` al validar). Los campos coinciden con `/lib/types.ts` del front.

| Grupo (rol) | Prefijo | Construido | Solo ruta (501) |
|---|---|---|---|
| Panel de administración (`admin`; `support` en clientes, pedidos, tickets e incidencias) | `/admin` | empresas y comedores (lectura), **alta de empresa** (`POST /admin/empresas`), **alta de repartidor** (`POST /admin/repartidores`), renovar contratos, contratos por vencer, platillos (crear, editar, revisar), configuración, calendario, corte (y su vista previa), repartidores, rutas (listar, **generar**, aprobar), entregas, incidencias (listar, resolver, **candidatos y reasignar**), facturas, liquidaciones, reporte de proveedor, menú semanal, consumo de una empresa, clientes, pedidos, tickets, bitácora, **dashboard con 10 indicadores en tiempo real** (`GET /admin/dashboard`, solo `admin`) y costos operativos (listar, registrar, anular) | ninguno: todos los endpoints de la API v1 están construidos (ninguna ruta apunta a un controlador provisional; `RutasDeLaApiTest` lo comprueba) |
| Portal de empresas (`company`) | `/empresa` | perfil, empleados (ver, **agregar**, activar/desactivar), pedidos de mañana, consumo, vista previa del mes en curso, facturas, indicadores de RR. HH. (`GET /empresa/indicadores`) | |
| Portal de comedores (`provider`) | `/comedor` | perfil, platillos, disponibilidad, producción, pagos | imágenes |
| Portal de empleados (`employee`) | `/empleado` | menú, elegir/quitar platillo, historial, calificar la entrega y el platillo que recibió | |
| App móvil (`customer`) | `/cliente` | paquetes, menú semanal, direcciones, pedidos (pedir), pago con tarjeta (`POST /cliente/pedidos/{id}/pago`) | |
| Administración y soporte | `/admin/pedidos/{id}/reembolso` | reembolso total de un pedido pagado con Stripe | |
| Cualquier rol con sesión | `/configuracion/operacion`, `/paquetes`, `/calificaciones/menu-semanal` | lectura de la configuración de operación, de los paquetes y de la calificación semanal del menú | |
| Público | `/contacto`, `/cuentas`, `/webhooks/stripe` | formulario de contacto, aceptar una invitación y restablecer la contraseña (por enlace de correo), aviso de cobro de Stripe (se confía en su firma) | |
| Administración, empresa y comedor (cada uno dentro de lo suyo) | `/cuentas/usuarios-acceso`, `/cuentas/invitaciones` | ver quién tiene acceso, invitar y reenviar la invitación | |

Un endpoint «solo ruta» ya tiene su URL, su rol y su nombre, pero responde `501 { "codigo": "no_implementado" }` desde `NoImplementadoController` hasta que se construya; entonces solo se cambia su línea en `routes/v1.php`. `php artisan route:list --path=api/v1` muestra el mapa completo.

Cada portal solo ve lo suyo: la empresa, el comedor o el empleado se resuelven del usuario autenticado (`ContextoDelUsuario`).

## Probar la API a mano (Bruno) y datos de ejemplo

La carpeta [`bruno/`](bruno/README.md) es una colección de [Bruno](https://www.usebruno.com/) con 211 peticiones y 345 pruebas automáticas que cubren toda la API v1 (incluidos los pendientes, que deben responder 501). `DatosDeEjemploSeeder` carga empresas, comedores, empleados, platillos, historial, clientes y pedidos de ejemplo (solo desarrollo; la contraseña de las cuentas sale de `SEED_PASSWORD` en tu `.env`):

```bash
docker compose exec app php artisan db:seed --class=DatosDeEjemploSeeder
```

## Pagos con Stripe (modo de prueba)

El pedido de un cliente individual se paga con tarjeta. La app móvil llama a `POST /api/v1/cliente/pedidos/{id}/pago`, recibe el `client_secret` y cobra con el SDK de Stripe; cuando Stripe confirma, avisa a `POST /api/v1/webhooks/stripe`, que pasa el pedido a `recibido`. El pago simulado ya no existe como ruta.

**Claves.** Solo en tu `.env` (nunca en el código ni en el repositorio; `.env` está en `.gitignore`):

```
STRIPE_SECRET=sk_test_...        # Dashboard de Stripe → Developers → API keys (modo de prueba)
STRIPE_WEBHOOK_SECRET=whsec_...  # el que imprime `stripe listen` (abajo) o el del endpoint que crees en el Dashboard
```

Sin ellas, el pago y el webhook responden `503 pagos_no_configurados`. Después de cambiar el `.env`: `docker compose exec app php artisan config:clear`.

**Probar el webhook en local con Stripe CLI.**

1. Instala [Stripe CLI](https://docs.stripe.com/stripe-cli) y entra a tu cuenta de prueba: `stripe login`.
2. Con el backend encendido (`docker compose up -d`, puerto 8000), reenvía los eventos a tu máquina:
   ```bash
   stripe listen --forward-to http://localhost:8000/api/v1/webhooks/stripe --events payment_intent.succeeded,payment_intent.payment_failed,charge.refunded
   ```
   Imprime `Your webhook signing secret is whsec_...`: cópialo a `STRIPE_WEBHOOK_SECRET` y limpia la configuración (paso anterior). Deja esa terminal abierta.
3. Crea un pedido con Bruno (carpeta *04 · App móvil · clientes*, peticiones 11 y 13) o con la app. La respuesta de «Iniciar el pago» trae `payment_intent_id` y `client_secret`.
4. Cobra ese intento con una tarjeta de prueba (sin la app):
   ```bash
   stripe payment_intents confirm pi_XXXX --payment-method pm_card_visa --return-url https://example.com
   ```
   (`pm_card_chargeDeclined` simula un pago rechazado.) En la terminal de `stripe listen` verás `payment_intent.succeeded → 200`, y el pedido pasa a `recibido` con `ultimos_digitos` `4242`.
5. Reenvía el mismo evento con `stripe events resend evt_XXXX`: responde 200 con `resultado: repetido` y no cambia nada.

`stripe trigger payment_intent.succeeded` también funciona, pero crea un cobro que no es de ningún pedido: queda como `ignorado`. Para ver lo que pasó, mira la tabla `stripe_eventos` y la bitácora (`pedido.pagado`, `pago.reembolso_manual`).

**Atajo para probar a mano (solo desarrollo local).** `docker compose exec app php artisan pagos:preparar-prueba` crea un cliente de prueba con un pedido en `pendiente_pago` y te imprime el id del pedido, un token del cliente, el `curl` de `POST /api/v1/cliente/pedidos/{id}/pago` ya armado y el comando `stripe payment_intents confirm … --payment-method pm_card_visa`. Con `--con-intento` también crea el PaymentIntent en Stripe (usa tu `STRIPE_SECRET`) y el comando de confirmación sale con el `pi_…` real; `--url=` cambia la dirección del backend. Necesita las zonas y los datos de ejemplo cargados, no escribe nada en el repo (el token solo se imprime en la terminal) y se niega a correr fuera de `APP_ENV=local`.

**Reembolsos.** Siempre son totales y no hay estado nuevo: un pedido reembolsado queda `cancelado` con `reembolsado_en`, `monto_reembolsado` (todo lo devuelto del cobro) y `stripe_refund_id`.

- **Cobro que no se puede aplicar (automático).** Si llega `payment_intent.succeeded` sobre un pedido cancelado o vencido, el sistema reembolsa el cobro completo en Stripe (llave de idempotencia por intento), anota `pago.reembolsado_automatico` en la bitácora y el pedido no cambia. Si Stripe falla, anota `pago.reembolso_manual` (con el error) para hacerlo a mano. Un monto distinto al del pedido, o un segundo cobro sobre un pedido ya pagado, no se reembolsan solos: quedan como `pago.reembolso_manual`, porque no se sabe cuál es el cobro bueno.
- **Por administración o soporte.** `POST /api/v1/admin/pedidos/{id}/reembolso` (solo `admin` y `support`): reembolsa lo que falta del cobro (nunca más de lo cobrado), cancela el pedido al momento y anota en la bitácora quién lo hizo (`pedido.reembolsado`). Una segunda llamada responde `422 pedido_ya_reembolsado`; un pedido sin pagar, `422 pedido_no_reembolsable`; si Stripe falla, `502 pasarela_con_error` y el pedido no cambia.
- **Webhook `charge.refunded`.** Cubre los reembolsos hechos desde el Dashboard de Stripe: si el pedido está pagado, pasa a `cancelado` con los datos del reembolso; si el endpoint ya lo hizo, no repite nada. Un reembolso parcial no cancela: queda `pago.reembolso_parcial` en la bitácora. Agrega `charge.refunded` a `--events` en `stripe listen` (arriba) y para probarlo: `stripe refunds create --payment-intent=pi_XXXX`.

**Reglas.** Se compara contra la hora del cargo, no la del webhook. Un pedido vencido no se puede empezar a pagar (`422 pedido_vencido`), pero el pago no lo cancela: lo cancela el corte. Si Stripe cobra algo que ya no se puede aplicar, el pedido no cambia y el cobro se reembolsa (ver Reembolsos). Las pruebas usan una pasarela falsa (`tests/Support/PasarelaFalsa.php`): ninguna llama a la red.

## Altas: empresa, comedor, repartidor, empleados y planes

- **Comedor** (`POST /admin/comedores`): crea el contacto (cuenta inactiva con invitación al portal de comedores), el comedor y su contrato de 6 meses con el precio por platillo y el mínimo mensual acordados. Razón social y RFC son opcionales (el RFC no se repite); la `zona` debe existir y el nombre no puede repetirse. No modifica el modelo `Provider`.
- **Plan** (`POST /admin/paquetes`, `PATCH /admin/paquetes/{id}/estado`): solo hay planes de 1, 2 o 6 días y **un solo plan activo por duración**; para cambiar el de una duración que ya existe se edita, o se desactiva y se crea otro. Un plan desactivado deja de ofrecerse y no se puede pedir; los pedidos que ya lo contrataron no cambian.
- **Empresa** (`POST /admin/empresas`): crea el contacto de RR. HH. (cuenta inactiva con invitación), la empresa y su contrato de 6 meses (`precio_por_platillo` $130 si no se manda). El nombre debe ser único y la `zona` debe existir en `zonas`; si no, el error lista las disponibles.
- **Repartidor** (`POST /admin/repartidores`): cuenta con invitación, `numero_empleado` automático (`R-001`…), placas obligatorias (solo se reparte en automóvil) y `zona_base` existente.
- **Empleados** (`POST /empresa/empleados`, hasta 500): cada persona queda inactiva hasta aceptar su invitación; sin correo se arma con el nombre y el dominio de la empresa; los correos repetidos o ya registrados se omiten y se devuelven en `omitidos`.

## Adjuntos en los tickets de soporte

`POST /admin/tickets/{id}/mensajes` acepta `texto`, un archivo `adjunto` (foto JPG, PNG o WebP, o PDF, de hasta 5 MB) o ambos; sin ninguno responde 422. Se guarda en `storage/app/public/tickets` con un nombre aleatorio de 40 caracteres (el nombre original solo se conserva, limpio, para mostrarlo) y el mensaje trae `adjunto: { url, nombre, tipo }`. Se rechazan SVG, scripts y cualquier otro tipo. Hace falta `php artisan storage:link` y correr `php artisan migrate` (agrega las columnas `adjunto_*` a `mensajes_ticket`).

## Fotos y precios de los platillos

- **Foto** (`POST /admin/platillos/{id}/imagen` y `POST /comedor/platillos/{id}/imagen`, multipart, campo `imagen`): JPG, PNG o WebP de hasta 2 MB (se rechazan SVG y cualquier otro archivo). Se guarda en `storage/app/public/platillos` con un nombre aleatorio y `foto_url` apunta a ella; la foto anterior se borra (las fotos externas no se tocan). El comedor solo cambia las de sus platillos, y un platillo publicado o rechazado vuelve a revisión, igual que al editarlo. Para servirlas hace falta `php artisan storage:link` una vez por instalación.
- **Historial de precios** (`GET /admin/platillos/{id}/historial-precios`): tabla `precios_de_platillos`, un tramo por precio con su costo (lo que se paga al comedor) de ese momento. `menus.price` sigue siendo el precio de hoy; la base impide dos tramos abiertos por platillo. Los platillos que ya existían arrancan con su precio actual al migrar.
- **Programar un precio** (`POST /admin/platillos/{id}/precios { precio_venta, vigente_desde }`, solo `admin`): desde mañana o después; el tramo de hoy queda vigente hasta el día anterior. Programar otro mientras hay uno pendiente lo reemplaza (nunca llegó a aplicarse). El comando `easylunch:aplicar-precios-programados` (00:05, ya en el programador de tareas) copia a `menus.price` el que entra en vigor. Editar el precio de un platillo (admin o comedor) también deja su tramo en el historial.

## Editar y desactivar

Nada se borra: «eliminar» es **desactivar** (`activo = false`, con su fecha) y se puede reactivar. Cada cambio queda en la bitácora.

| Qué | Endpoint | Quién | Reglas |
|---|---|---|---|
| Empresa | `PUT /admin/empresas/{id}`, `PATCH …/estado` | `admin` | nombre único, ventana de entrega válida, necesita un contrato activo; el precio por platillo acordado no cambia y lo ya elegido conserva su precio y subsidio |
| Empleado | `PUT /empresa/empleados/{id}` | `company` (los suyos) | nombre y departamento |
| Plan | `PUT /admin/paquetes/{id}` (también se crea y se desactiva: ver Altas) | `admin` | nombre único, descuento de 0 a 100; los días no cambian |
| Repartidor | `PUT /admin/repartidores/{id}`, `PATCH …/estado` | `admin` | solo automóvil con placas, zona existente, número de empleado único; no se desactiva con rutas por hacer (`repartidor_con_rutas`) |
| Capacidad del auto | `PUT /admin/repartidores/capacidades { automovil }` | `admin` | las rutas ya creadas conservan la suya |
| Persona con acceso | `PUT /cuentas/usuarios-acceso/{id}`, `PATCH …/estado` | `admin`, y cada empresa o comedor con la suya | el rol debe corresponder a la organización; nadie cambia ni desactiva su propia cuenta; el titular de una empresa o comedor no cambia de rol; no se activa a quien no aceptó su invitación; al desactivar o cambiar de rol se cierran sus sesiones |

| Comedor | `PUT /admin/comedores/{id}`, `PATCH …/estado` | `admin` | nombre único, zona existente, necesita contrato activo; precio y mínimo > 0 (el precio nuevo aplica a lo que se entregue desde ahora); el correo del contacto se corrige solo mientras no acepte su invitación; **desactivar** deja de ofrecer TODOS sus platillos sin cambiar el estado de cada uno (al reactivar vuelve todo) y no se puede con platillos por entregar (`comedor_con_pedidos`) |
| Platillo | `PATCH /admin/platillos/{id}/estado`, `PATCH /comedor/platillos/{id}/estado` | `admin`; el comedor solo los suyos | quita o vuelve a poner el platillo en la oferta (`is_available`); no se quita con elecciones o pedidos por entregar (`platillo_con_pedidos`) |

No se modificaron las clases `Provider` ni `Menu`: las reglas viven en `EdicionDeRegistros`.

## Corregir el correo

El correo es el acceso de la cuenta, así que solo se corrige **mientras la persona no haya aceptado su invitación** (un correo mal escrito, o el que el sistema armó al subir un CSV sin correos). Se puede mandar `correo` en `PUT /empresa/empleados/{id}`, `PUT /cuentas/usuarios-acceso/{id}` y `PUT /admin/empresas/{id}` (como `contacto_correo`). Se guarda en la cuenta y en la invitación, el enlace anterior deja de servir y se manda una invitación nueva al correo corregido. Si ya aceptó, responde 422 `correo_no_editable`; si el correo ya existe, `correo_en_uso`.

## Rutas e incidencias

- **Generar rutas** (`POST /admin/rutas/generar { fecha }`, solo `admin`): completa las rutas de una fecha **ya cortada** con lo que quedó sin repartidor (por ejemplo, después de dar de alta a uno). Usa el mismo `GeneradorDeRutas` que el corte: por zona, primero empresas y luego clientes, sin pasar la capacidad del auto. Nunca toca una ruta existente (borrador o aprobada), no duplica incidencias abiertas y cierra solas las de «falta de repartidores» de lo que ya tiene ruta. Rechaza una fecha sin corte (`corte_pendiente`), pasada o sin servicio.
- **Reasignar** (`GET /admin/incidencias/{id}/candidatos`, `POST /admin/incidencias/{id}/reasignar { repartidor_id, nota }`, admin y soporte): solo para una incidencia **abierta ligada a una ruta**. Es candidato el repartidor activo, disponible, distinto del actual y con capacidad libre ese día (auto menos lo que ya lleva); «alcanza» si le cabe toda la ruta. Al reasignar, la ruta cambia de repartidor (conserva su estado), la incidencia queda `reasignada` y se registra en la bitácora.

## Cuentas: invitaciones y contraseña

- **Invitar** (`POST /api/v1/cuentas/invitaciones`): administración invita a cualquier organización; una empresa, a la suya; un comedor, al suyo. Crea la cuenta **inactiva** y manda un correo con el enlace; la persona elige su contraseña al aceptar (`POST /cuentas/invitaciones/{token}/aceptar`, mínimo 8 caracteres con letras y números) y ya entra por el portal de su rol. La invitación vence a los 7 días; **reenviar** da un enlace nuevo (el anterior deja de servir). Quien acepta una invitación de empresa o comedor ve los datos de esa organización.
- **Restablecer** (`POST /cuentas/restablecer/solicitar`, `GET` y `POST /cuentas/restablecer/{token}`): responde siempre igual, exista o no el correo; el enlace vence a los 60 minutos, sirve una vez, y al usarlo se cierran las sesiones abiertas de esa cuenta. Solo reciben el correo las cuentas activas con portal web.
- **Del enlace solo se guarda el hash** (la base no tiene enlaces utilizables) y la API nunca lo devuelve: solo viaja por correo.
- **Correo en desarrollo:** `MAIL_MAILER=log` (no hace falta SMTP; tampoco hay Mailpit). El correo completo queda en `storage/logs/laravel.log`, pero codificado; por eso, **en local y en pruebas, cada enlace se escribe además solo y en una línea en `storage/logs/enlaces.log`**:
  ```bash
  docker compose exec app tail -f storage/logs/enlaces.log
  # [2026-11-17 11:58:37] local.INFO: INVITACIÓN para persona@easylunch.test
  # http://localhost:3000/admin/aceptar-invitacion?token=…
  ```
  Copia el enlace al navegador. Los enlaces apuntan a `FRONTEND_URL` (por defecto `http://localhost:3000`). **En producción nunca se escriben en ningún log** (permitirían entrar a la cuenta de otra persona); allí solo sale el correo, con el `MAIL_MAILER` real que se configure.

## Dashboard e indicadores

`GET /api/v1/admin/dashboard` (solo `admin`) devuelve el resumen del día, las alertas abiertas y los 10 indicadores, calculados al pedirlos (sin cachés ni tablas de resumen) en `App\Services\IndicadoresDelDashboard`. Las empresas ven los suyos en `GET /api/v1/empresa/indicadores` y cualquier rol con sesión, la calificación del menú en `GET /api/v1/calificaciones/menu-semanal`.

**Periodos.** «Semana» = de lunes a domingo de la semana en curso; «mes» = el mes calendario en curso. Las series son de 6 semanas.

| # | Indicador | Cómo se calcula |
|---|---|---|
| 1 | Platillos entregados por semana | Platillos de las entregas a empresas en estado `entregada` (lo mismo que cuenta la factura), por empresa y por día. |
| 2 | Calificación de la entrega | Promedio simple de puntualidad, trato del chofer y empaque (1 a 5) de lo calificado esa semana por empleados y clientes. |
| 3 | Calificación semanal del menú | Promedio simple de estrellas (1 a 5) al sabor y la calidad, también por cocina. |
| 4 | Paquete más popular | Pedidos individuales pagados del mes por paquete (1, 2 o 6 días); si empatan, gana el de menos días. |
| 5 | Ahorro estimado por empleado | Lo gastado en platillos entregados en el mes × el sobreprecio estimado de las apps de entrega (`ahorro_apps_porcentaje`, editable en Configuración; arranca en 30 %). |
| 6 | Incidencias o quejas | Incidencias de la semana de tipo `comida_fria`, `platillo_danado` (empaque dañado) y `entrega_tardia`. |
| 7 | Nuevos contratos B2B | Empresas cuyo **primer** contrato empezó este mes (las renovaciones no cuentan), con su mínimo mensual. |
| 8 | Balance semanal de utilidad | Por entrega: ingresos (precio de cada platillo entregado; a clientes, con el descuento de su paquete) − pago a cocinas (su precio de contrato) − costos operativos registrados. |
| 9 | Costo directo de maquila | Precio por platillo de cada cocina contra el tope `costo_maquila_limite` (arranca en $49); avisa si alguna lo excede. |
| 10 | Estatus de facturación B2B | Semáforo del mes más reciente con facturas: verde pagada, rojo emitida y pendiente, gris sin emitir. |

**Datos nuevos.** Calificaciones de la entrega y del platillo (1 a 5; solo de lo ya entregado, una vez por persona y dentro de 14 días, lo exige la base), costos operativos (se anulan, no se borran) y dos tipos nuevos de incidencia. Los empleados califican con `GET /empleado/calificaciones/pendientes`, `POST /empleado/calificaciones/entrega` y `/platillo`; los clientes, con `POST /cliente/pedidos/{id}/calificaciones/entrega` y `/platillo`. El administrador maneja los costos en `GET/POST /admin/costos-operativos` y `POST /admin/costos-operativos/{id}/anular`.

## Tareas automáticas

El servicio `scheduler` de docker-compose ejecuta `php artisan schedule:work` (con la base ya migrada). En producción basta un cron: `* * * * * php artisan schedule:run`.

| Comando | Cuándo | Qué hace |
|---|---|---|
| `easylunch:corte-automatico` | cada minuto | Ejecuta el corte de la siguiente fecha de entrega en cuanto llega su hora (18:00 por defecto). Solo corta días de servicio; no repite si ya hay corte (automático o manual). Si falla, reintenta cada 5 min hasta 12 veces. |
| `easylunch:cancelar-pedidos-vencidos` | cada minuto | Cancela los pedidos individuales que no se pagaron antes del corte de su primer día. |
| `easylunch:avisos-de-contratos` | diario 08:00 | Avisos de contratos que vencen este mes o en los 7 días siguientes. |

Cada comando decide por sí mismo si le toca, así que llamarlos de más no cambia nada. Un corte fallido queda registrado en `cortes` con su mensaje y en el log (`storage/logs`).

## Modelos Eloquent

Hay un modelo por tabla en `app/Models`. Los de tablas en español extienden `ModeloEasyLunch` (marcas `creado_en` / `actualizado_en`) y los que tienen `activo` usan el trait `Desactivable` (`activos()`, `desactivar()`, `reactivar()`; el DELETE lo impide la base). Los de las tablas del equipo conservan su nombre en inglés (`Provider` = comedor, `Menu` = platillo, `Address`, `CatalogItem`).
Montos y porcentajes salen como texto con 2 decimales (`decimal:2`), las fechas como `YYYY-MM-DD`, y `alergenos` / `etiquetas` (text[]) como arreglos de PHP con `App\Casts\ArregloPostgres`. Las ubicaciones PostGIS (`paradas.ubicacion`, `repartidores.ultima_ubicacion`) no se leen como atributo: se escriben y consultan con SQL de PostGIS.

## Reglas de negocio

Viven en `app/Services` y cada una tiene su prueba en `tests/Feature/Negocio`. Las infracciones lanzan `ReglaDeNegocioException` con un `codigo` estable (`corte_cerrado`, `sin_porciones`, `mes_sin_terminar`…).

| Servicio | Regla |
|---|---|
| `CalendarioDeServicio` | Se entrega de lunes a sábado (`dias_entrega`), domingo nunca. El corte es a `hora_corte` (18:00, hora de Guadalajara) del día calendario anterior. Un pedido nuevo va a la primera fecha de servicio con el corte abierto: viernes 17:00 → sábado, viernes 19:00 → lunes, domingo 19:00 → martes. |
| `EleccionDePlatillos` | Elegir, cambiar o quitar solo antes del corte (a las 18:00:00 ya está cerrado). Al elegir se congelan el precio y el subsidio del contrato de la empresa. Respeta porciones, publicación y tiempo de comida. |
| `EjecutorDelCorte` | Aplica la regla de quien no eligió, cierra elecciones, genera una orden por comedor y platillo, consolida una entrega por empresa y crea rutas por zona sin pasar la capacidad del auto. Idempotente: repetirlo deja un registro «sin_cambios». Si falla, no guarda nada. |
| `GeneradorDeFacturas` | Total = parte empresa + parte empleados + diferencia por mínimo. Solo cuentan entregas «entregada»; el mes debe haber terminado. |
| `GeneradorDeLiquidaciones` | Al comedor se le paga lo entregado más lo que faltó para su mínimo, a su precio fijo. |
| `ContratacionDePaquetes` | Paquetes de 1, 2 o 6 días de servicio con 1, 2 o 3 comidas por día (un platillo por tiempo de comida, los mismos tiempos todos los días, más un extra opcional por día); precio congelado por platillo, descuento del paquete. El pago de verdad lo confirma Stripe (`PagosDePedidos`); `pagar()` es un pago simulado interno, sin ruta, para el seeder y las pruebas. La dirección debe tener cobertura. Un pedido por pagar vence en el corte de su primer día (`vence_pago_en`, `segundos_para_pagar`) y se cancela. |
| `PagosDePedidos` | Pago con tarjeta con Stripe (modo de prueba). `iniciar()` crea un PaymentIntent por el total en MXN (solo pedido propio, por pagar y sin vencer) y no cambia el pedido; `procesarEvento()` atiende el webhook: idempotente (`stripe_eventos`), pasa el pedido a «recibido» con la hora del cargo y los últimos 4 dígitos, y procesa `charge.refunded`. Un pago fallido no cambia nada; un cobro sobre un pedido cancelado o vencido se reembolsa solo (el pedido no cambia); un monto distinto o un segundo cobro de un pedido ya pagado quedan en la bitácora (`pago.reembolso_manual`) para hacerlo a mano. Nunca se guardan datos de tarjeta. |
| `ReembolsosDePedidos` | Reembolso total de pedidos pagados con Stripe: por administración o soporte, automático para cobros que no se pueden aplicar, o hecho desde Stripe (`charge.refunded`). Sin estado nuevo (el pedido queda «cancelado» con `reembolsado_en`, `monto_reembolsado`, `stripe_refund_id`), sin doble reembolso y nunca más de lo cobrado. |
| `AvisosDeVencimiento` | Avisa a la empresa y a los administradores de los contratos que vencen en el mes en curso o en los 7 días siguientes (tabla `avisos`). |
| `Folios` | Consecutivos `EL-AAAAMM-NNN` y `GDL-AAAAMMDD-NNNN` sin repetirse aunque dos procesos pidan folio a la vez (tabla `contadores_folio`). |

**Zonas.** `zonas` guarda un polígono PostGIS por zona (lo carga el administrador; sin zonas cargadas ninguna dirección tiene cobertura). Al guardar una dirección la base calcula su punto (`ubicacion`) y su `zona`; si ninguna zona activa la cubre, `sin_cobertura` es verdadera. El nombre de la zona debe coincidir con `empresas.zona` y `repartidores.zona_base` para que compartan ruta (se comparan sin mayúsculas ni espacios sobrantes).

Los montos se calculan en centavos enteros (`Dinero`) o con `numeric` de PostgreSQL, nunca con decimales de punto flotante.

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
