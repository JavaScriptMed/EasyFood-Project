# EasyLunch · front (Next.js) conectado al backend

El front habla con el backend de Laravel (`../easyfood`, API en `/api/v1`). Con el backend encendido y datos cargados, todos los portales funcionan con datos reales.

## Levantarlo

```bash
# 1) Backend (carpeta easyfood/): base de datos, API y tareas automáticas
docker compose up -d
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed --class=DatosDeEjemploSeeder   # cuentas y datos de ejemplo (usa SEED_PASSWORD de tu .env)

# 2) Front (tu proyecto Next.js con estas carpetas app/, components/, lib/)
cp .env.example .env.local        # NEXT_PUBLIC_API_URL=http://localhost:8000
pnpm dev                          # http://localhost:3000
```

El backend solo acepta peticiones del front en `http://localhost:3000` (o `127.0.0.1:3000`); si usas otro puerto, agrégalo en `CORS_ALLOWED_ORIGINS` del `.env` del backend.

## Cuentas de ejemplo (contraseña = `SEED_PASSWORD`)

| Portal | Entra en | Correo |
|---|---|---|
| Administración | `/login` | `admin@easylunch.test` · `soporte@easylunch.test` |
| Empresas | `/empresas/login` | `rrhh@nexo.test` · `rrhh@pacifico.test` |
| Comedores | `/comedores/login` | `comedor@easylunch.test` · `comedor.sur@easylunch.test` |
| Empleados | `/empleados/login` | `ana@nexo.test` y `beto@`, `carla@`, `diego@nexo.test` |

Cada cuenta solo entra por su portal (por ejemplo, el administrador por `/empresas/login` recibe «no pertenece a este portal»).

## Cómo está conectado

| Archivo | Qué hace |
|---|---|
| `lib/config.ts` | `API_URL`, el modo (`USAR_MOCK`) y las fechas (hoy, mes actual, día de operación) |
| `lib/http.ts` | Cliente `fetch`: manda el token, convierte los errores `{ mensaje, codigo, errores }` en `ErrorApi` con el mensaje en español y, si la API responde 401, marca la sesión como expirada |
| `lib/sesion.ts` | Sesión en `sessionStorage` (token + rol, caduca a los 30 min sin actividad); al cerrar sesión revoca el token |
| `lib/auth.ts` | Login de cada portal contra `POST /api/auth/login` y revisión de que la cuenta sea de ese portal |
| `lib/api.ts` | Las 75 funciones que usan las pantallas, con la misma firma que antes. Adapta lo que responde el backend (ids a texto, campos que la maqueta tenía y el backend no) |
| `lib/api-mock.ts` + `lib/mock-data.ts` | La maqueta de antes; solo se usa con `NEXT_PUBLIC_USAR_MOCK=true` |

Las pantallas no cambiaron: siguen llamando a las mismas funciones de `@/lib/api`.

## Qué ya es real y qué falta

Las altas (incluidos comedores y planes), la foto y el historial de precios de los platillos, regenerar rutas, reasignar incidencias y editar o desactivar empresas, empleados, planes, repartidores, límite del auto y personas con acceso ya son reales. Todo funciona con el backend **excepto** lo que el backend todavía no construye. Esas funciones avisan con un mensaje claro («… todavía no está disponible en el servidor») o muestran su pantalla vacía:

- **Datos:** el dashboard es real (resumen, alertas y los 10 indicadores), el historial de precios también y las fotos de los platillos se suben de verdad (JPG, PNG o WebP, hasta 2 MB).

## Ventanas de crear, editar, eliminar y ver detalle

Las ventanas viven en `components/ventanas.tsx` (formulario, confirmación y panel de detalle) y cada pantalla las usa. En EasyLunch **nada se borra**: «eliminar» es **desactivar** (con confirmación) y se puede reactivar.

| Entidad | Crear | Editar | Desactivar | Ver detalle |
|---|---|---|---|---|
| Empresas (admin) | sí | sí | sí | sí |
| Comedores (admin) | sí | sí | sí | sí |
| Platillos (admin y comedor) | sí | sí | sí | sí |
| Precio de un platillo | programar | — | — | historial |
| Planes | sí | sí | sí | sí |
| Repartidores | sí | sí | sí | sí |
| Límites por vehículo | — | sí | — | sí |
| Empleados (empresa) | sí | sí | sí | sí |
| Usuarios con acceso | invitar | sí | sí | — |
| Facturas, pedidos de mañana, pagos al comedor | — | — | — | sí |
| Elección del empleado | sí | sí | quitar | sí |

**Ventanas listas, rutas del backend pendientes** (devuelven «… todavía no está disponible en el servidor»; las funciones ya existen en `lib/api.ts` y en la maqueta): `editarComedor`, `cambiarEstadoComedor` y `cambiarEstadoPlatillo`. Ya son reales la foto (`subirImagenPlatillo`), el historial y programar un precio. Ya son reales: editar platillo (`PUT …/platillos/{id}`) y quitar la elección (`DELETE /empleado/elecciones`).

## Soporte: adjuntar archivos

En el chat de un ticket (`/admin/soporte`) el clip abre el selector de archivos: foto (JPG, PNG, WebP) o PDF de hasta 5 MB. El archivo elegido se muestra sobre el cuadro de texto, con botón para quitarlo, y se puede enviar con o sin texto. Las fotos se ven dentro de la conversación y los PDF salen como enlace (`components/adjunto-de-ticket.tsx`).

## Dashboard e indicadores

- **Administrador (`/admin`):** debajo del resumen del día, la sección «Indicadores de la operación» con los 10 indicadores (platillos entregados, calificación de la entrega y del menú, paquete más popular, ahorro por empleado, quejas, contratos nuevos, costo de maquila, balance de utilidad y semáforo de facturación). El balance es una gráfica de ingresos contra costos con su utilidad y un botón «Ver como tabla»; el semáforo y el límite de maquila llevan ícono y texto, no solo color. Componentes en `components/indicadores.tsx`.
- **Costos operativos (`/admin/costos`):** registrar y anular los gastos que entran al balance (reparto, empaque, nómina…).
- **Configuración → Operación:** el sobreprecio de las apps de entrega (ahorro por empleado) y el tope del pago a cada cocina (arranca en $49).
- **Empresas (`/empresas`):** los indicadores de RR. HH.: platillos entregados por día (para conciliar la factura), ahorro por empleado, calificaciones, quejas y el semáforo de sus facturas.
- **Empleados (`/empleados`):** «Califica lo que recibiste»: la entrega (puntualidad, trato del chofer y empaque) y cada platillo, de 1 a 5 estrellas (`components/calificaciones.tsx`).

## Probar sin backend

`NEXT_PUBLIC_USAR_MOCK=true` en `.env.local` y reinicia `pnpm dev`: todo vuelve a los datos de ejemplo.
