# EasyLunch · backend

Laravel (última versión estable) + Sanctum + PostgreSQL con PostGIS. API JSON en /api/v1. Nombres de tablas, columnas y rutas en español y snake_case, iguales a los tipos de /lib/types.ts del front. Zona horaria America/Mexico_City. Montos en numeric(10,2) MXN. Fechas y horas en timestamptz.

## Negocio
- Roles: administrador, soporte, comedor, empresa (RR. HH.), empleado, cliente, repartidor.
- Contrato con comedor: 6 meses, precio fijo de $49 por platillo, compra mínima de 200 platillos al mes. Si EasyLunch compra menos, paga la diferencia.
- Contrato con empresa: 6 meses, compra mínima mensual de platillos, porcentaje de subsidio (0 a 100), dirección y horario de entrega, un solo punto de entrega por día.
- Los comedores suben sus platillos (estados: borrador, en_revision, publicado, rechazado) y marcan disponibilidad por día con porciones máximas.
- Cada empleado elige un platillo por día y por tiempo de comida hasta el corte (6:00 p. m., configurable). Después del corte no se cambia.
- Si no eligió, se aplica la regla configurada: ultimo_platillo, platillo_del_dia o no_entregar.
- Al elegir se congelan el precio y el porcentaje de subsidio; cambios de contrato no tocan lo ya elegido.
- Corte diario: cierra elecciones, genera órdenes de producción por comedor, consolida una entrega por empresa y día, y crea rutas. Debe ser idempotente.
- Facturación mensual: una factura por empresa. Total = platillos entregados × precio congelado; parte de la empresa = subsidio %; parte de los empleados = resto. Si el consumo queda bajo el mínimo, se agrega (mínimo − consumo) × precio unitario del contrato.
- Clientes individuales: paquetes de 1, 2 o 6 días; el pago es simulado (no hay cobro real).
- Solo autos como vehículo; capacidad por paradas y por platillos.

## Reglas de trabajo
- Pregúntame antes de asumir una regla de negocio que no esté aquí.
- Una tarea a la vez; no toques archivos fuera de la tarea.
- Cada regla importante lleva prueba automatizada.
- Los montos y reglas de dinero nunca se calculan en el front.
- No pongas claves ni contraseñas en el repositorio; usa .env.