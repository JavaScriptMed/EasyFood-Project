# Matriz de cobertura — App EasyLunch (Android)

Referencia: `docs/API-PARA-ALBERTO.md`. Base: `https://easylunch-api.yahirdev.dev/api/` (sin cambios).

> **Estado: FINAL de esta iteración (24 nov 2026).** La versión inicial (antes de los cambios) se resume al final.
> Pruebas en emulador Pixel_9 (Android 16, API 37) contra el servidor real. Las cuentas las inició la persona
> (la contraseña no la escribió el asistente).

Leyenda de estado: **completo** = pantalla + endpoint + estados de carga/vacío/error · **completo (sin probar)** = construido y compilado, falta probar en vivo (motivo en la columna de evidencia) · **bloqueado** = depende del backend.

## Todos los roles

| Endpoint | Pantalla o acción | Estado | Archivo | Evidencia |
|---|---|---|---|---|
| `POST /api/auth/login` | Login; `user.role` decide la pantalla de inicio (customer / employee / driver / otros → "Esta cuenta usa el panel web") | completo | `ui/auth/AuthScreens.kt`, `MainActivity.kt` | cliente.app y empleado.ejemplo entran cada uno a su flujo. Errores `{message, errors}` se muestran legibles |
| `GET /api/auth/profile` | Mi perfil; recupera el rol si la sesión guardada no lo tenía | completo | `ui/shared/Compartidas.kt`, `ui/auth/SesionViewModel.kt` | Perfil de empleado.ejemplo visto en emulador |
| `PUT /api/auth/profile` (multipart, avatar ≤ 2 MB jpg/png/webp) | Editar nombre, apellido, teléfono y foto | completo (sin probar guardar) | `Compartidas.kt`, `SesionViewModel.kt` | Se envía `POST` + `_method=PUT`; ya no hay `Content-Type` global. No se guardó para no alterar las cuentas de prueba |
| `POST /api/auth/logout` | "Cerrar sesión" (borra el token local aunque falle la red) | completo | `SesionViewModel.cerrarSesion` | Probado con la cuenta de cliente → vuelve al login |
| `POST /api/v1/cuentas/restablecer/solicitar` + `GET/POST …/restablecer/{token}` | "Olvidé mi contraseña" | **bloqueado por backend** para customer y driver; completo para empleados | `AuthScreens.kt` (`OlvideScreen`) | La pantalla avisa: "¿Eres cliente o repartidor? Pide ayuda a administración…". No revela si la cuenta existe |
| `GET /api/v1/configuracion/operacion` | `hora_corte` en textos y cuentas regresivas | completo | `SesionViewModel.cargarConfiguracion` | — |
| Manejo general | 401 → login · 403 rol → mensaje · 404 · 422 `{mensaje,codigo,errores}` · 429 `Retry-After` (espera ≤ 20 s y reintenta 1 vez) · sin conexión | completo | `data/network/ApiResult.kt`, `RetrofitClient.kt` | Nunca se muestra JSON crudo ni `«` (se probó con `sin_porciones`) |

## Cliente (customer)

| Endpoint | Pantalla o acción | Estado | Archivo | Evidencia |
|---|---|---|---|---|
| `POST /api/auth/register` | Registro | completo (sin probar) | `AuthScreens.kt` | No se registraron cuentas de relleno (regla del proyecto) |
| `GET /api/v1/cliente/direcciones` | Perfil → Mis direcciones (zona o "Fuera de cobertura") | completo | `ui/cliente/ClienteHome.kt` | Lista con "Casa — Av. Fray Antonio Alcalde 10" |
| `POST/PUT /api/v1/cliente/direcciones` | Editor con mapa: buscador (Guadalajara), pin arrastrable o por toque, calle/colonia/CP/interior/referencias; siempre manda `latitud`+`longitud` | completo (sin probar el mapa) | `EditarDireccionScreen.kt`, `MapaMapbox.kt`, `data/geo/Geo.kt` | **Falta `MAPBOX_ACCESS_TOKEN`** en `~/.gradle/gradle.properties`: la pantalla muestra el aviso y no se rompe. `sin_cobertura=true` al guardar → aviso |
| `DELETE /api/v1/cliente/direcciones/{id}` | Eliminar con confirmación; `direccion_en_uso` con su mensaje | completo (sin probar) | `ClienteHome.kt` | No se borró nada para conservar datos |
| `GET /api/v1/paquetes` | Paso 1 del pedido | completo | `CrearPedidoScreen.kt` | Muestra "mega fan · 10 % de descuento" |
| `GET /api/v1/cliente/proxima-entrega` | "Se entrega el sábado 28 de noviembre · Cierra el viernes 27 a las 18:00" + cuenta regresiva | completo | `ClienteHome.kt` (`EncabezadoEntrega`) | Visto en emulador |
| `GET /api/v1/cliente/menu-semanal` | Menú de la semana y armado del pedido por día con su fecha real ("Lun 30 nov"); solo el platillo que hay ese día; "No hay platillo este día" si falta | completo | `ClienteHome.kt`, `CrearPedidoScreen.kt` | El servidor responde `"celdas":{}` los 6 días → la app avisa "Aún no se publica el menú de esta semana" |
| `POST /api/v1/cliente/pedidos` | Confirmar; `sin_porciones`/`platillo_no_disponible` marcan el platillo y dejan cambiar | completo (sin probar crear) | `CrearPedidoScreen.kt`, `ClienteViewModel.kt` | No se puede crear: no hay menú publicado |
| `GET /api/v1/cliente/pedidos` | Mis pedidos (vacío con explicación) | completo | `PedidosScreens.kt` | — |
| `GET /api/v1/cliente/pedidos/{id}` | Detalle: folio, estado, sábado, total del servidor, días con platillos, evidencia, reembolso, cuenta regresiva | completo (sin probar) | `PedidosScreens.kt` | Sin pedidos en la cuenta |
| `POST /api/v1/cliente/pedidos/{id}/pago` | "Pagar con tarjeta" (PaymentSheet); luego consulta `GET /pedidos/{id}` cada 3 s hasta `recibido`; 503/500 → "Intenta de nuevo en un momento" | completo (sin probar) | `PedidosScreens.kt`, `ClienteViewModel.kt` | Requiere un pedido; la tarjeta 4242 la escribe la persona |
| `POST …/calificaciones/entrega` y `/platillo` | Solo si `dias[].estado == "entregada"` y ≤ 14 días; error del servidor con su mensaje | completo (sin probar) | `PedidosScreens.kt` | Sin pedidos entregados |
| `GET /api/v1/calificaciones/menu-semanal` | Promedio de estrellas en el menú | completo | `ClienteHome.kt` | "El menú de esta semana aún no tiene calificaciones" |
| — | **"Cancelar pedido" eliminado** (no existe endpoint para el cliente) | completo | — | — |

## Empleado (employee)

| Endpoint | Pantalla o acción | Estado | Archivo | Evidencia |
|---|---|---|---|---|
| `GET /api/v1/empleado/menu` | "Mi comida": fecha (anterior/siguiente/"Próximo abierto"), desayuno/comida/cena, precio, empresa cubre, tú pagas, hora de entrega, abierto/cerrado **solo con `cerrado`/`motivo_cerrado` del servidor**, elección actual, fotos y vista Lista/Cuadrícula/Grande | completo | `ui/empleado/EmpleadoScreens.kt` | empleado.ejemplo: viernes 27 abierto (cierra jueves 26 18:00); miércoles 25 muestra "Ya pasó el corte" porque el servidor manda `cerrado:true` (ver hallazgo) |
| `PUT /api/v1/empleado/elecciones` | Elegir / cambiar (deshabilitado si cerrado o agotado; refresca desde el servidor) | completo (sin probar) | `EmpleadoScreens.kt`, `EmpleadoViewModel.kt` | No se cambió ninguna elección para no alterar datos del portal |
| `DELETE /api/v1/empleado/elecciones` | "Quitar mi elección" | completo (sin probar) | idem | idem |
| `GET /api/v1/empleado/elecciones?limite=30` | Historial (manual / por defecto, abierta/cerrada) | completo (sin probar en pantalla) | idem | — |
| `GET /api/v1/empleado/calificaciones/pendientes` + `POST …/entrega` y `…/platillo` | "Calificar" | completo (sin probar) | idem | — |

## Repartidor (driver)

| Endpoint | Pantalla o acción | Estado | Archivo | Evidencia |
|---|---|---|---|---|
| `GET/PATCH /api/v1/repartidor/disponibilidad` | Interruptor En línea / Fuera de línea (bloqueado con ruta en curso; `ruta_en_curso` con su mensaje) | completo | `ui/repartidor/RepartidorScreens.kt` | repartidor.ejemplo: GET muestra "En línea"; PATCH a fuera de línea y de vuelta a en línea, ambos aplicados por el servidor (quedó en línea, como estaba) |
| `GET /api/v1/repartidor/rutas?fecha=` | Lista por día (anterior/siguiente) y pantalla de ruta: encabezado oscuro, **mapa con paradas numeradas y trazo por calles** (Mapbox Directions), "Paradas de hoy" con ✓ / Siguiente / pendiente | completo (lista probada; detalle sin probar) | `RepartidorScreens.kt`, `MapaRuta.kt` | Del 24 al 29 nov el servidor responde `[]` → estado vacío "Sin rutas este día". El detalle no se pudo abrir: no hay rutas aprobadas. Sin token de Mapbox el mapa muestra aviso |
| `POST …/paradas/{id}/iniciar` | "Iniciar siguiente parada" (solo la siguiente; explica por qué está bloqueado) | completo (sin probar) | idem | — |
| `POST …/paradas/{id}/entregar` (multipart `foto`) | Cámara → vista previa → "Confirmar entrega" (JPEG ≤ 1600 px, < 5 MB) | completo (sin probar) | idem, `RepartidorViewModel.kt` | — |
| `POST …/paradas/{id}/no-entregada` | "No se pudo entregar" con motivo (3–255) | completo (sin probar) | idem | — |
| `ubicacion` / `telefono` | "Navegar" (`geo:`) y "Llamar" (`tel:`); se ocultan si vienen `null` | completo (sin probar) | idem | — |
| — | **No se construyó chat ni GPS** (los bocetos los mostraban; el backend no los tiene) | — | — | — |

## Pendientes del backend
1. **`GET /api/v1/zonas`** (polígonos para cualquier sesión): hoy responde **404**. La app ya llama `cargarZonas()`, falla con elegancia y valida con `sin_cobertura` al guardar. Cuando exista, dibuja los polígonos y bloquea "Guardar" fuera de zona sin cambios en la app.
2. **Corte del empleado cerrado antes de tiempo** (hallazgo, 24 nov 2026 02:14 hora de Guadalajara):
   `GET /v1/empleado/menu?fecha=2026-11-25&tiempo_comida=desayuno` → `"cerrado":true,"motivo_cerrado":"corte"`, y lo mismo para el 26. Según la regla, el corte del 25 es el martes 24 a las 18:00 y el del 26 el miércoles 25 a las 18:00. Por eso el menú sin parámetros salta al viernes 27. **Hay que revisar el servidor** (¿se ejecutó el corte manual?).
3. **Menú semanal vacío**: `menu-semanal?fecha_inicio=2026-11-30` trae `celdas: {}` los 6 días; sin eso ningún cliente puede pedir.
4. **`foto_url` en `menu-semanal`**: el empleado sí recibe la foto; el cliente no. La app ya la lee si el backend la agrega.
5. **Restablecer contraseña** para customer y driver (no se envía el correo).
6. **Rutas aprobadas para repartidor.ejemplo**: no hay ninguna del 24 al 29 nov. Para probar iniciar / entregar con foto / no entregada hace falta que el administrador apruebe una ruta de hoy para esa cuenta.

## Claves locales
- `STRIPE_PUBLISHABLE_KEY`: movida de `gradle.properties` del proyecto a `~/.gradle/gradle.properties`.
- `MAPBOX_ACCESS_TOKEN` (pk.): **falta**. Agregarla en `~/.gradle/gradle.properties` y recompilar.

## Diagnóstico inicial (resumen, antes de los cambios)
- Errores crudos (`errorBody()` con `«`) en todas las pantallas; sin manejo de 401/403/404/429.
- Interceptor forzaba `Content-Type: application/json` (rompía el avatar multipart).
- Solo existía el flujo de cliente; empleado y repartidor veían pantallas de cliente.
- Crear pedido usaba `/menus` (catálogo heredado, "Stock: 50") y rotulaba "Día 1".
- Direcciones con latitud/longitud escritas a mano; sin aviso de cobertura.
- "Cancelar pedido" inventado (solo en memoria) y "Paquete Estándar" por defecto.
- Tras pagar no se esperaba al webhook.
