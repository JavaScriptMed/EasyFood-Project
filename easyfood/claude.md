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
- Se entrega de lunes a sábado (empresas y clientes individuales); el domingo no hay servicio. El corte es a las 18:00 (Guadalajara) del día calendario anterior a la entrega; si el día no es de servicio o el corte ya pasó, el pedido pasa al siguiente día de servicio.
- Precio congelado al elegir = precio por platillo del contrato de la empresa; subsidio congelado = el del contrato. Cada empresa decide su porcentaje de subsidio; cada paquete, su descuento.
- Platillo del día (regla sin_eleccion = platillo_del_dia): cada comedor marca uno por día y tiempo de comida. Si varios comedores lo marcan, se asigna al que vaya más atrás en su compra mínima mensual: avance = platillos del mes / mínimo de su contrato (platillos del mes = órdenes de producción de los días anteriores del mes + lo ya elegido para la fecha que se corta). Empate: el de menos platillos del mes; si aún empatan, el de menor id. Si el elegido no tiene porciones libres, pasa al siguiente más atrás. Un comedor sin contrato vigente no participa.
- Cada dirección guarda su punto (PostGIS) y su zona se calcula sola dentro de los polígonos de `zonas`; fuera de toda zona queda «sin cobertura» y no sirve para pedir. Las entregas a clientes individuales entran a las rutas de su zona, después de las de empresas.
- Pedido por pagar: vence en el corte de su primer día de entrega; si pasa sin pagarse se cancela y hay que pedirlo de nuevo.
- Facturar un mes no se prorratea: se usa el contrato más reciente que toque ese mes. Se avisa a la empresa y a los administradores cuando un contrato vence ese mes o en los 7 días siguientes.
- Folios: EL-AAAAMM-NNN (facturas) y GDL-AAAAMMDD-NNNN (pedidos); el consecutivo nunca se repite.

## Reglas de trabajo
- Pregúntame antes de asumir una regla de negocio que no esté aquí.
- Una tarea a la vez; no toques archivos fuera de la tarea.
- Cada regla importante lleva prueba automatizada.
- Los montos y reglas de dinero nunca se calculan en el front.
- No pongas claves ni contraseñas en el repositorio; usa .env.