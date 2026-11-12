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

Todo funciona con el backend **excepto** lo que el backend todavía no construye. Esas funciones avisan con un mensaje claro («… todavía no está disponible en el servidor») o muestran su pantalla vacía:

- **Cuentas por correo:** invitar y reenviar invitaciones, aceptar invitación, restablecer contraseña.
- **Altas:** crear empresa, crear repartidor, agregar empleados.
- **Operación:** reasignar una incidencia (y ver candidatos) y regenerar rutas (las crea el corte).
- **Datos sin tabla todavía:** historial de precios (vacío) y las cifras del resumen del dashboard (ejemplo; las alertas sí son incidencias reales).

## Probar sin backend

`NEXT_PUBLIC_USAR_MOCK=true` en `.env.local` y reinicia `pnpm dev`: todo vuelve a los datos de ejemplo.
