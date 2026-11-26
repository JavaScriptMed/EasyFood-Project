# API de EasyLunch para la app móvil (empleados, clientes y repartidores)

Documento para Alberto. Cubre los endpoints que usa la app móvil: **autenticación**, **empleado**, **cliente individual** y **repartidor**, más los pocos endpoints compartidos que cualquier persona con sesión puede leer.

> Cómo se armó: cada ruta se revisó contra `easyfood/routes/api.php` y `easyfood/routes/v1.php`, y su controlador y servicio. Los ejemplos de respuesta no son inventados: se obtuvieron llamando a la API real (código de `master`) con datos de ejemplo del seeder. Las respuestas largas están recortadas con `…`. Lo único que **no** se pudo llamar de verdad es el cobro con Stripe (para no crear cobros en la cuenta de pruebas): su respuesta está tomada del código y se marca así.

## Índice

1. [Cómo conectarse](#1-cómo-conectarse)
2. [Roles: qué puede hacer cada cuenta](#2-roles-qué-puede-hacer-cada-cuenta)
3. [Autenticación y cuentas](#3-autenticación-y-cuentas)
4. [Endpoints compartidos (cualquier rol con sesión)](#4-endpoints-compartidos-cualquier-rol-con-sesión)
5. [Empleado](#5-empleado)
6. [Cliente individual](#6-cliente-individual)
7. [Repartidor](#7-repartidor)
8. [Catálogo de códigos de error](#8-catálogo-de-códigos-de-error)
9. [Cosas a tener en cuenta](#9-cosas-a-tener-en-cuenta)
10. [Colección de ejemplos con curl](#10-colección-de-ejemplos-con-curl)

---

## 1. Cómo conectarse

### URL base

| Entorno | URL |
|---|---|
| Producción | `https://easylunch-api.yahirdev.dev` |
| Local (Docker) | `http://localhost:8000` |

Hay dos prefijos:

- `/api/auth/...` → registro, login, perfil y logout (código del equipo).
- `/api/v1/...` → todo lo demás (empleado, cliente, repartidor, cuentas, compartidos).

Estado de la API: `GET /api/v1/salud` (público, sin token). Responde `200` con `{"estado":"ok", "api":{…}, "base_de_datos":{…}}` o `503` si la base no responde.

### Cabeceras obligatorias

```
Accept: application/json
Content-Type: application/json      (menos al subir archivos: multipart/form-data)
Authorization: Bearer <TOKEN>       (todo menos login, registro y los endpoints públicos de cuentas)
```

**Siempre manda `Accept: application/json`.** Sin ella, Laravel puede responder con una redirección HTML (`302`) en vez de JSON cuando falla una validación.

### Login y token Bearer

1. `POST /api/auth/login` con correo y contraseña ([ver endpoint](#post-apiauthlogin)).
2. La respuesta trae `token` (un token personal de Sanctum) y `user.role`.
3. Guarda el token de forma segura en el teléfono y mándalo en cada petición: `Authorization: Bearer <TOKEN>`.
4. `POST /api/auth/logout` borra **ese** token en el servidor.

Detalles verificados:

- El token **no caduca solo** a menos que el servidor defina `SANCTUM_EXPIRATION` (`config/sanctum.php`). Si algún día caduca o se borra, la API responde `401`: manda a la persona al login.
- Cada login crea un token nuevo (no invalida los anteriores).
- `user.role` es el nombre del rol **en inglés**, tal como está en la base: `customer` (cliente), `employee` (empleado), `driver` (repartidor); también existen `admin`, `support`, `provider` y `company`, que no usa la app móvil.
- Una cuenta con `status` distinto de `active` (por ejemplo, una invitación sin aceptar) no puede entrar: login responde `403`.

### Formato de datos

- IDs numéricos (enteros).
- Fechas `AAAA-MM-DD` (por ejemplo `2026-11-23`). Momentos en ISO 8601 con zona: `2026-11-27T18:00:00-06:00`.
- Horas `HH:MM` (por ejemplo `12:30`).
- Zona horaria del sistema: `America/Mexico_City` (Guadalajara).
- Dinero: número en **pesos MXN** con hasta 2 decimales (`130`, `93.1`). Nunca se calcula dinero en la app: el servidor manda todos los montos.
- Los nombres de campos van en español y `snake_case`.

### Formato de errores

Hay **dos formas**, según el prefijo. Esto es importante porque `/api/auth` es código del equipo y conserva el formato de Laravel.

**`/api/v1/...`** → `{ mensaje, codigo?, errores? }`:

```json
// Regla de negocio (HTTP 422): "codigo" es estable, úsalo en la app
{ "mensaje": "El corte de ese día ya pasó (jueves 19 de noviembre, 18:00): la elección ya no se puede cambiar.", "codigo": "corte_cerrado" }

// Validación de campos (HTTP 422): "errores" por campo
{ "mensaje": "El campo tiempo de comida es obligatorio. (and 1 more error)",
  "errores": { "tiempo_comida": ["El campo tiempo de comida es obligatorio."], "platillo_id": ["El campo platillo es obligatorio."] } }
```

**`/api/auth/...`** → `{ message, errors? }` (Laravel):

```json
{ "message": "Las credenciales proporcionadas son incorrectas.", "errors": { "email": ["Las credenciales proporcionadas son incorrectas."] } }
```

Códigos HTTP comunes:

| HTTP | Cuándo | Cuerpo |
|---|---|---|
| `401` | Sin token, token inválido o borrado | En `/api/v1`: `{"mensaje":"Necesitas iniciar sesión para continuar.","codigo":"no_autenticado"}`. En `/api/auth/profile` y similares: `{"message":"Unauthenticated."}` |
| `403` | Rol equivocado | `{"message":"Acceso denegado: No cuentas con los permisos requeridos.","user_role":"employee","required_roles":["driver"]}` (ojo: lleva `message`, no `mensaje`) |
| `403` | Cuenta sin ficha de empleado o repartidor, o sin permiso | `{"mensaje":"…","codigo":"sin_permiso"}` |
| `404` | No existe, o existe pero es de otra persona | `{"mensaje":"No encontramos lo que buscas.","codigo":"no_encontrado"}` |
| `422` | Regla de negocio (con `codigo`) o validación (con `errores`) | ver arriba |
| `429` | Demasiados intentos | `{"mensaje":"Demasiados intentos: espera un momento y vuelve a intentarlo.","codigo":"demasiados_intentos"}` + cabecera `Retry-After` |
| `503` | Pagos con tarjeta sin configurar | `{"mensaje":"…","codigo":"pagos_no_configurados"}` |

### Límites de intentos

- Login: 5 por minuto por correo+IP y 30 por minuto por IP.
- Registro: 10 por hora por IP.
- «Olvidé mi contraseña» (`restablecer/solicitar`): 3 por hora por correo y 10 por hora por IP.
- Resto de endpoints públicos de `/api/v1/cuentas/*`: 10 por minuto.

---

## 2. Roles: qué puede hacer cada cuenta

| Rol (`user.role`) | Quién | Cómo se crea la cuenta | Endpoints |
|---|---|---|---|
| `customer` | Cliente individual | Ella misma: `POST /api/auth/register` | `/api/v1/cliente/*` |
| `employee` | Empleado de una empresa | RR. HH. o el administrador lo da de alta: nace **inactiva** con una invitación; entra al aceptarla | `/api/v1/empleado/*` |
| `driver` | Repartidor | El administrador lo da de alta: igual, invitación | `/api/v1/repartidor/*` |

Cada grupo de rutas exige su rol: con un token de otro rol responde `403`. Los endpoints de [§4](#4-endpoints-compartidos-cualquier-rol-con-sesión) y de [§3](#3-autenticación-y-cuentas) sirven a cualquiera con sesión.

---

## 3. Autenticación y cuentas

### `POST /api/auth/register`

- **Rol:** público (crea siempre un **cliente**, rol `customer`).
- **Límite:** 10 por hora por IP.

Cuerpo:

```json
{
  "name": "Luis Pérez",
  "email": "luis.perez@ejemplo.test",
  "password": "<CONTRASEÑA_DE_8+_CARACTERES>",
  "first_name": "Luis",
  "last_name": "Pérez",
  "phone": "33 1234 5678"
}
```

`name` (máx. 120), `email` (único, máx. 150), `password` (mín. 8), `first_name` y `last_name` (máx. 100) son obligatorios; `phone` (máx. 20) es opcional.

Respuesta `201`:

```json
{
  "message": "Usuario registrado exitosamente.",
  "token": "<TOKEN>",
  "user": {
    "id": 19, "name": "Luis Pérez", "email": "luis.perez@ejemplo.test", "role": "customer", "status": "active",
    "profile": { "id": 19, "user_id": 19, "first_name": "Luis", "last_name": "Pérez", "phone": "33 1234 5678", "avatar_url": null, "bio": null, "birth_date": null, "created_at": "2026-11-22T18:46:46.000000Z", "updated_at": "2026-11-22T18:46:46.000000Z" }
  }
}
```

Errores (formato `/api/auth`): `422` con `errors` (por ejemplo correo repetido: `"El valor de correo electrónico ya está en uso."`), `429`.

### `POST /api/auth/login`

- **Rol:** público. **Límite:** 5 por minuto por correo+IP.

Cuerpo: `{ "email": "ana@nexo.test", "password": "<CONTRASEÑA>" }`

Respuesta `200` (misma forma que el registro, con `"message": "Inicio de sesión exitoso."`):

```json
{
  "message": "Inicio de sesión exitoso.",
  "token": "<TOKEN>",
  "user": { "id": 8, "name": "Ana Ruiz", "email": "ana@nexo.test", "role": "employee", "status": "active", "profile": { "first_name": "Ana", "last_name": "Ruiz", "phone": "33 3389 7730", "avatar_url": null, "…": "…" } }
}
```

Errores:

| HTTP | Respuesta |
|---|---|
| `422` | Credenciales incorrectas: `{"message":"Las credenciales proporcionadas son incorrectas.","errors":{"email":["…"]}}`. Campos vacíos: `errors.email` / `errors.password` |
| `403` | Cuenta inactiva o suspendida (por ejemplo, invitación sin aceptar): `{"message":"Esta cuenta se encuentra inactiva o suspendida."}` |
| `429` | Demasiados intentos (cabecera `Retry-After: 60`) |

Con `user.role` decide a qué pantalla mandar: `customer`, `employee` o `driver`.

### `GET /api/auth/profile`

- **Rol:** cualquiera con sesión. Respuesta `200`: `{ "user": { id, name, email, role, status, profile } }` (igual que arriba). `401` sin token.

### `PUT /api/auth/profile`

- **Rol:** cualquiera con sesión. Todos los campos son opcionales.

Cuerpo (JSON o multipart si lleva `avatar`):

```json
{ "name": "Cecilia Navarro", "first_name": "Cecilia", "last_name": "Navarro", "phone": "33 1111 2222", "bio": "…", "birth_date": "1990-05-20" }
```

- `avatar`: archivo `jpeg`, `png`, `jpg` o `webp`, hasta 2 MB (multipart). Probado: funciona enviando `PUT` con `multipart/form-data` y también con `POST` + campo `_method=PUT`. En la respuesta, `profile.avatar_url` es la URL pública.
- El correo **no** se puede cambiar aquí.

Respuesta `200`: `{ "message": "Perfil actualizado exitosamente.", "user": { … } }`. Errores `422` con `errors`.

### `POST /api/auth/logout`

- **Rol:** cualquiera con sesión. Borra el token con el que se llamó. Respuesta `200`: `{ "message": "Sesión cerrada exitosamente." }`.

### Cuentas (públicas): aceptar invitación y restablecer contraseña

Los empleados y repartidores nacen con una invitación (un enlace que llega por correo). Estos endpoints son públicos; el `{token}` es el que trae el enlace. Reglas de la contraseña nueva: **8 a 128 caracteres, con al menos una letra y un número**.

> Mientras el servidor tenga `MAIL_MAILER=log` no se envían correos: el enlace queda en `storage/logs/enlaces.log` del servidor. Pídeselo a Yahir para probar.

#### `GET /api/v1/cuentas/invitaciones/{token}`

Muestra la invitación. Respuesta `200`:

```json
{ "estado": "enviada", "nombre_invitado": "Ana Ruiz", "correo": "ana@nexo.test", "organizacion_nombre": "Grupo Industrial Nexo", "organizacion_tipo": "empresa", "rol": "empleado", "portal": "empleados" }
```

`estado`: `enviada`, `aceptada`, `vencida` o `invalida` (token desconocido: el resto de campos vienen vacíos). Aquí `rol` está en español (`empleado`, `repartidor`, …).

#### `POST /api/v1/cuentas/invitaciones/{token}/aceptar`

Cuerpo: `{ "contrasena": "<CONTRASEÑA>" }`. Activa la cuenta con esa contraseña. Respuesta `200`:

```json
{ "portal": "empleados", "rol": "empleado", "nombre": "Ana Ruiz", "organizacion_nombre": "Grupo Industrial Nexo" }
```

Después la persona entra con `POST /api/auth/login`.

Errores `422`: `invitacion_invalida`, `invitacion_aceptada` («Inicia sesión»), `invitacion_vencida`; contraseña débil → `errores.contrasena` (por ejemplo `"La contraseña debe incluir letras y números."`).

#### `POST /api/v1/cuentas/restablecer/solicitar`

Cuerpo: `{ "correo": "ana@nexo.test" }`. **Siempre** responde `200 {"enviado": true, "enlace_simulado": null}`, exista o no el correo (para no revelar cuentas). El enlace vence a los 60 minutos y sirve una vez. Límite: 3 por hora por correo.

#### `GET /api/v1/cuentas/restablecer/{token}`

Respuesta `200`: `{"valido": true, "correo_oculto": "a***@nexo.test"}` o `{"valido": false, "motivo": "invalido" | "usado" | "vencido"}`.

#### `POST /api/v1/cuentas/restablecer/{token}`

Cuerpo: `{ "contrasena": "<CONTRASEÑA_NUEVA>" }`. Respuesta `200`: `{"mensaje": "Listo: ya puedes entrar con tu contraseña nueva."}`. Se cierran las sesiones de esa cuenta. Error `422` `enlace_invalido` si el enlace ya no sirve.

---

## 4. Endpoints compartidos (cualquier rol con sesión)

### `GET /api/v1/configuracion/operacion`

Parámetros de la operación que la app necesita mostrar. Respuesta `200`:

```json
{ "hora_corte": "18:00", "zona_horaria": "America/Mexico_City", "dias_entrega": "lunes_sabado", "regla_sin_eleccion": "ultimo_platillo",
  "capacidad_auto_entregas": 30, "capacidad_auto_platillos": 120, "ahorro_apps_porcentaje": 30, "costo_maquila_limite": 49 }
```

Lo útil para la app: `hora_corte` (hora del corte diario), `dias_entrega` (`lunes_sabado` o `lunes_viernes`) y `regla_sin_eleccion` (`ultimo_platillo`, `platillo_del_dia` o `no_entregar`). Los demás campos son de operación interna.

### `GET /api/v1/paquetes`

Paquetes activos de clientes individuales (de 1, 2 y 6 días). Respuesta `200`:

```json
[
  { "id": 1, "nombre": "Un día",   "dias": 1, "descuento_porcentaje": 0, "detalle": "Un día, con 1, 2 o 3 comidas", "destacado": false },
  { "id": 2, "nombre": "Dos días", "dias": 2, "descuento_porcentaje": 5, "detalle": "Dos días, con 1, 2 o 3 comidas · 5 % de descuento", "destacado": false },
  { "…": "… (el de 6 días)" }
]
```

(Mismo contenido que `GET /api/v1/cliente/paquetes`.)

### `GET /api/v1/calificaciones/menu-semanal`

Promedio de estrellas del menú de la semana (sabor y calidad), en tiempo real. Respuesta `200`:

```json
{ "semana_inicio": "2026-11-16", "semana_fin": "2026-11-22", "promedio": 4.17, "calificaciones": 24,
  "serie": [ { "semana_inicio": "2026-10-12", "promedio": 4, "calificaciones": 14 }, "…" ] }
```

`promedio` es `null` si la semana no tiene calificaciones.

---

## 5. Empleado

**Rol:** `employee`. Todos los endpoints de este bloque cuelgan de `/api/v1/empleado` y trabajan sobre **el propio empleado** (se identifica por el token). Si la cuenta no tiene ficha de empleado responden `403 sin_permiso` («Tu cuenta no está asociada a ninguna empresa como empleado.»).

### Reglas importantes (cortes)

- **Servicio:** las empresas reciben de lunes a sábado; el domingo no hay servicio (y tampoco los días marcados sin servicio por el administrador).
- **Corte:** las elecciones de una fecha de entrega **cierran a las 18:00 (hora de Guadalajara) del día calendario anterior** (`hora_corte` es configurable; ver `GET /configuracion/operacion`). A las 18:00:00 en punto ya está cerrado. También se cierra si el administrador ejecuta el corte a mano antes de tiempo. Pasado el corte no se puede elegir, cambiar ni quitar (`corte_cerrado`).
- **Un platillo por día y por tiempo de comida** (`desayuno`, `comida` o `cena`). Se puede cambiar cuantas veces quiera **antes del corte**; elegir el mismo platillo otra vez no hace nada.
- **Precio y subsidio congelados:** al elegir se congelan el precio y el porcentaje de subsidio del contrato de su empresa; cambios de contrato posteriores no tocan lo ya elegido.
- **Sin elección:** si no eligió, el corte aplica la regla `regla_sin_eleccion`: `ultimo_platillo`, `platillo_del_dia` o `no_entregar`. Esas elecciones nacen con `origen: "por_defecto"` (las que hace la persona son `manual`).
- **Porciones:** cada platillo tiene un máximo de porciones por día; si ya no hay, `sin_porciones`.
- Quien cubre cuánto: `empresa_cubre` (subsidio) y `empleado_paga` (el resto) los calcula el servidor; la app solo los muestra.

### `GET /api/v1/empleado/menu`

El menú que puede elegir para una fecha y tiempo de comida.

Parámetros (query, opcionales):

| Parámetro | Valor | Por defecto |
|---|---|---|
| `fecha` | `AAAA-MM-DD` | la próxima fecha de servicio **con el corte abierto** (si hoy ya pasó el corte de mañana, devuelve la siguiente) |
| `tiempo_comida` | `desayuno` \| `comida` \| `cena` | `comida` |

Respuesta `200` (`opciones` recortado):

```json
{
  "fecha": "2026-11-23", "tiempo_comida": "comida", "hora_corte": "18:00",
  "cerrado": false, "motivo_cerrado": null,
  "empleado": { "id": 1, "nombre": "Ana Ruiz", "empresa_nombre": "Grupo Industrial Nexo", "activo": true },
  "hora_entrega": "12:30", "precio": 130, "porcentaje_subsidio": 60, "empresa_cubre": 78, "empleado_paga": 52,
  "opciones": [
    { "platillo": { "id": 1, "comedor_id": 1, "comedor_nombre": "Cocina Norte", "nombre": "Pollo en mole con arroz", "descripcion": "Pierna de pollo en mole poblano, arroz rojo y frijoles.",
                    "tiempo_comida": "comida", "calorias": 720, "alergenos": ["frutos secos", "ajonjolí"], "etiquetas": ["casero"], "precio_venta": 49,
                    "foto_url": null, "estado_revision": "publicado", "motivo_rechazo": null, "activo": true },
      "porciones_maximas": 40, "porciones_tomadas": 1, "porciones_restantes": 39, "agotado": false },
    "…"
  ],
  "eleccion": null
}
```

- `cerrado` es `true` si ese día la empresa no tiene servicio (`motivo_cerrado: "empresa_cerrada"`, y `opciones` viene vacío) o si ya pasó el corte (`motivo_cerrado: "corte"`).
- `eleccion` es `{ "platillo_id": 3, "platillo_nombre": "Enchiladas verdes", "origen": "manual" }` si ya eligió ese día y tiempo, o `null`.
- `precio`, `empresa_cubre` y `empleado_paga` salen del **contrato de su empresa** y son lo que le importa al empleado. `platillo.precio_venta` es el precio base del platillo (el que se usa para los clientes individuales), no lo que paga el empleado.

Errores: `422` por validación de `fecha`/`tiempo_comida`; `403 sin_permiso`.

### `PUT /api/v1/empleado/elecciones`

Elige o cambia el platillo de un día y tiempo de comida (idempotente).

Cuerpo:

```json
{ "fecha": "2026-11-23", "tiempo_comida": "comida", "platillo_id": 3 }
```

Respuesta `200`:

```json
{ "id": 253, "empleado_id": 1, "fecha": "2026-11-23", "tiempo_comida": "comida", "platillo_id": 3, "precio_congelado": 130, "porcentaje_subsidio_congelado": 60, "origen": "manual" }
```

Errores de negocio (`422`):

| `codigo` | Cuándo |
|---|---|
| `corte_cerrado` | Ya pasó el corte de esa fecha (el mensaje trae la hora exacta) |
| `dia_sin_servicio` | Ese día no hay servicio para su empresa (domingo, festivo o cierre) |
| `sin_contrato_vigente` | Su empresa no tiene contrato vigente ese día |
| `platillo_no_disponible` | El platillo no está publicado/activo o no es de ese tiempo de comida |
| `sin_porciones` | Ya no quedan porciones de ese platillo ese día |
| `empleado_inactivo` | El empleado o su empresa están desactivados |

Más `422` de validación (`fecha` con formato `AAAA-MM-DD`, `tiempo_comida` válido, `platillo_id` entero).

### `DELETE /api/v1/empleado/elecciones`

Quita su elección de un día y tiempo de comida (solo antes del corte; libera la porción).

Cuerpo (sí, un `DELETE` con cuerpo JSON): `{ "fecha": "2026-11-25", "tiempo_comida": "comida" }`

Respuesta `200`: `{ "mensaje": "Listo: quitamos tu elección." }`

Errores `422`: `corte_cerrado`, `sin_eleccion` («No hay una elección para ese día y tiempo de comida.»).

### `GET /api/v1/empleado/elecciones`

Su historial, del más reciente al más antiguo. Query opcional `limite` (1 a 100, por defecto 8).

Respuesta `200`:

```json
[
  { "id": 253, "fecha": "2026-11-23", "tiempo_comida": "comida", "platillo_nombre": "Enchiladas verdes", "precio": 130, "empresa_cubre": 78, "empleado_paga": 52, "origen": "manual", "cerrada": false },
  { "id": 247, "fecha": "2026-11-21", "tiempo_comida": "comida", "platillo_nombre": "Milanesa de res con ensalada", "precio": 130, "empresa_cubre": 78, "empleado_paga": 52, "origen": "manual", "cerrada": true }
]
```

`cerrada: true` = el corte de esa fecha ya pasó (ya no se puede cambiar). `origen`: `manual` o `por_defecto`.

### `GET /api/v1/empleado/calificaciones/pendientes`

Lo que ya recibió en los últimos 14 días y todavía no calificó. Un elemento por día:

```json
[
  { "fecha": "2026-11-18", "entrega_calificada": false,
    "platillos": [ { "tiempo_comida": "comida", "platillo_id": 4, "platillo_nombre": "Caldo de res", "calificado": true } ] },
  "…"
]
```

Solo aparecen días con la entrega de su empresa **ya entregada** en los que él pidió, y que tengan algo por calificar. Lista vacía = nada pendiente.

### `POST /api/v1/empleado/calificaciones/entrega`

Califica la entrega (última milla) de un día, de 1 a 5.

```json
{ "fecha": "2026-11-18", "puntualidad": 5, "trato_chofer": 4, "empaque": 4, "comentario": "Todo en orden" }
```

`comentario` es opcional (máx. 500). Respuesta `201`: `{ "id": 171, "mensaje": "Gracias por calificar la entrega." }`

Errores `422`: `fuera_de_plazo` (solo se califica lo de los últimos 14 días, nunca fechas futuras), `sin_entrega_que_calificar` (solo una entrega ya recibida en la que pidió), `ya_calificada`.

### `POST /api/v1/empleado/calificaciones/platillo`

Califica el sabor y la calidad del platillo de un tiempo de comida, de 1 a 5.

```json
{ "fecha": "2026-11-14", "tiempo_comida": "comida", "estrellas": 5, "comentario": "Muy rico" }
```

Respuesta `201`: `{ "id": 195, "mensaje": "Gracias por calificar el platillo." }`

Errores `422`: `fuera_de_plazo`, `sin_platillo_que_calificar` (solo un platillo ya recibido), `ya_calificada`.

---

## 6. Cliente individual

**Rol:** `customer`. Los endpoints cuelgan de `/api/v1/cliente` y solo ven lo propio (un pedido o dirección de otra persona responde `404`).

### Reglas importantes

**Entrega semanal: una sola entrega, el sábado.**

- TODO el pedido llega junto, **una vez por semana, el sábado**. Ese día salen a repartir.
- **Cierre de pedidos: viernes a las 18:00** (la misma `hora_corte`). Lunes a viernes antes de las 18:00 → entra al sábado de **esa** semana. Desde el viernes a las 18:00, el sábado y el domingo → pasa al sábado **siguiente**. Un sábado festivo o sin servicio se salta al siguiente.
- El cliente sigue eligiendo platillos **por día**, pero esos son los **días de comida** de la semana siguiente a la entrega (lunes a sábado; el domingo y los festivos no cuentan). Cada platillo aparta su porción en el día en que se come.
- `GET /cliente/proxima-entrega` dice cuál es el sábado, cuándo cierra y qué días de comida hay que llenar. **Úsalo para armar la pantalla de pedido.**

**Paquetes y comidas:**

- Paquete de **1, 2 o 6 días** (`GET /paquetes`). El descuento (`descuento_porcentaje`) lo define el administrador.
- El cliente elige **1, 2 o 3 comidas por día** (`desayuno`, `comida` y/o `cena`), **las mismas todos los días**.
- Puede sumar un **extra** (un platillo adicional por día, de cualquier tiempo de comida), también **todos los días o ninguno**.
- El precio de cada platillo se congela al pedir. `total = suma de precios × (1 − descuento del paquete)`; el extra lleva el mismo descuento. El servidor calcula el total.

**Cobertura:**

- Cada dirección guarda su punto (latitud/longitud). La zona se calcula sola en el servidor con PostGIS dentro de los polígonos de las zonas activas.
- Si el punto cae **fuera de toda zona**, la dirección queda con `"zona": null` y `"sin_cobertura": true`: se puede guardar pero **no sirve para pedir** (`sin_cobertura` al crear el pedido).
- Si la app no manda coordenadas, el servidor busca la dirección en OpenStreetMap (Nominatim); si no la encuentra responde `direccion_no_encontrada`. **Recomendación: manda siempre `latitud` y `longitud` del punto que la persona marcó en el mapa.**

**Estados del pedido** (`estado`): `pendiente_pago` → `recibido` (pagado) → `completado` (entregado); `cancelado` (vencido, reembolsado o cancelado). `activo` existe en el esquema y, igual que `recibido`, significa pagado y por entregar.

**Estados de la entrega** (`dias[].estado`): `programada`, `en_ruta`, `entregada`, `no_localizado`, `cancelada`.

**Pago con tarjeta (Stripe, modo de prueba):**

1. `POST /cliente/pedidos` crea el pedido en `pendiente_pago` y trae `vence_pago_en` (el viernes a las 18:00 anterior al sábado de entrega) y `segundos_para_pagar`.
2. `POST /cliente/pedidos/{id}/pago` crea un PaymentIntent por el total en MXN y devuelve su `client_secret`.
3. La app cobra con el SDK de Stripe usando ese `client_secret` (y la clave **publicable** de Stripe, que **no** viene de esta API: pídesela a Yahir).
4. Cuando Stripe confirma el cobro, **el servidor** (webhook firmado) pasa el pedido a `recibido` y guarda `metodo_pago: "tarjeta"`, `ultimos_digitos` y la fecha de pago. **La app no confirma nada contra esta API**: tras pagar, vuelve a pedir `GET /cliente/pedidos/{id}` hasta que `estado` sea `recibido`.
5. Nunca se guardan datos de tarjeta (solo los últimos 4 dígitos).
6. Si el pedido vence sin pagarse, se cancela y hay que pedirlo de nuevo. Si un cobro llega a un pedido ya cancelado o vencido, el servidor lo **reembolsa completo automáticamente** (el pedido no cambia).
7. Los reembolsos son siempre totales: el pedido queda `cancelado` con `reembolsado_en`, `monto_reembolsado` y `stripe_refund_id`. Los hace administración o soporte, no la app.

### `GET /api/v1/cliente/paquetes`

Igual que [`GET /api/v1/paquetes`](#get-apiv1paquetes).

### `GET /api/v1/cliente/menu-semanal`

El menú de 6 días a partir de `fecha_inicio`, por día y tiempo de comida (un platillo publicado con porciones por celda).

Query: `fecha_inicio` (obligatoria, `AAAA-MM-DD`). Para un pedido usa el primer elemento de `dias_de_comida` de `proxima-entrega`.

Respuesta `200`:

```json
[
  { "fecha": "2026-11-30",
    "celdas": {
      "desayuno": { "platillo_id": 6, "platillo_nombre": "Chilaquiles con huevo", "precio_venta": 49, "porciones_maximas": 40, "foto_url": "https://…/platillo.jpg" },
      "comida":   { "platillo_id": 1, "platillo_nombre": "Pollo en mole con arroz", "precio_venta": 49, "porciones_maximas": 40, "foto_url": "https://…/platillo.jpg" },
      "cena":     { "platillo_id": 7, "platillo_nombre": "Sopa de lentejas", "precio_venta": 49, "porciones_maximas": 40, "foto_url": null } } },
  "… (6 días)"
]
```

`foto_url` es la foto del platillo, o `null` si no tiene.

Una celda que falta (por ejemplo sin `cena`) significa que ese día no hay platillo publicado de ese tiempo. Igual que en `GET /api/v1/admin/menu-semanal`, el platillo mostrado por celda es el primero disponible; **la disponibilidad real se valida al crear el pedido**.

### `GET /api/v1/cliente/proxima-entrega`

```json
{ "fecha_entrega": "2026-11-28", "cierra_en": "2026-11-27T18:00:00-06:00", "dias_de_comida": ["2026-11-30", "2026-12-01", "…"] }
```

`dias_de_comida` trae los 6 posibles días de comida (los primeros `paquete.dias` son los de ese paquete). Error `422 sin_dia_de_entrega` si por ahora no hay sábados de entrega.

### `GET /api/v1/cliente/direcciones`

Lista sus direcciones (la predeterminada primero). Respuesta `200`:

```json
[
  { "id": 1, "etiqueta": "Casa", "direccion": "Av. Vallarta 1248", "numero_interior": "4B", "codigo_postal": "44100", "latitud": 20.676, "longitud": -103.347,
    "referencias": "Portón negro, casa verde", "predeterminada": true, "zona": "Centro", "sin_cobertura": false },
  { "id": 2, "etiqueta": "Oficina CDMX (sin cobertura)", "direccion": "Paseo de la Reforma 222", "numero_interior": null, "codigo_postal": null, "latitud": 19.4326, "longitud": -99.1332,
    "referencias": null, "predeterminada": false, "zona": null, "sin_cobertura": true }
]
```

### `POST /api/v1/cliente/direcciones`

Cuerpo:

```json
{ "etiqueta": "Casa", "direccion": "Av. Chapultepec Sur 480", "numero_interior": "3A", "codigo_postal": "44150",
  "latitud": 20.6709, "longitud": -103.3667, "referencias": "Portón azul, entre Av. Niños Héroes y Av. Vallarta", "predeterminada": false }
```

- Obligatorio solo `direccion` (máx. 500). `etiqueta` máx. 50, `numero_interior` máx. 20, `codigo_postal` máx. 10, `referencias` máx. 500.
- `latitud` (−90 a 90) y `longitud` (−180 a 180): van **las dos o ninguna** (si una está, la otra es obligatoria).
- `predeterminada: true` quita la marca a las demás (solo una predeterminada).

Respuesta `201` (la dirección con su zona calculada):

```json
{ "id": 4, "etiqueta": "Casa", "direccion": "Av. Chapultepec Sur 480", "numero_interior": "3A", "codigo_postal": "44150", "latitud": 20.6709, "longitud": -103.3667,
  "referencias": "Portón azul, entre Av. Niños Héroes y Av. Vallarta", "predeterminada": false, "zona": "Centro", "sin_cobertura": false }
```

Con un punto fuera de cobertura (probado con la Ciudad de México) responde `201` igualmente, con `"zona": null, "sin_cobertura": true`. **Muestra ese aviso en la app** antes de que intente pedir.

Errores: `422 direccion_no_encontrada` (sin coordenadas y no se pudo ubicar el texto); `422` de validación (`errores.direccion`, `errores.longitud`…).

### `PUT /api/v1/cliente/direcciones/{id}`

Edita una dirección propia. Todos los campos son opcionales (`sometimes`); mismos límites que al crear. Al editar se puede mandar solo una coordenada. Si no mandas coordenadas y cambias `direccion` o `codigo_postal`, se vuelve a ubicar el texto; si solo cambias otra cosa, se conserva el punto.

Cuerpo de ejemplo: `{ "referencias": "Tocar el timbre dos veces" }`. Respuesta `200`: la dirección completa. Errores: `404` si no es suya, `422 direccion_no_encontrada`.

### `DELETE /api/v1/cliente/direcciones/{id}`

Respuesta `200`: `{ "mensaje": "Dirección eliminada." }`.

Errores: `422 direccion_en_uso` (tiene pedidos: «Puedes editarla o dejar de usarla») y `404`.

### `GET /api/v1/cliente/pedidos`

Todos sus pedidos, del más reciente al más antiguo (sin paginación). Cada elemento es un pedido completo (forma abajo).

### `GET /api/v1/cliente/pedidos/{id}`

Un pedido propio (`404` si no existe o es de otra persona). Misma forma.

**Forma de un pedido** (respuesta del `POST` siguiente, con `platillos` recortados):

```json
{
  "id": 9, "folio": "GDL-20261122-0006", "cliente_id": 16, "cliente_nombre": "Cecilia Navarro", "plan_id": 2, "plan_nombre": "Dos días",
  "fecha_entrega": "2026-11-28", "fecha_inicio": "2026-11-30", "fecha_fin": "2026-12-01",
  "zona": "Centro", "total": 279.3, "estado": "pendiente_pago",
  "entregas_totales": 1, "entregas_completadas": 0,
  "direccion_entrega": "Av. Chapultepec Sur 480", "referencias": "Portón azul, entre Av. Niños Héroes y Av. Vallarta",
  "metodo_pago": null, "ultimos_digitos": null, "tiene_evidencia": false,
  "vence_pago_en": "2026-11-27T18:00:00-06:00", "segundos_para_pagar": 450769,
  "comidas_por_dia": 2, "con_extra": true,
  "reembolsado_en": null, "monto_reembolsado": null, "stripe_refund_id": null,
  "dias": [
    { "id": 11, "fecha": "2026-11-30", "fecha_entrega": "2026-11-28", "estado": "programada",
      "platillos": [
        { "tiempo_comida": "desayuno", "es_extra": false, "platillo_id": 6, "platillo_nombre": "Chilaquiles con huevo", "precio_congelado": 49 },
        { "tiempo_comida": "comida",   "es_extra": false, "platillo_id": 1, "platillo_nombre": "Pollo en mole con arroz", "precio_congelado": 49 },
        { "tiempo_comida": "comida",   "es_extra": true,  "platillo_id": 2, "platillo_nombre": "Milanesa de res con ensalada", "precio_congelado": 49 } ] },
    { "id": 11, "fecha": "2026-12-01", "fecha_entrega": "2026-11-28", "estado": "programada", "platillos": [ "…" ] }
  ]
}
```

Cómo leerlo:

- `fecha_entrega`: el sábado en que llega **todo**. `fecha_inicio` y `fecha_fin`: primer y último día de comida.
- `dias` trae **un elemento por día de comida** (lo que eligió para ese día, en `fecha`). Todos viajan en la misma entrega, por eso comparten `id`, `estado` y `fecha_entrega` (los de esa entrega única). `entregas_totales` es 1 en los pedidos nuevos.
- `segundos_para_pagar` y `vence_pago_en` solo tienen valor mientras el pedido está `pendiente_pago`; después son `null`.
- `tiene_evidencia`: la entrega ya tiene foto de evidencia del repartidor.
- Los campos de reembolso (`reembolsado_en`, `monto_reembolsado`, `stripe_refund_id`) solo se llenan en un pedido reembolsado.
- Para pedidos antiguos (de antes de la entrega semanal) `dias[].fecha_entrega` puede ser el propio día de comida.

### `POST /api/v1/cliente/pedidos`

Crea un pedido. Nace `pendiente_pago`: **todavía no está pagado** ni entra al corte.

Cuerpo:

```json
{
  "paquete_id": 2,
  "direccion_id": 4,
  "platillos": [
    { "desayuno": 6, "comida": 1, "extra": 2 },
    { "desayuno": 6, "comida": 1, "extra": 2 }
  ]
}
```

- `platillos` tiene **un elemento por día del paquete, en orden** (el primero es el primer día de comida de `proxima-entrega`). Cada elemento es un objeto con los tiempos de comida elegidos (`desayuno`, `comida`, `cena`; de 1 a 3) con el **id del platillo**, más `extra` opcional.
- Los **mismos tiempos de comida todos los días**, y `extra` en todos los días o en ninguno.
- Cada platillo debe ser del tiempo de comida en el que se pone (un desayuno no va en `comida`); el `extra` puede ser de cualquier tiempo.
- `platillos`: de 1 a 6 elementos; cada elemento, de 1 a 4 llaves.

Respuesta `201`: el pedido (forma de arriba).

Errores de negocio (`422`):

| `codigo` | Cuándo |
|---|---|
| `paquete_inactivo` | El paquete ya no está disponible |
| `sin_cobertura` | La dirección está fuera de cobertura |
| `platillos_incompletos` | El número de días de `platillos` no coincide con los del paquete |
| `comidas_por_dia_invalidas` | Un día lleva 0 o más de 3 comidas |
| `comidas_distintas_por_dia` | Los días no llevan los mismos tiempos de comida |
| `extra_distinto_por_dia` | El extra no está en todos los días (o en ninguno) |
| `tiempo_de_comida_invalido` | Una llave que no es `desayuno`, `comida`, `cena` ni `extra` |
| `platillo_de_otro_tiempo` | Un platillo no es del tiempo de comida en el que se puso |
| `platillo_no_disponible` | El platillo no existe, no está publicado/activo o su comedor está desactivado |
| `sin_porciones` | Ya no quedan porciones de un platillo ese día (el mensaje dice cuál y qué fecha) |
| `sin_dia_de_entrega` | No hay sábados de entrega próximos |

Una `direccion_id` o `paquete_id` que no existe (o una dirección de otra persona) responde `404`. Más `422` de validación.

### `POST /api/v1/cliente/pedidos/{id}/pago`

Inicia el pago con tarjeta de un pedido propio, `pendiente_pago` y sin vencer. **No cambia el pedido.** Repetir la llamada devuelve el mismo intento mientras siga sirviendo.

Cuerpo: ninguno.

Respuesta `200` (tomada del código: `PagosDePedidos::iniciar`; no se llamó para no crear cobros en Stripe):

```json
{ "pedido_id": 9, "payment_intent_id": "pi_…", "client_secret": "pi_…_secret_…", "monto": "279.30", "moneda": "MXN" }
```

`monto` es el total del pedido en MXN. Con el `client_secret` la app presenta el pago con el SDK de Stripe.

Errores:

| HTTP / `codigo` | Cuándo |
|---|---|
| `422 pedido_no_pagable` | El pedido no está `pendiente_pago` (ya pagado, cancelado, completado…) |
| `422 pedido_vencido` | Pasó el plazo (`vence_pago_en`) o ya se ejecutó el corte de individuales de ese sábado: hay que pedirlo de nuevo |
| `503 pagos_no_configurados` | El servidor no tiene las claves de Stripe |
| `404` | El pedido no existe o es de otra persona |

### `POST /api/v1/cliente/pedidos/{id}/calificaciones/entrega`

Califica la entrega de un pedido propio, de 1 a 5. `fecha` es el sábado de la entrega **o** cualquiera de los días de comida de ese pedido.

```json
{ "fecha": "2026-11-12", "puntualidad": 5, "trato_chofer": 5, "empaque": 4, "comentario": "Llegó caliente" }
```

Respuesta `201`: `{ "id": 171, "mensaje": "Gracias por calificar la entrega." }`.

Errores `422`: `fuera_de_plazo` (solo los últimos 14 días, nunca fechas futuras), `sin_entrega_que_calificar` (solo una entrega ya recibida), `ya_calificada`; `404` si el pedido no es suyo.

### `POST /api/v1/cliente/pedidos/{id}/calificaciones/platillo`

```json
{ "fecha": "2026-11-12", "platillo_id": 1, "estrellas": 5, "comentario": "Muy rico" }
```

Respuesta `201`: `{ "id": 195, "mensaje": "Gracias por calificar el platillo." }`.

Errores `422`: `fuera_de_plazo`, `sin_entrega_que_calificar`, `sin_platillo_que_calificar` («Ese platillo no fue parte de tu pedido ese día.»), `ya_calificada`.

---

## 7. Repartidor

**Rol:** `driver`. Endpoints bajo `/api/v1/repartidor`. Si la cuenta no está dada de alta como repartidor **activo**, todos responden `403 sin_permiso` («Tu cuenta no está dada de alta como repartidor activo.»).

No hay chat ni GPS en la API: el repartidor **no manda su ubicación**, solo recibe la de cada parada.

### Reglas importantes

- **Solo ve y toca sus propias rutas, y solo las aprobadas** por el administrador (`aprobada`, `en_curso` o `completada`). Un borrador no aparece. Las rutas se asignan automáticamente: el repartidor no las escoge.
- **Una ruta solo se empieza el día que le toca** (`fecha`). Una ruta ya empezada se puede seguir aunque pase la medianoche.
- **Una sola ruta en curso a la vez.**
- **Las paradas se atienden en orden:** no se inicia una parada hasta que las anteriores quedaron entregadas o no entregadas (no se puede saltar el orden).
- **En línea / fuera de línea:** fuera de línea no puede iniciar paradas, y con una ruta en curso no puede ponerse fuera de línea.
- Iniciar la primera parada pasa la ruta a `en_curso` (y al repartidor a `en_ruta`); cerrar la última parada pasa la ruta a `completada` (y al repartidor a `libre`).
- **Una parada se cierra de dos formas:** `entregado` (con foto de evidencia) o `no_localizado` (con un motivo; esto además abre una incidencia para el administrador). El estado de la entrega a la empresa o del pedido del cliente sigue al de su parada, y un pedido de cliente queda `completado` cuando ya no le falta ninguna entrega.

**Estados de una ruta:** `borrador` (el repartidor no la ve), `aprobada`, `en_curso`, `completada`.
**Estados de una parada:** `pendiente` → `en_camino` → `entregado` | `no_localizado`.
**Estados del repartidor (`estado`):** `libre`, `asignado`, `en_ruta`, `no_disponible` (fuera de línea).

### Los campos `ubicacion` y `contacto_nombre`

Cada parada trae todo lo que necesita el repartidor en la calle:

- **`ubicacion`**: `{ "latitud": 20.676968, "longitud": -103.346985 }` (6 decimales) o **`null`** si esa parada no tiene punto (por ejemplo una empresa dada de alta sin coordenadas). La app debe manejar `null` (mostrar la `direccion` en texto y permitir llamar).
  - Paradas de **cliente**: el punto de la dirección del pedido.
  - Paradas de **empresa**: el punto guardado de la parada o, si no lo hay, el de la empresa (`latitud`/`longitud` de la empresa).
- **`contacto_nombre`**: por quién preguntar. En una parada de **cliente** es el nombre del cliente; en una de **empresa** es la persona de RR. HH. de esa empresa («por quién preguntar en la entrada»). Junto con `telefono` (el teléfono de esa persona, **puede ser `null`**) permite llamar.
- `destinatario` es el nombre de la parada (cliente o empresa); `direccion`, el texto de la dirección.

### `GET /api/v1/repartidor/disponibilidad`

Respuesta `200`:

```json
{ "en_linea": true, "estado": "libre", "ruta_en_curso_id": null }
```

`en_linea` es `false` cuando `estado` es `no_disponible`. `ruta_en_curso_id` es el id de su ruta en curso, si la hay.

### `PATCH /api/v1/repartidor/disponibilidad`

Se pone en línea o fuera de línea.

Cuerpo: `{ "en_linea": false }` (obligatorio, booleano). Respuesta `200`: igual que `GET` (por ejemplo `{ "en_linea": false, "estado": "no_disponible", "ruta_en_curso_id": null }`).

Errores: `422 ruta_en_curso` («Tienes una ruta en curso: termínala antes de ponerte fuera de línea.»); `422` de validación si falta `en_linea`.

### `GET /api/v1/repartidor/rutas`

Sus rutas de un día, con sus paradas, ordenadas por ventana de entrega.

Query: `fecha` (`AAAA-MM-DD`, opcional; por defecto **hoy**).

Respuesta `200` (una ruta de empresas con dos paradas; recortada):

```json
[
  {
    "id": 1, "fecha": "2026-11-22", "estado": "aprobada", "zona": "Centro", "tipo": "empresas",
    "ventana_inicio": "12:30", "ventana_fin": "13:20", "entregas_asignadas": 2, "platillos_asignados": 6,
    "iniciada_en": null, "completada_en": null, "paradas_totales": 2, "paradas_cerradas": 0, "siguiente_parada_id": 1,
    "paradas": [
      {
        "id": 1, "orden": 1, "estado": "pendiente", "tipo": "empresa",
        "destinatario": "Grupo Industrial Nexo",
        "direccion": "Avenida Fray Antonio Alcalde 10, Centro, C.P. 44100, Guadalajara",
        "ubicacion": { "latitud": 20.676968, "longitud": -103.346985 },
        "hora_estimada": "12:30",
        "platillos": [ { "nombre": "Caldo de res", "tiempo_comida": "comida", "cantidad": 1, "dia_de_comida": "2026-11-23", "es_extra": false }, "…" ],
        "total_platillos": 4,
        "iniciada_en": null, "cerrada_en": null, "evidencia_foto_url": null, "motivo_no_entrega": null,
        "referencias": null, "contacto_nombre": "Mariana Ortega", "telefono": "33 6229 5987", "folio": null
      },
      {
        "id": 2, "orden": 2, "estado": "pendiente", "tipo": "empresa",
        "destinatario": "Logística Pacífico", "direccion": "Av. de la Industria 1450, Centro",
        "ubicacion": null,
        "hora_estimada": "13:00",
        "platillos": [ { "nombre": "Milanesa de res con ensalada", "tiempo_comida": "comida", "cantidad": 2, "dia_de_comida": "2026-11-23", "es_extra": false } ],
        "total_platillos": 2,
        "iniciada_en": null, "cerrada_en": null, "evidencia_foto_url": null, "motivo_no_entrega": null,
        "referencias": null, "contacto_nombre": "Ricardo Salas", "telefono": "33 4647 9201", "folio": null
      }
    ]
  }
]
```

Campos de la **ruta**:

| Campo | Significado |
|---|---|
| `tipo` | `empresas`, `clientes` o `mixta` (según el tipo de sus paradas) |
| `estado` | `aprobada`, `en_curso` o `completada` |
| `ventana_inicio` / `ventana_fin` | Ventana de entrega de la ruta (`HH:MM`) |
| `entregas_asignadas` / `platillos_asignados` | Cuántas entregas y platillos lleva |
| `iniciada_en` / `completada_en` | Momentos (ISO 8601) o `null` |
| `paradas_totales` / `paradas_cerradas` | Para la barra de avance |
| `siguiente_parada_id` | La primera parada `pendiente` o `en_camino`; `null` si ya no queda ninguna |

Campos de cada **parada**:

| Campo | Significado |
|---|---|
| `id`, `orden` | Id de la parada y su orden en la ruta (se atiende en ese orden) |
| `estado` | `pendiente`, `en_camino`, `entregado`, `no_localizado` |
| `tipo` | `cliente` o `empresa` |
| `destinatario`, `direccion` | A quién y dónde |
| `ubicacion` | `{latitud, longitud}` o `null` (ver arriba) |
| `hora_estimada` | `HH:MM` |
| `platillos[]` | Lo que lleva: `nombre`, `tiempo_comida`, `cantidad`, `dia_de_comida` y `es_extra`. En una parada de empresa agrupa lo que eligieron sus empleados (`cantidad` puede ser mayor que 1) |
| `total_platillos` | Suma de las cantidades |
| `iniciada_en`, `cerrada_en` | Momentos o `null` |
| `evidencia_foto_url` | URL de la foto de evidencia si `entregado`; `null` si no |
| `motivo_no_entrega` | Motivo si `no_localizado`; `null` si no |
| `referencias` | Indicaciones de entrega del cliente (en paradas de cliente; `null` en las de empresa) |
| `contacto_nombre`, `telefono` | Ver arriba |
| `folio` | Folio del pedido (`GDL-AAAAMMDD-NNNN`) en paradas de cliente; `null` en las de empresa |

Para **clientes individuales** la ruta sale el **sábado** y **cada parada es una entrega con TODO el pedido**: los `platillos[]` traen lo de los varios días de comida (`dia_de_comida`) de esa semana. Ejemplo real de una parada de cliente (recortada):

```json
{
  "id": 3, "orden": 1, "estado": "pendiente", "tipo": "cliente",
  "destinatario": "Cecilia Navarro", "direccion": "Av. Vallarta 1248 int. 4B",
  "ubicacion": { "latitud": 20.676, "longitud": -103.347 },
  "hora_estimada": "12:10",
  "platillos": [
    { "nombre": "Chilaquiles con huevo", "tiempo_comida": "desayuno", "cantidad": 1, "dia_de_comida": "2026-11-30", "es_extra": false },
    { "nombre": "Pollo en mole con arroz", "tiempo_comida": "comida", "cantidad": 1, "dia_de_comida": "2026-11-30", "es_extra": false },
    "…"
  ],
  "total_platillos": 4,
  "iniciada_en": null, "cerrada_en": null, "evidencia_foto_url": null, "motivo_no_entrega": null,
  "referencias": "Tocar el timbre dos veces", "contacto_nombre": "Cecilia Navarro", "telefono": "33 1111 2222", "folio": "GDL-20261122-0001"
}
```

Una lista vacía `[]` significa que no tiene rutas aprobadas ese día.

### Respuesta de las acciones sobre una parada

`iniciar`, `entregar` y `no-entregada` responden `200` con la **parada ya actualizada** y el **estado de su ruta** (para refrescar la pantalla sin volver a pedir la lista):

```json
{
  "parada": { "id": 1, "orden": 1, "estado": "en_camino", "…": "… (misma forma que en GET /rutas)" },
  "ruta":   { "id": 1, "fecha": "2026-11-22", "estado": "en_curso", "zona": "Centro", "tipo": "empresas", "ventana_inicio": "12:30", "ventana_fin": "13:20",
              "entregas_asignadas": 2, "platillos_asignados": 6, "iniciada_en": "2026-11-22T12:48:04-06:00", "completada_en": null,
              "paradas_totales": 2, "paradas_cerradas": 0, "siguiente_parada_id": 1 }
}
```

Una parada de **otra persona**, de un borrador o que no existe responde `404 no_encontrado`.

### `POST /api/v1/repartidor/paradas/{id}/iniciar`

Inicia la siguiente parada (`pendiente` → `en_camino`). Si es la primera de la ruta, la ruta pasa a `en_curso`. Sin cuerpo.

Errores `422`:

| `codigo` | Cuándo |
|---|---|
| `fuera_de_linea` | Está fuera de línea: debe ponerse en línea |
| `ruta_completada` | Esa ruta ya se completó |
| `ruta_de_otro_dia` | La ruta no es de hoy y aún no estaba empezada («solo puedes iniciar las rutas de hoy») |
| `parada_en_camino` | Esa parada ya está en camino: hay que confirmar la entrega o marcarla no entregada |
| `parada_ya_atendida` | Esa parada ya se atendió (entregada o no entregada) |
| `orden_de_paradas` | Hay una parada anterior sin atender (el mensaje dice cuál) |
| `otra_ruta_en_curso` | Ya tiene otra ruta en curso |

### `POST /api/v1/repartidor/paradas/{id}/entregar`

Confirma la entrega de una parada **en camino** con su foto de evidencia. Es **`multipart/form-data`** con el campo `foto`:

- Imagen real `jpg`, `jpeg`, `png` o `webp` (no SVG), **máximo 5 MB**. Obligatoria.
- El servidor guarda la foto con un nombre aleatorio y devuelve su URL absoluta en `parada.evidencia_foto_url` (la base de la URL depende del `APP_URL` del servidor).

Respuesta `200` (`parada.estado: "entregado"`, `parada.cerrada_en` y `parada.evidencia_foto_url` llenos; si era la última parada, `ruta.estado: "completada"` y `siguiente_parada_id: null`).

Errores: `422` de validación (`errores.foto`: «El campo foto es obligatorio.», tipo o tamaño inválido); `422 parada_no_iniciada` («Primero inicia esta parada.»); `422 parada_ya_atendida`.

### `POST /api/v1/repartidor/paradas/{id}/no-entregada`

Marca una parada **en camino** como no entregada. Deja una **incidencia abierta** (prioridad inmediata) para el administrador.

Cuerpo:

```json
{ "motivo": "Nadie atendió en la entrada" }
```

`motivo`: obligatorio, de 3 a 255 caracteres.

Respuesta `200`: `parada.estado: "no_localizado"`, con `parada.motivo_no_entrega` y `parada.cerrada_en`. Si era la última parada, la ruta queda `completada` (ejemplo real):

```json
{ "parada": { "id": 2, "estado": "no_localizado", "motivo_no_entrega": "Nadie atendió en la entrada", "cerrada_en": "2026-11-22T12:48:05-06:00", "evidencia_foto_url": null, "…": "…" },
  "ruta":   { "id": 1, "estado": "completada", "completada_en": "2026-11-22T12:48:05-06:00", "paradas_totales": 2, "paradas_cerradas": 2, "siguiente_parada_id": null, "…": "…" } }
```

Errores: `422 parada_no_iniciada`, `422 parada_ya_atendida`; `422` de validación de `motivo`.

### Flujo típico del día

1. `PATCH /repartidor/disponibilidad` `{"en_linea": true}`.
2. `GET /repartidor/rutas` → ver las rutas de hoy; `siguiente_parada_id` dice por dónde seguir.
3. `POST /repartidor/paradas/{siguiente}/iniciar` → la parada pasa a `en_camino` (y la ruta a `en_curso`).
4. Al llegar: `POST …/entregar` con la foto, o `POST …/no-entregada` con el motivo.
5. Repetir con `ruta.siguiente_parada_id` hasta que `ruta.estado` sea `completada`.

---

## 8. Catálogo de códigos de error

Todos son `422` con `{ "mensaje", "codigo" }` salvo donde se indica. Úsalos para decidir qué mostrar; los mensajes están en español y se pueden mostrar tal cual.

**Cuentas**

| `codigo` | Significado |
|---|---|
| `invitacion_invalida` | El token de la invitación no existe |
| `invitacion_aceptada` | La invitación ya se aceptó: iniciar sesión |
| `invitacion_vencida` | La invitación venció: pedir que la reenvíen |
| `enlace_invalido` | El enlace de restablecer ya no sirve (usado, vencido o cuenta inactiva) |

**Empleado**

| `codigo` | Significado |
|---|---|
| `corte_cerrado` | Pasó el corte de esa fecha |
| `dia_sin_servicio` | Ese día no hay servicio para la empresa |
| `sin_contrato_vigente` | La empresa no tiene contrato vigente ese día |
| `platillo_no_disponible` | Platillo no publicado, inactivo o de otro tiempo de comida (también en pedidos de clientes) |
| `sin_porciones` | Sin porciones ese día (también en pedidos de clientes) |
| `empleado_inactivo` | Empleado o empresa desactivados |
| `sin_eleccion` | No hay elección que quitar |

**Calificaciones (empleado y cliente)**

| `codigo` | Significado |
|---|---|
| `fuera_de_plazo` | Solo se califica lo entregado en los últimos 14 días |
| `sin_entrega_que_calificar` | Solo se califica una entrega ya recibida |
| `sin_platillo_que_calificar` | Solo se califica un platillo ya recibido / que fue parte del pedido |
| `ya_calificada` | Ya calificó esto |

**Cliente: direcciones, pedidos y pago**

| `codigo` | Significado |
|---|---|
| `direccion_no_encontrada` | No se pudo ubicar la dirección en el mapa |
| `direccion_en_uso` | La dirección tiene pedidos y no se puede eliminar |
| `sin_cobertura` | La dirección está fuera de cobertura |
| `paquete_inactivo` | Paquete no disponible |
| `platillos_incompletos` | Faltan o sobran días de platillos |
| `comidas_por_dia_invalidas` | Cada día lleva de 1 a 3 comidas |
| `comidas_distintas_por_dia` | Los días deben llevar las mismas comidas |
| `extra_distinto_por_dia` | El extra va en todos los días o en ninguno |
| `tiempo_de_comida_invalido` | Solo `desayuno`, `comida`, `cena` (y `extra`) |
| `platillo_de_otro_tiempo` | El platillo no es de ese tiempo de comida |
| `sin_dia_de_entrega` | No hay sábados de entrega próximos |
| `pedido_no_pagable` | Solo se paga un pedido `pendiente_pago` |
| `pedido_vencido` | El plazo para pagar terminó; pedirlo de nuevo |
| `pagos_no_configurados` | (HTTP `503`) Stripe sin configurar en el servidor |

**Repartidor**

| `codigo` | Significado |
|---|---|
| `ruta_en_curso` | No puede ponerse fuera de línea con una ruta en curso |
| `fuera_de_linea` | Debe ponerse en línea para iniciar |
| `ruta_completada` | La ruta ya se completó |
| `ruta_de_otro_dia` | Solo se inician las rutas de hoy |
| `otra_ruta_en_curso` | Ya tiene otra ruta en curso |
| `parada_en_camino` | La parada ya está en camino |
| `parada_no_iniciada` | Primero hay que iniciar la parada |
| `parada_ya_atendida` | La parada ya se cerró |
| `orden_de_paradas` | Hay paradas anteriores sin atender |

**Generales (cualquier endpoint de `/api/v1`)**

| HTTP `codigo` | Significado |
|---|---|
| `401 no_autenticado` | Hace falta iniciar sesión |
| `403 sin_permiso` | Sin permiso o sin ficha de empleado/repartidor |
| `404 no_encontrado` | No existe o no es suyo |
| `429 demasiados_intentos` | Esperar (`Retry-After`) |

---

## 9. Cosas a tener en cuenta

Observaciones que salieron al revisar el código y probar la API. No se modificó nada del backend.

1. **Dos formatos de error** (ver [§1](#formato-de-errores)): `/api/auth` usa `message`/`errors`; `/api/v1` usa `mensaje`/`codigo`/`errores`. Además, el `403` por **rol equivocado** en `/api/v1` lo genera el middleware de roles y trae `message` (no `mensaje`), junto con `user_role` y `required_roles`.
2. **Si Stripe no responde** al iniciar un pago, la API no captura ese error (`PagoController` no maneja `ErrorDePasarela`): la app recibiría un `500`. Trátalo como «intenta de nuevo en un momento».
3. **`evidencia_foto_url`** y **`avatar_url`** son URL absolutas construidas con el `APP_URL` del servidor: confirma con Yahir que en producción sea el dominio público.
4. **`ubicacion` puede ser `null`** en paradas de empresa (empresa sin punto en el mapa). La `direccion` en texto siempre viene.
5. **`telefono` puede ser `null`** (si la persona de contacto no tiene teléfono en su perfil).
6. **Fechas de comida ≠ fecha de entrega** en clientes: el pedido llega el sábado, pero cada platillo es para un día de la semana siguiente. En `dias[]`, `fecha` es el día en que se come y `fecha_entrega` el sábado.
7. **El domingo no hay servicio** (ni para empresas ni para clientes); los días marcados sin servicio por el administrador tampoco.
8. **Pagos en modo de prueba:** Stripe está en modo de prueba. Tarjeta de prueba de Stripe: `4242 4242 4242 4242` (cualquier fecha futura y CVC). No hay cobros reales.
9. **Los ejemplos de este documento** usan cuentas y datos de ejemplo del seeder; los nombres, teléfonos y direcciones son ficticios.
10. **Rutas heredadas del equipo** que **no** documenta este archivo: `/api/menus`, `/api/providers`, `/api/catalog-categories` y los marcadores `/api/driver/routes` (devuelve solo un mensaje fijo; **no** es la API del repartidor: usa `/api/v1/repartidor/*`).

---

## 10. Colección de ejemplos con curl

Pensada para copiar y pegar en una terminal bash. **No incluye contraseñas ni tokens reales:** pon los tuyos en las variables.

```bash
# --- Variables ---------------------------------------------------------------
export BASE="https://easylunch-api.yahirdev.dev"      # local: http://localhost:8000
export H_JSON=(-H "Accept: application/json" -H "Content-Type: application/json")

# Guarda en TOKEN el token que devuelve el login (ver más abajo)
export TOKEN="<TOKEN>"
export AUTH=(-H "Authorization: Bearer $TOKEN")
```

### 10.1 Autenticación

```bash
# Estado de la API (público)
curl -s "$BASE/api/v1/salud" -H "Accept: application/json"

# Registro de un cliente
curl -s -X POST "$BASE/api/auth/register" "${H_JSON[@]}" \
  -d '{"name":"Luis Pérez","email":"luis.perez@ejemplo.test","password":"<CONTRASEÑA>","first_name":"Luis","last_name":"Pérez","phone":"33 1234 5678"}'

# Login (empleado, cliente o repartidor): copia "token" de la respuesta a TOKEN
curl -s -X POST "$BASE/api/auth/login" "${H_JSON[@]}" \
  -d '{"email":"ana@nexo.test","password":"<CONTRASEÑA>"}'

# Login y guardar el token automáticamente (requiere python3)
export TOKEN=$(curl -s -X POST "$BASE/api/auth/login" "${H_JSON[@]}" \
  -d '{"email":"ana@nexo.test","password":"<CONTRASEÑA>"}' | python3 -c 'import sys,json; print(json.load(sys.stdin)["token"])')
export AUTH=(-H "Authorization: Bearer $TOKEN")

# Perfil, actualizar perfil, avatar y logout
curl -s "$BASE/api/auth/profile" -H "Accept: application/json" "${AUTH[@]}"
curl -s -X PUT "$BASE/api/auth/profile" "${H_JSON[@]}" "${AUTH[@]}" -d '{"phone":"33 1111 2222"}'
curl -s -X POST "$BASE/api/auth/profile" -H "Accept: application/json" "${AUTH[@]}" -F _method=PUT -F avatar=@./foto.png
curl -s -X POST "$BASE/api/auth/logout" -H "Accept: application/json" "${AUTH[@]}"

# Cuentas (públicas): invitación y restablecer contraseña
curl -s "$BASE/api/v1/cuentas/invitaciones/<TOKEN_DEL_ENLACE>" -H "Accept: application/json"
curl -s -X POST "$BASE/api/v1/cuentas/invitaciones/<TOKEN_DEL_ENLACE>/aceptar" "${H_JSON[@]}" -d '{"contrasena":"<CONTRASEÑA_NUEVA>"}'
curl -s -X POST "$BASE/api/v1/cuentas/restablecer/solicitar" "${H_JSON[@]}" -d '{"correo":"ana@nexo.test"}'
curl -s "$BASE/api/v1/cuentas/restablecer/<TOKEN_DEL_ENLACE>" -H "Accept: application/json"
curl -s -X POST "$BASE/api/v1/cuentas/restablecer/<TOKEN_DEL_ENLACE>" "${H_JSON[@]}" -d '{"contrasena":"<CONTRASEÑA_NUEVA>"}'
```

### 10.2 Compartidos

```bash
curl -s "$BASE/api/v1/configuracion/operacion" -H "Accept: application/json" "${AUTH[@]}"
curl -s "$BASE/api/v1/paquetes" -H "Accept: application/json" "${AUTH[@]}"
curl -s "$BASE/api/v1/calificaciones/menu-semanal" -H "Accept: application/json" "${AUTH[@]}"
```

### 10.3 Empleado (token de un `employee`)

```bash
# Menú (por defecto: próxima fecha con el corte abierto, "comida")
curl -s "$BASE/api/v1/empleado/menu" -H "Accept: application/json" "${AUTH[@]}"
curl -s "$BASE/api/v1/empleado/menu?fecha=2026-11-24&tiempo_comida=desayuno" -H "Accept: application/json" "${AUTH[@]}"

# Elegir / cambiar el platillo de un día y tiempo de comida
curl -s -X PUT "$BASE/api/v1/empleado/elecciones" "${H_JSON[@]}" "${AUTH[@]}" \
  -d '{"fecha":"2026-11-23","tiempo_comida":"comida","platillo_id":3}'

# Quitar la elección
curl -s -X DELETE "$BASE/api/v1/empleado/elecciones" "${H_JSON[@]}" "${AUTH[@]}" \
  -d '{"fecha":"2026-11-23","tiempo_comida":"comida"}'

# Historial (últimas 8 por defecto)
curl -s "$BASE/api/v1/empleado/elecciones?limite=20" -H "Accept: application/json" "${AUTH[@]}"

# Calificaciones
curl -s "$BASE/api/v1/empleado/calificaciones/pendientes" -H "Accept: application/json" "${AUTH[@]}"
curl -s -X POST "$BASE/api/v1/empleado/calificaciones/entrega" "${H_JSON[@]}" "${AUTH[@]}" \
  -d '{"fecha":"2026-11-18","puntualidad":5,"trato_chofer":4,"empaque":4,"comentario":"Todo en orden"}'
curl -s -X POST "$BASE/api/v1/empleado/calificaciones/platillo" "${H_JSON[@]}" "${AUTH[@]}" \
  -d '{"fecha":"2026-11-14","tiempo_comida":"comida","estrellas":5}'
```

### 10.4 Cliente (token de un `customer`)

```bash
# Cuándo llega el próximo pedido y qué días de comida hay que llenar
curl -s "$BASE/api/v1/cliente/proxima-entrega" -H "Accept: application/json" "${AUTH[@]}"

# Menú de 6 días (usa el primer día de comida de proxima-entrega)
curl -s "$BASE/api/v1/cliente/menu-semanal?fecha_inicio=2026-11-30" -H "Accept: application/json" "${AUTH[@]}"

# Direcciones
curl -s "$BASE/api/v1/cliente/direcciones" -H "Accept: application/json" "${AUTH[@]}"
curl -s -X POST "$BASE/api/v1/cliente/direcciones" "${H_JSON[@]}" "${AUTH[@]}" \
  -d '{"etiqueta":"Casa","direccion":"Av. Chapultepec Sur 480","numero_interior":"3A","codigo_postal":"44150","latitud":20.6709,"longitud":-103.3667,"referencias":"Portón azul","predeterminada":true}'
curl -s -X PUT "$BASE/api/v1/cliente/direcciones/4" "${H_JSON[@]}" "${AUTH[@]}" -d '{"referencias":"Tocar el timbre dos veces"}'
curl -s -X DELETE "$BASE/api/v1/cliente/direcciones/4" -H "Accept: application/json" "${AUTH[@]}"

# Crear un pedido: paquete de 2 días, desayuno + comida + extra todos los días
curl -s -X POST "$BASE/api/v1/cliente/pedidos" "${H_JSON[@]}" "${AUTH[@]}" \
  -d '{"paquete_id":2,"direccion_id":4,"platillos":[{"desayuno":6,"comida":1,"extra":2},{"desayuno":6,"comida":1,"extra":2}]}'

# Listar / ver un pedido
curl -s "$BASE/api/v1/cliente/pedidos" -H "Accept: application/json" "${AUTH[@]}"
curl -s "$BASE/api/v1/cliente/pedidos/9" -H "Accept: application/json" "${AUTH[@]}"

# Iniciar el pago con tarjeta: devuelve el client_secret para el SDK de Stripe
curl -s -X POST "$BASE/api/v1/cliente/pedidos/9/pago" -H "Accept: application/json" "${AUTH[@]}"

# Calificar una entrega ya recibida
curl -s -X POST "$BASE/api/v1/cliente/pedidos/6/calificaciones/entrega" "${H_JSON[@]}" "${AUTH[@]}" \
  -d '{"fecha":"2026-11-12","puntualidad":5,"trato_chofer":5,"empaque":4,"comentario":"Llegó caliente"}'
curl -s -X POST "$BASE/api/v1/cliente/pedidos/6/calificaciones/platillo" "${H_JSON[@]}" "${AUTH[@]}" \
  -d '{"fecha":"2026-11-12","platillo_id":1,"estrellas":5}'
```

### 10.5 Repartidor (token de un `driver`)

```bash
# Disponibilidad
curl -s "$BASE/api/v1/repartidor/disponibilidad" -H "Accept: application/json" "${AUTH[@]}"
curl -s -X PATCH "$BASE/api/v1/repartidor/disponibilidad" "${H_JSON[@]}" "${AUTH[@]}" -d '{"en_linea":true}'

# Rutas de hoy (o de otra fecha) con sus paradas (incluye ubicacion y contacto_nombre)
curl -s "$BASE/api/v1/repartidor/rutas" -H "Accept: application/json" "${AUTH[@]}"
curl -s "$BASE/api/v1/repartidor/rutas?fecha=2026-11-28" -H "Accept: application/json" "${AUTH[@]}"

# Iniciar la siguiente parada
curl -s -X POST "$BASE/api/v1/repartidor/paradas/1/iniciar" -H "Accept: application/json" "${AUTH[@]}"

# Confirmar la entrega con la foto de evidencia (multipart, campo "foto")
curl -s -X POST "$BASE/api/v1/repartidor/paradas/1/entregar" -H "Accept: application/json" "${AUTH[@]}" -F "foto=@./evidencia.jpg"

# Marcar como no entregada (con motivo)
curl -s -X POST "$BASE/api/v1/repartidor/paradas/2/no-entregada" "${H_JSON[@]}" "${AUTH[@]}" \
  -d '{"motivo":"Nadie atendió en la entrada"}'
```

### 10.6 Flujo completo de un cliente (de cero a pedido por pagar)

```bash
# 1. Registro (o login) y token
export TOKEN=$(curl -s -X POST "$BASE/api/auth/register" "${H_JSON[@]}" \
  -d '{"name":"Luis Pérez","email":"luis.perez@ejemplo.test","password":"<CONTRASEÑA>","first_name":"Luis","last_name":"Pérez"}' \
  | python3 -c 'import sys,json; print(json.load(sys.stdin)["token"])')
export AUTH=(-H "Authorization: Bearer $TOKEN")

# 2. Una dirección con su punto del mapa (anota el "id" de la respuesta)
curl -s -X POST "$BASE/api/v1/cliente/direcciones" "${H_JSON[@]}" "${AUTH[@]}" \
  -d '{"direccion":"Av. Chapultepec Sur 480","codigo_postal":"44150","latitud":20.6709,"longitud":-103.3667}'

# 3. Cuándo llega y qué días de comida hay; los platillos de cada día
curl -s "$BASE/api/v1/cliente/proxima-entrega" -H "Accept: application/json" "${AUTH[@]}"
curl -s "$BASE/api/v1/cliente/menu-semanal?fecha_inicio=<PRIMER_DIA_DE_COMIDA>" -H "Accept: application/json" "${AUTH[@]}"

# 4. Pedir (sustituye los ids de paquete, dirección y platillos por los que obtuviste)
curl -s -X POST "$BASE/api/v1/cliente/pedidos" "${H_JSON[@]}" "${AUTH[@]}" \
  -d '{"paquete_id":1,"direccion_id":<ID_DIRECCION>,"platillos":[{"comida":<ID_PLATILLO>}]}'

# 5. Pagar: toma "client_secret" de la respuesta y úsalo con el SDK de Stripe en la app
curl -s -X POST "$BASE/api/v1/cliente/pedidos/<ID_PEDIDO>/pago" -H "Accept: application/json" "${AUTH[@]}"

# 6. Tras pagar en la app, consulta hasta que estado = "recibido" (lo confirma el webhook de Stripe)
curl -s "$BASE/api/v1/cliente/pedidos/<ID_PEDIDO>" -H "Accept: application/json" "${AUTH[@]}"
```
