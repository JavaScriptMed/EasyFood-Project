// Un tipo por entidad. Los campos usan snake_case en español para coincidir con las columnas de la base de datos;
// los ids son strings (el backend puede cambiarlos a number sin tocar las pantallas, que solo los pasan de largo).

export type Rol = 'administrador' | 'soporte' | 'comedor' | 'empresa' | 'empleado' | 'cliente' | 'repartidor'
export type TiempoComida = 'desayuno' | 'comida' | 'cena'
export type EstadoRevision = 'borrador' | 'en_revision' | 'publicado' | 'rechazado'
export type OrigenEleccion = 'manual' | 'por_defecto'
export type EstadoEntrega = 'programada' | 'en_consolidacion' | 'en_ruta' | 'entregada' | 'no_localizado' | 'reprogramada' | 'cancelada'
export type TipoVehiculo = 'bicicleta' | 'motocicleta' | 'automovil'
export type EstadoRepartidor = 'libre' | 'asignado' | 'en_ruta' | 'no_disponible'
export type BloqueHorario = 'manana' | 'mediodia' | 'tarde'
export type EstadoRuta = 'borrador' | 'aprobada'
export type EstadoParada = 'pendiente' | 'entregado' | 'no_localizado'
export type EstadoOrdenProduccion = 'pendiente' | 'lista'
export type EstadoFactura = 'borrador' | 'emitida' | 'pagada'
export type EstadoLiquidacion = 'pendiente' | 'pagado'
export type EstadoTicket = 'abierto' | 'en_proceso' | 'resuelto' | 'cerrado'
export type EstadoPedido = 'pendiente_pago' | 'recibido' | 'activo' | 'completado' | 'cancelado'
export type EstadoContrato = 'activo' | 'por_vencer' | 'vencido'
export type TipoIncidencia = 'falta_repartidores' | 'pago_pendiente' | 'ruta_excede_bloque' | 'entrega_no_localizada' | 'empresa_cerrada' | 'empleado_ausente' | 'repartidor_no_llego' | 'platillo_danado' | 'otro'
export type EstadoIncidencia = 'abierta' | 'reasignada' | 'resuelta'
export type PrioridadIncidencia = 'normal' | 'inmediata'

export interface Usuario {
  id: string
  nombre: string
  correo: string
  telefono: string | null
  rol: Rol
  activo: boolean
}

export interface Comedor {
  id: string
  usuario_id: string // contacto con acceso al portal del comedor
  nombre: string
  zona: string
  activo: boolean
}

export interface Empresa {
  id: string
  usuario_id: string // contacto de RR. HH. con acceso al portal de empresas
  nombre: string
  dominio: string
  zona: string
  direccion_entrega: string
  activo?: boolean // si falta, se trata como activa
}

export interface ContratoComedor {
  id: string
  comedor_id: string
  precio_por_platillo: number
  compra_minima_mensual: number
  vigencia_inicio: string // YYYY-MM-DD
  vigencia_fin: string
}

export interface ContratoEmpresa {
  id: string
  empresa_id: string
  porcentaje_subsidio: number // 0 a 100
  compra_minima_mensual: number
  precio_por_platillo: number
  vigencia_inicio: string
  vigencia_fin: string
  hora_entrega: string // hora estimada, HH:mm
  ventana_entrega_inicio: string
  ventana_entrega_fin: string
}

export interface Empleado {
  id: string
  usuario_id: string
  empresa_id: string
  departamento: string | null
  activo: boolean
}

export interface Platillo {
  id: string
  comedor_id: string
  nombre: string
  descripcion: string
  tiempo_comida: TiempoComida
  calorias: number
  alergenos: string[]
  etiquetas: string[]
  precio_venta: number
  foto_url: string | null
  estado_revision: EstadoRevision
  motivo_rechazo: string | null
  activo: boolean
}

export interface DisponibilidadDia {
  id: string
  platillo_id: string
  fecha: string
  porciones_maximas: number
  porciones_tomadas: number
}

export interface EleccionEmpleado {
  id: string
  empleado_id: string
  fecha: string
  tiempo_comida: TiempoComida
  platillo_id: string
  precio_congelado: number
  porcentaje_subsidio_congelado: number
  origen: OrigenEleccion
}

export interface OrdenProduccion {
  id: string
  comedor_id: string
  platillo_id: string
  fecha: string
  porciones_totales: number
  porciones_empresas: number
  porciones_individuales: number
  hora_recoleccion: string
  estado: EstadoOrdenProduccion
}

export interface EntregaEmpresa {
  id: string
  empresa_id: string
  ruta_id: string | null
  fecha: string
  zona: string
  ventana_inicio: string
  ventana_fin: string
  estado: EstadoEntrega
  tipo_vehiculo: TipoVehiculo
}

export interface Repartidor {
  id: string
  usuario_id: string
  numero_empleado: string
  tipo_vehiculo: TipoVehiculo
  vehiculo_personalizado: boolean
  placas: string | null
  zona_base: string
  estado: EstadoRepartidor
  bloques_reservados: BloqueHorario[]
  entregas_asignadas: number
  entregas_completadas: number
  ultima_ubicacion_en: string // ISO
  activo?: boolean // si falta, se trata como activo
}

export interface Ruta {
  id: string
  fecha: string
  repartidor_id: string
  tipo_vehiculo: TipoVehiculo
  zona: string
  bloque: BloqueHorario
  ventana_inicio: string
  ventana_fin: string
  capacidad_maxima: number
  entregas_asignadas: number
  distancia_km: number
  hora_fin_estimada: string
  excede_bloque: boolean
  estado: EstadoRuta
}

export interface Parada {
  id: string
  ruta_id: string
  orden: number
  entrega_id: string | null
  nombre_destinatario: string
  direccion: string
  hora_estimada: string
  estado: EstadoParada
}

export interface Incidencia {
  id: string
  fecha: string
  tipo: TipoIncidencia
  descripcion: string
  prioridad: PrioridadIncidencia
  estado: EstadoIncidencia
  hora: string | null // HH:mm
  foto_url: string | null // evidencia
  nota: string | null
  repartidor_reasignado_id: string | null
  ruta_id: string | null
  entrega_id: string | null
}

export interface FacturaMensual {
  id: string // folio
  empresa_id: string
  mes: string // YYYY-MM
  platillos: number
  parte_empresa: number
  parte_empleados: number
  platillos_faltantes: number
  diferencia_minimo: number
  total: number
  estado: EstadoFactura
  emitida_en: string | null
  pagada_en: string | null
}

export interface LiquidacionComedor {
  id: string
  comedor_id: string
  mes: string
  platillos_entregados: number
  precio_por_platillo: number
  platillos_minimos: number
  platillos_faltantes: number
  diferencia_minimo: number // platillos faltantes × precio; se paga al comedor aunque no se hayan entregado
  monto: number // entregados × precio + diferencia por mínimo
  estado: EstadoLiquidacion
  pagada_en: string | null
}

export interface Ticket {
  id: string
  cliente_id: string
  asunto: string
  pedido_folio: string
  estado: EstadoTicket
  mensajes_sin_leer: number
  actualizado_en: string // ISO
}

export interface RegistroAuditoria {
  id: string
  usuario_id: string | null // null = el sistema
  accion: string
  entidad: string
  entidad_id: string
  detalle: string
  creado_en: string // ISO
}

// ---------------------------------------------------------------- apoyo (no son entidades principales)

export interface MensajeTicket {
  id: string
  ticket_id: string
  autor: 'cliente' | 'agente'
  texto: string
  enviado_en: string
}

export interface Plan {
  id: string
  nombre: string
  descuento_porcentaje: number
  detalle: string
  destacado: boolean
}

export interface Pedido {
  folio: string
  cliente_id: string
  plan_id: string
  fecha_inicio: string
  fecha_fin: string
  zona: string
  bloque: BloqueHorario
  total: number
  estado: EstadoPedido
  entregas_totales: number
  entregas_completadas: number
  direccion_entrega: string
  referencias: string
  metodo_pago: string
  ultimos_digitos: string | null
  tiene_evidencia: boolean
}

export interface HistorialPrecio {
  id: string
  platillo_id: string
  vigente_desde: string
  vigente_hasta: string | null
  precio_venta: number
  costo: number
}

export interface ResumenDashboard {
  entregas_hoy: number
  entregas_completadas: number
  pedidos_activos: number
  en_consolidacion: number
  en_ruta: number
  ingresos_semana: number
  variacion_ingresos_porcentaje: number
  pedidos_por_dia: { dia: string; pedidos: number }[]
  entregas_por_estado: { estado: string; cantidad: number; color: string }[]
  zonas_activas: { zona: string; x: string; y: string }[]
}

// ---------------------------------------------------------------- vistas que arma la API (joins y totales)

export interface UsuarioResumen extends Pick<Usuario, 'id' | 'nombre' | 'correo' | 'telefono'> {}

export interface ComedorDetalle extends Comedor {
  contacto: UsuarioResumen
  contrato: ContratoComedor
  platillos_publicados: number
  platillos_entregados_mes: number
  avance_minimo_porcentaje: number
  estado_contrato: EstadoContrato
  dias_para_vencer: number
}

export interface EmpresaDetalle extends Empresa {
  contacto: UsuarioResumen
  contrato: ContratoEmpresa
  total_empleados: number
  platillos_mes: number
  estado_contrato: EstadoContrato
  dias_para_vencer: number
}

export interface EmpleadoDetalle extends Empleado {
  nombre: string
  correo: string
  platillos_por_mes: Record<string, number>
  invitacion: InvitacionDetalle | null
  eleccion_manana: { platillo_id: string; platillo_nombre: string; origen: OrigenEleccion } | null
}

export interface PlatilloDetalle extends Platillo {
  comedor_nombre: string
}

export interface EntregaDetalle extends EntregaEmpresa {
  empresa_nombre: string
}

export interface RepartidorDetalle extends Repartidor {
  nombre: string
}

export interface ParadaConEstado extends Parada {}

export interface RutaDetalle extends Ruta {
  repartidor_nombre: string
  paradas: Parada[]
}

export interface PedidoDetalle extends Pedido {
  cliente_nombre: string
  plan_nombre: string
}

export interface TicketDetalle extends Ticket {
  cliente_nombre: string
  mensajes: MensajeTicket[]
  resumen_pedido: {
    plan: string
    proxima_entrega: string
    zona: string
    total: number
    direccion: string
    referencias: string
  }
}

export interface LineaConsumoEmpleado {
  empleado_id: string
  nombre: string
  correo: string
  platillos: number
  importe: number
  parte_empresa: number
  parte_empleado: number
}

export interface FacturaDetalle extends FacturaMensual {
  importe_consumo: number // parte de la empresa + parte de los empleados
  lineas: LineaConsumoEmpleado[]
}

export interface FacturaConEmpresa extends FacturaMensual {
  empresa: EmpresaDetalle
}

export interface LiquidacionConComedor extends LiquidacionComedor {
  comedor: Comedor
}

export interface OrdenProduccionDetalle extends OrdenProduccion {
  platillo_nombre: string
  tiempo_comida: TiempoComida
}

export interface DisponibilidadConPlatillo extends DisponibilidadDia {
  platillo_nombre: string
}

export interface PedidosMananaEmpresa {
  fecha: string
  total: number
  grupos: { platillo_id: string; platillo_nombre: string; personas: { empleado_id: string; nombre: string }[] }[]
  pendientes: { empleado_id: string; nombre: string }[]
}

export interface FilaReporteProveedor {
  comedor_nombre: string
  platillo_nombre: string
  tiempo_comida: TiempoComida
  porciones: number
  costo_total: number
}

export interface MenuSemanalDia {
  fecha: string
  celdas: Partial<Record<TiempoComida, { platillo_id: string; platillo_nombre: string; precio_venta: number; porciones_maximas: number }>>
}

export interface ResultadoAltaEmpleados {
  agregados: number
  omitidos: number
}

// ---------------------------------------------------------------- entradas y vistas adicionales de la API

export interface DashboardAdmin {
  resumen: ResumenDashboard
  alertas: Incidencia[]
  incidencias_del_dia: number
  requieren_atencion: number
}

export interface TicketResumen extends Ticket {
  cliente_nombre: string
}

export interface RegistroAuditoriaDetalle extends RegistroAuditoria {
  usuario_nombre: string | null
  usuario_rol: Rol | null
}

export interface NuevaEmpresa {
  nombre: string
  contacto_rrhh: string // "Nombre y correo", tal como lo captura el formulario
  vigencia_inicio: string
  compra_minima_mensual: number
  porcentaje_subsidio: number
  direccion_entrega: string
  horario_entrega: string // "12:30–13:15"
}

export interface NuevoRepartidor {
  nombre: string
  correo: string
  telefono: string
  numero_empleado: string
  tipo_vehiculo: TipoVehiculo
  placas: string
  zona_base: string
}

// Entradas de las ventanas de edición. Nada se borra: "eliminar" es desactivar (cambiarEstado…), y se puede reactivar.
export interface EdicionEmpresa {
  nombre: string
  direccion_entrega: string
  contacto_nombre: string
  contacto_telefono: string
  porcentaje_subsidio: number
  compra_minima_mensual: number
  ventana_entrega_inicio: string
  ventana_entrega_fin: string
}

export interface EdicionComedor {
  nombre: string
  zona: string
  contacto_nombre: string
  contacto_telefono: string
  precio_por_platillo: number
  compra_minima_mensual: number
}

export interface EdicionRepartidor {
  nombre: string
  telefono: string
  numero_empleado: string
  tipo_vehiculo: TipoVehiculo
  placas: string
  zona_base: string
}

export interface EdicionEmpleado {
  nombre: string
  departamento: string
}

export interface EdicionPlan {
  nombre: string
  descuento_porcentaje: number
  detalle: string
  destacado: boolean
}

export interface EdicionUsuarioAcceso {
  nombre: string
  rol: Rol
}

export interface ProgramarPrecio {
  precio_venta: number
  vigente_desde: string // YYYY-MM-DD
}

export interface GuardarPlatillo {
  id?: string
  comedor_id: string
  nombre: string
  descripcion: string
  tiempo_comida: TiempoComida
  calorias: number
  alergenos: string[]
  precio_venta?: number
  estado_revision: EstadoRevision
}

export interface CambioDisponibilidad {
  platillo_id: string
  fecha: string
  porciones_maximas: number
}

// ---------------------------------------------------------------- operación: configuración, calendario y cortes

export type ReglaSinEleccion = 'ultimo_platillo' | 'platillo_del_dia' | 'no_entregar'
export type DiasEntrega = 'lunes_viernes' | 'lunes_sabado'
export type TipoDiaSinServicio = 'festivo' | 'sin_servicio' | 'cierre_empresa'
export type EstadoCorte = 'correcto' | 'con_advertencias' | 'fallido'

export interface ConfiguracionOperacion {
  hora_corte: string // HH:mm
  zona_horaria: string
  dias_entrega: DiasEntrega
  regla_sin_eleccion: ReglaSinEleccion
  capacidad_auto_entregas: number
  capacidad_auto_platillos: number
}

export interface DiaSinServicio {
  id: string
  fecha: string
  tipo: TipoDiaSinServicio
  motivo: string
  empresa_id: string | null // solo en "cierre_empresa"
}

export interface Corte {
  id: string
  fecha_entrega: string
  ejecutado_en: string // ISO
  ejecutado_por: string | null // null = automático a la hora de corte
  estado: EstadoCorte
  sin_cambios: boolean
  elecciones_cerradas: number
  empleados_por_defecto: number
  ordenes_generadas: number
  entregas_consolidadas: number
  rutas_creadas: number
  mensaje: string | null
}

export interface DiaSinServicioDetalle extends DiaSinServicio {
  empresa_nombre: string | null
}

export interface CalendarioServicio {
  mes: string
  dias: DiaSinServicioDetalle[]
  fechas_cerradas: string[] // días cuyo corte ya se ejecutó
}

export interface VistaPreviaCorte {
  fecha_entrega: string
  hora_corte: string
  regla_sin_eleccion: ReglaSinEleccion
  ya_cerrado: boolean
  empleados_activos: number
  sin_elegir: number
}

export interface ResultadoCorte {
  corte: Corte
  fecha_entrega: string
  sin_cambios: boolean
  elecciones_cerradas: number
  empleados_por_defecto: { empleado_id: string; nombre: string; empresa_nombre: string; platillo_nombre: string }[]
  empleados_sin_entrega: number
  ordenes_por_comedor: { comedor_id: string; comedor_nombre: string; ordenes: number; porciones: number }[]
  entregas_por_empresa: { empresa_id: string; empresa_nombre: string; platillos: number; hora_entrega: string; ruta_id: string | null }[]
  rutas: { id: string; repartidor_nombre: string; zona: string; entregas: number; platillos: number }[]
  advertencias: string[]
}

export interface IncidenciaDetalle extends Incidencia {
  ruta_zona: string | null
  repartidor_nombre: string | null // quien tiene la ruta ahora
}

export interface CandidatoReasignacion {
  repartidor: RepartidorDetalle
  capacidad_libre: number // entregas que aún caben en su vehículo ese día
  alcanza: boolean // cabe toda la ruta
}

export interface ContratoPorVencer {
  tipo: 'empresa' | 'comedor'
  id: string
  nombre: string
  vigencia_fin: string
  dias: number
}

export interface FiltroBitacora {
  usuario_id?: string // "sistema" para las acciones automáticas
  rol?: Rol
  desde?: string
  hasta?: string
}

// ---------------------------------------------------------------- cuentas: invitaciones y restablecimiento

export type EstadoInvitacion = 'enviada' | 'aceptada' | 'vencida'
export type TipoOrganizacion = 'comedor' | 'empresa' | 'plataforma'
export type Portal = 'admin' | 'comedores' | 'empresas' | 'empleados'

export interface Invitacion {
  id: string
  usuario_id: string
  correo: string
  rol: Rol
  organizacion_tipo: TipoOrganizacion
  organizacion_id: string | null
  token: string
  enviada_en: string // ISO
  vence_en: string // ISO
  aceptada_en: string | null
}

export interface InvitacionDetalle extends Invitacion {
  estado: EstadoInvitacion
}

export interface TokenRestablecimiento {
  token: string
  usuario_id: string
  vence_ms: number
  usado: boolean
}

export interface UsuarioAcceso {
  usuario_id: string
  nombre: string
  correo: string
  rol: Rol
  activo: boolean
  es_principal: boolean // contacto titular: no tiene invitación
  invitacion: InvitacionDetalle | null
}

export interface NuevaInvitacion {
  organizacion_tipo: TipoOrganizacion
  organizacion_id: string | null
  nombre: string
  correo: string
  rol: Rol
}

export interface SolicitudRestablecimiento {
  enviado: true
  enlace_simulado: string | null // solo en la maqueta: lo que traería el correo
}

export interface ValidacionToken {
  valido: boolean
  motivo?: 'vencido' | 'invalido' | 'usado'
  correo_oculto?: string
}

export interface InvitacionPublica {
  estado: EstadoInvitacion | 'invalida'
  nombre_invitado: string
  correo: string
  organizacion_nombre: string
  organizacion_tipo: TipoOrganizacion
  rol: Rol
  portal: Portal
}

export interface ResultadoAceptacion {
  portal: Portal
  rol: Rol
  nombre: string
  organizacion_nombre: string
}

// ---------------------------------------------------------------- portal del empleado y clientes

export interface OpcionMenu {
  platillo: PlatilloDetalle
  porciones_maximas: number
  porciones_tomadas: number
  porciones_restantes: number
  agotado: boolean
}

export interface MiMenu {
  fecha: string
  hora_corte: string
  cerrado: boolean
  motivo_cerrado: 'corte' | 'empresa_cerrada' | null
  empleado: { id: string; nombre: string; empresa_nombre: string; activo: boolean }
  hora_entrega: string
  precio: number
  porcentaje_subsidio: number
  empresa_cubre: number
  empleado_paga: number
  opciones: OpcionMenu[]
  eleccion: { platillo_id: string; platillo_nombre: string; origen: OrigenEleccion } | null
}

export interface MiEleccion {
  id: string
  fecha: string
  platillo_nombre: string
  precio: number
  empresa_cubre: number
  empleado_paga: number
  origen: OrigenEleccion
}

export interface ConsumoDiario {
  fecha: string
  platillos: number
}

export interface ClienteResumen {
  usuario_id: string
  nombre: string
  correo: string
  activo: boolean
  pedidos_total: number
  pedidos_activos: number
  gasto_total: number
  ultimo_pedido: string | null
  tickets_abiertos: number
}

export interface ClienteDetalle extends ClienteResumen {
  pedidos: PedidoDetalle[]
  tickets: Ticket[]
}

// ---------------------------------------------------------------- sitio público

export interface MensajeContacto {
  id: string
  nombre: string
  correo: string
  empresa: string | null
  mensaje: string
  enviado_en: string // ISO
}

export type NuevoMensajeContacto = Pick<MensajeContacto, 'nombre' | 'correo' | 'mensaje'> & { empresa: string }
