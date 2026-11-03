import type { BloqueHorario, EstadoEntrega, EstadoIncidencia, Rol, TipoIncidencia, EstadoFactura, EstadoLiquidacion, EstadoPedido, EstadoRepartidor, EstadoRevision, EstadoTicket, TiempoComida, TipoVehiculo } from './types'

export const etiquetasEstadoEntrega: Record<EstadoEntrega, string> = {
  programada: 'Programada',
  en_consolidacion: 'En consolidación',
  en_ruta: 'En ruta',
  entregada: 'Entregada',
  no_localizado: 'No localizado',
  reprogramada: 'Reprogramada',
  cancelada: 'Cancelada',
}

export const clasesEstadoEntrega: Record<EstadoEntrega, string> = {
  programada: 'bg-muted text-muted-foreground',
  en_consolidacion: 'bg-primary/20 text-primary-foreground dark:text-primary',
  en_ruta: 'bg-info/15 text-info',
  entregada: 'bg-success/15 text-success',
  no_localizado: 'bg-warning/15 text-warning',
  reprogramada: 'bg-violet-500/15 text-violet-700 dark:text-violet-300',
  cancelada: 'bg-destructive/15 text-destructive',
}

export const etiquetasVehiculo: Record<TipoVehiculo, string> = { bicicleta: 'Bicicleta', motocicleta: 'Motocicleta', automovil: 'Automóvil' }
export const etiquetasVehiculoCorto: Record<TipoVehiculo, string> = { bicicleta: 'Bici', motocicleta: 'Moto', automovil: 'Carro' }
export const clasesVehiculo: Record<TipoVehiculo, string> = {
  bicicleta: 'bg-success/10 text-success',
  motocicleta: 'bg-warning/10 text-warning',
  automovil: 'bg-info/10 text-info',
}

export const etiquetasEstadoRepartidor: Record<EstadoRepartidor, string> = { libre: 'Libre', asignado: 'Asignado', en_ruta: 'En ruta', no_disponible: 'No disponible' }
export const etiquetasBloque: Record<BloqueHorario, string> = { manana: 'Mañana', mediodia: 'Mediodía', tarde: 'Tarde' }
export const etiquetasTiempoComida: Record<TiempoComida, string> = { desayuno: 'Desayuno', comida: 'Comida', cena: 'Cena' }
export const etiquetasRevision: Record<EstadoRevision, string> = { borrador: 'borrador', en_revision: 'en revisión', publicado: 'publicado', rechazado: 'rechazado' }
export const etiquetasEstadoFactura: Record<EstadoFactura, string> = { borrador: 'Borrador', emitida: 'Emitida', pagada: 'Pagada' }
export const etiquetasEstadoLiquidacion: Record<EstadoLiquidacion, string> = { pendiente: 'Pendiente', pagado: 'Pagado' }
export const etiquetasEstadoTicket: Record<EstadoTicket, string> = { abierto: 'abierto', en_proceso: 'en proceso', resuelto: 'resuelto', cerrado: 'cerrado' }
export const etiquetasEstadoPedido: Record<EstadoPedido, string> = { pendiente_pago: 'Pendiente de pago', recibido: 'Recibido', activo: 'Activo', completado: 'Completado', cancelado: 'Cancelado' }

export const capitalizar = (texto: string) => `${texto[0].toUpperCase()}${texto.slice(1)}`

export const etiquetasTipoIncidencia: Record<TipoIncidencia, string> = {
  empresa_cerrada: 'Empresa cerrada',
  empleado_ausente: 'Empleado ausente',
  repartidor_no_llego: 'Repartidor no llegó',
  platillo_danado: 'Platillo dañado',
  falta_repartidores: 'Faltan repartidores',
  pago_pendiente: 'Pago pendiente',
  ruta_excede_bloque: 'Ruta excede el bloque',
  entrega_no_localizada: 'Entrega no localizada',
  otro: 'Otro',
}
/** Los tipos que se reportan desde una ruta (los demás son alertas generales del panel). */
export const tiposIncidenciaRuta: TipoIncidencia[] = ['empresa_cerrada', 'empleado_ausente', 'repartidor_no_llego', 'platillo_danado']
export const etiquetasEstadoIncidencia: Record<EstadoIncidencia, string> = { abierta: 'Abierta', reasignada: 'Reasignada', resuelta: 'Resuelta' }
export const clasesEstadoIncidencia: Record<EstadoIncidencia, string> = { abierta: 'bg-destructive/10 text-destructive', reasignada: 'bg-sky-500/10 text-sky-600', resuelta: 'bg-emerald-500/10 text-emerald-600' }
export const etiquetasRol: Record<Rol, string> = { administrador: 'Administrador', soporte: 'Soporte', comedor: 'Comedor', empresa: 'Empresa', empleado: 'Empleado', cliente: 'Cliente', repartidor: 'Repartidor' }
export const etiquetasEntidad: Record<string, string> = {
  usuario: 'Usuario', platillo: 'Platillo', empresa: 'Empresa', comedor: 'Comedor', empleado: 'Empleado', repartidor: 'Repartidor', ruta: 'Ruta', incidencia: 'Incidencia', pedido: 'Pedido', ticket: 'Ticket',
  corte: 'Corte', factura_mensual: 'Factura', liquidacion_comedor: 'Liquidación', disponibilidad_dia: 'Disponibilidad', orden_produccion: 'Producción',
  configuracion_operacion: 'Configuración', dia_sin_servicio: 'Día de servicio', contrato_empresa: 'Contrato de empresa', contrato_comedor: 'Contrato de comedor', eleccion_empleado: 'Elecciones',
}
