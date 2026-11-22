ESTADO ACTUAL DEL PROYECTO (actualizado 22 nov 2026)

Demo/exposición: 30 de noviembre de 2026. Prioridad: que todo funcione y sea operable; después lo bonito.

Quién hace qué
Yahir: backend (easyfood/), front web (frontend-reference/), servidor y Vercel.
Alberto: app móvil (pedidos y repartidor). Consume la API v1.
Brayan Javier: login, usuarios y roles. Daniel: líder.
NO modificar lo del compañero: tablas users, roles, providers (comedores), menus (platillos), catalog_* ni AuthController.
Infraestructura
Backend: Laravel 13 + PostgreSQL 17/PostGIS en un droplet de DigitalOcean con Docker. API: https://easylunch-api.yahirdev.dev (rutas /api/auth/... y /api/v1/...). Salud: /api/v1/salud.
Front: Next.js en Vercel (raíz frontend-reference), conectado al fork YAHIRCODE/EasyFood-Project. Tras fusionar en JavaScriptMed hay que hacer Sync fork. NEXT_PUBLIC_API_URL es solo el dominio, sin /api.
Stripe en modo prueba. El webhook ya está configurado en el servidor y funciona.
Respaldo diario de la base a las 3:00 (cron en el servidor).
Correo del servidor en modo log (no envía; enlaces de invitación en storage/logs/enlaces.log).
API del repartidor y zonas
API del repartidor (rama feat/api-repartidor): solo ve rutas aprobadas; solo inicia la ruta del día que le toca; una ruta en curso a la vez; paradas en orden sin saltos; fuera de línea no inicia paradas; con ruta en curso no puede salir de línea. Las rutas se asignan automáticamente; él no las escoge.
Zonas: polígonos GeoJSON; la zona se calcula con PostGIS. Los nombres de zona deben coincidir exactamente con los de empresas y repartidores.
Estado de ramas
master: incluye la entrega semanal de individuales (desplegada en el servidor).
feat/api-repartidor: PR abierto contra master; falta revisar, respaldar la base y fusionar.
feat/datos-demo-produccion (commit 92a50c6, comando easylunch:cargar-demo --confirmo): lista y probada; falta subirla y fusionarla DESPUÉS de feat/api-repartidor. Si se carga la demo antes de migrar la API del repartidor, esa migración puede fallar.
Orden de despliegue: respaldo de la base → fusionar feat/api-repartidor y migrar → fusionar datos de demo → correr easylunch:cargar-demo en el servidor (cuentas @demo.easylunch.test).
Mapas con Mapbox (Tarea B): pendiente. Solo B.1 (Dashboard y Rutas del admin) y B.2 (Nueva Empresa con punto y lat/lng en empresas). El mapa del repartidor lo hace Alberto en su app. Se trabaja desde master actualizado, en copia limpia, y el commit lleva solo mapbox-gl.
Pendientes
Dibujar e importar 3 zonas reales (Zapopan, Tlaquepaque, Guadalajara), sin traslapes, ANTES de cargar la demo.
Mapas con Mapbox (token público en .env.local y en Vercel; nunca en el repo; restringido por dominio).
Cambiar la contraseña del admin de prueba y ponerle precio realista al platillo "teriyki".
Corregir en el front: arrastrar platillos en Disponibilidad no funciona; contraste bajo en el login del portal de comedores.
Cambios sin guardar en package.json y package-lock.json (next ^16.4.6, motion, overrides de minimatch): revisar y subir aparte; no mezclarlos con otras tareas.
Reglas de trabajo de ramas y despliegue (obligatorias)
Una rama nueva desde master por tarea. Nunca commitear ni fusionar directo a master.
Antes de commitear: PHPUnit completo y Bruno completos en verde.
No subir package.json, package-lock.json, .vscode/ ni billing-data.ts salvo que la tarea lo pida.
Toda migración requiere respaldo de la base antes de fusionar (scripts/respaldo-db.sh --archivos).
Nunca poner contraseñas, claves ni tokens en el repo ni en este archivo.
No cambiar la lógica de negocio sin que Yahir lo pida.
Después de cada fusión, actualizar "Estado de ramas" y "Pendientes" de este archivo.
Guía visual del front
Paleta: negro y dorado. Pocas animaciones, limpias, que no cansen.
Ideas aprobadas: pantalla de carga con camioneta y barra; portada con olla y partículas suaves; tenedor siguiendo el cursor; carrusel de platillos con tarjetas en ángulo; fondo oscuro con dibujos de cubiertos en trazo dorado muy tenue.
Desactivar partículas en celular y si el usuario prefiere menos movimiento.
EasyLunch · backend

Laravel (última versión estable) + Sanctum + PostgreSQL con PostGIS. API JSON en /api/v1. Nombres de tablas, columnas y rutas en español y snake_case, iguales a los tipos de /lib/types.ts del front. Zona horaria America/Mexico_City. Montos en numeric(10,2) MXN. Fechas y horas en timestamptz.

Negocio
Roles: administrador, soporte, comedor, empresa (RR. HH.), empleado, cliente, repartidor.
Contrato con comedor: 6 meses, precio fijo de $49 por platillo, compra mínima de 200 platillos al mes. Si EasyLunch compra menos, paga la diferencia.
Contrato con empresa: 6 meses, compra mínima mensual de platillos, porcentaje de subsidio (0 a 100), dirección y horario de entrega, un solo punto de entrega por día.
Los comedores suben sus platillos (estados: borrador, en_revision, publicado, rechazado) y marcan disponibilidad por día con porciones máximas.
Cada empleado elige un platillo por día y por tiempo de comida hasta el corte (6:00 p. m., configurable). Después del corte no se cambia.
Si no eligió, se aplica la regla configurada: ultimo_platillo, platillo_del_dia o no_entregar.
Al elegir se congelan el precio y el porcentaje de subsidio; cambios de contrato no tocan lo ya elegido.
Corte diario: cierra elecciones, genera órdenes de producción por comedor, consolida una entrega por empresa y día, y crea rutas. Debe ser idempotente.
Facturación mensual: una factura por empresa. Total = platillos entregados × precio congelado; parte de la empresa = subsidio %; parte de los empleados = resto. Si el consumo queda bajo el mínimo, se agrega (mínimo − consumo) × precio unitario del contrato.
Clientes individuales: paquetes de 1, 2 o 6 días, y el cliente elige 1, 2 o 3 comidas por día (desayuno, comida y/o cena, las mismas todos los días); puede sumar un platillo extra por día (de cualquier tiempo de comida, con el descuento del paquete); UNA sola entrega por pedido, el sábado (todo llega junto: el cliente sigue eligiendo platillos por día, que son los días de servicio de la semana siguiente a la entrega); el pago es con tarjeta mediante Stripe en modo de prueba (PaymentIntent + webhook firmado e idempotente; nunca se guardan datos de tarjeta; claves solo en .env). Reembolsos siempre totales y sin estado nuevo (el pedido queda cancelado con reembolsado_en, monto_reembolsado y stripe_refund_id): automático si el cobro llega a un pedido cancelado o vencido, o por administración/soporte con bitácora de quién lo hizo.
Solo autos como vehículo; capacidad por paradas y por platillos.
Las empresas reciben de lunes a sábado; el domingo no hay servicio. El corte de empleados y empresas es a las 18:00 (Guadalajara) del día calendario anterior a la entrega; si el día no es de servicio o el corte ya pasó, la elección pasa al siguiente día de servicio.
Los clientes individuales reciben UNA vez por semana, el sábado: los pedidos de lunes a viernes antes de las 18:00 del viernes entran al sábado de esa semana; desde el viernes a las 18:00 y el sábado mismo pasan al sábado siguiente; un sábado festivo se salta al siguiente. El sábado salen a repartir. Tienen su propio corte (CorteDeIndividuales, cortes.tipo = 'individuales', el viernes a la hora de corte, después del general) que cancela lo sin pagar, suma sus porciones a las órdenes de producción y arma sus rutas, separadas de las de empresas (rutas de clientes desde las 12:00, o cuando su repartidor termina la de empresas). Las porciones se apartan en el día en que se come, no el sábado.
Precio congelado al elegir = precio por platillo del contrato de la empresa; subsidio congelado = el del contrato. Cada empresa decide su porcentaje de subsidio; cada paquete, su descuento.
Platillo del día (regla sin_eleccion = platillo_del_dia): cada comedor marca uno por día y tiempo de comida. Si varios comedores lo marcan, se asigna al que vaya más atrás en su compra mínima mensual: avance = platillos del mes / mínimo de su contrato (platillos del mes = órdenes de producción de los días anteriores del mes + lo ya elegido para la fecha que se corta). Empate: el de menos platillos del mes; si aún empatan, el de menor id. Si el elegido no tiene porciones libres, pasa al siguiente más atrás. Un comedor sin contrato vigente no participa.
Cada dirección guarda su punto (PostGIS) y su zona se calcula sola dentro de los polígonos de zonas; fuera de toda zona queda «sin cobertura» y no sirve para pedir. Las entregas a clientes individuales (el sábado) van en rutas de su zona separadas de las de empresas.
Pedido por pagar: vence el viernes a la hora de corte anterior a su entrega del sábado; si pasa sin pagarse se cancela y hay que pedirlo de nuevo.
Facturar un mes no se prorratea: se usa el contrato más reciente que toque ese mes. Se avisa a la empresa y a los administradores cuando un contrato vence ese mes o en los 7 días siguientes.
Folios: EL-AAAAMM-NNN (facturas) y GDL-AAAAMMDD-NNNN (pedidos); el consecutivo nunca se repite.
Reglas de trabajo
Pregúntame antes de asumir una regla de negocio que no esté aquí.
Una tarea a la vez; no toques archivos fuera de la tarea.
Cada regla importante lleva prueba automatizada.
Los montos y reglas de dinero nunca se calculan en el front.
No pongas claves ni contraseñas en el repositorio; usa .env.
API
Rutas en routes/v1.php, JSON en español; errores { mensaje, codigo?, errores? }. Los campos salen con los nombres de /lib/types.ts (se traducen en App\Http\Resources\V1\Vistas).
Los controladores son delgados: la regla de negocio vive en app/Services y la API solo valida, autoriza y presenta. Los montos nunca se calculan en el controlador.
Cuentas: invitar, aceptar la invitación y restablecer la contraseña son reales (Invitaciones, RestablecimientoDeContrasena). Del enlace solo se guarda el hash; se ve en storage/logs/enlaces.log en desarrollo y en el servidor mientras MAIL_MAILER=log. Una persona invitada a una empresa o comedor trabaja con los datos de esa organización (ContextoDelUsuario).
HistorialDePrecios lleva la línea de tiempo de menus.price (tabla propia precios_de_platillos; los controladores que editan el precio llaman a asegurarPunto antes y a sincronizar después) e ImagenesDePlatillos guarda las fotos. EdicionDeRegistros concentra las reglas de editar y desactivar (empresas, empleados, planes, repartidores, capacidad del auto y personas con acceso; nada se borra). GeneradorDeRutas arma las rutas (lo usan el corte y POST /admin/rutas/generar, que nunca toca rutas existentes) y ReasignacionDeRutas calcula candidatos y reasigna. Las altas (empresa, comedor, repartidor, empleados) viven en AltaDeCuentas y reutilizan Invitaciones (cuenta inactiva + invitación). Los endpoints complejos y las imágenes están declarados pero apuntan a NoImplementadoController (501) hasta construirse. El dashboard ya es real: 10 indicadores calculados al pedirlos (IndicadoresDelDashboard), con calificaciones (1 a 5, solo de lo entregado), costos operativos y los parámetros ahorro_apps_porcentaje y costo_maquila_limite en la configuración.