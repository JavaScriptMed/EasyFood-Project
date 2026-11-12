// Capa de acceso a datos del front. Cada función conserva la firma que usan las pantallas y, según el modo:
//   · real (por defecto): llama al backend de EasyLunch (Laravel, /api/v1) con el token de la sesión;
//   · maqueta (NEXT_PUBLIC_USAR_MOCK=true): usa los datos de ejemplo de /lib/api-mock.ts, sin backend.
//
// El backend responde con ids numéricos y algunos nombres distintos a los de /lib/types.ts; aquí se adaptan (ids a texto,
// campos que faltan con valores neutros) para que las pantallas no cambien. Los endpoints que el backend todavía no construye
// (invitaciones, crear empresa o repartidor, reasignar, generar rutas, imágenes…) avisan con un mensaje claro en vez de fallar en silencio.

import { USAR_MOCK, FECHA_OPERACION } from './config'
import { borrar, ErrorApi, get, patch, post, put } from './http'
import { leerSesion } from './sesion'
import * as mock from './api-mock'
import type {
  BloqueHorario, CalendarioServicio, ClienteDetalle, ClienteResumen, ComedorDetalle, ConfiguracionOperacion, ConsumoDiario, ContratoPorVencer, Corte,
  DashboardAdmin, DiaSinServicio, DisponibilidadConPlatillo, EleccionEmpleado, Empleado, EmpleadoDetalle, EmpresaDetalle, EntregaDetalle, FacturaConEmpresa,
  FacturaDetalle, FacturaMensual, FilaReporteProveedor, IncidenciaDetalle, LiquidacionComedor, LiquidacionConComedor, MensajeContacto, MensajeTicket,
  MenuSemanalDia, MiEleccion, MiMenu, OrdenProduccionDetalle, PedidoDetalle, PedidosMananaEmpresa, Plan, PlatilloDetalle, RegistroAuditoriaDetalle,
  RepartidorDetalle, ResultadoCorte, Rol, RutaDetalle, TicketDetalle, TicketResumen, TipoVehiculo, VistaPreviaCorte,
} from './types'

// ---------------------------------------------------------------- ayudas

/** Ids del backend (números) a texto, como los declara /lib/types.ts. */
const esId = (clave: string) => clave === 'id' || clave === 'ejecutado_por' || clave.endsWith('_id')
function ids<T>(valor: T): T {
  if (Array.isArray(valor)) return valor.map(ids) as T
  if (valor && typeof valor === 'object') {
    const salida: Record<string, unknown> = {}
    for (const [clave, v] of Object.entries(valor)) salida[clave] = esId(clave) && typeof v === 'number' ? String(v) : ids(v)
    return salida as T
  }
  return valor
}

const leer = async <T>(ruta: string, query?: Record<string, unknown>) => ids(await get<T>(ruta, query))
const rolActual = (): Rol | null => { const s = leerSesion(); return s.estado === 'ninguna' ? null : s.rol }
const esRol = (...roles: Rol[]) => { const r = rolActual(); return !!r && roles.includes(r) }
const pendiente = (que: string) => new ErrorApi(`${que} todavía no está disponible en el servidor.`, 501, 'no_implementado')
const ahoraIso = () => new Date().toISOString().slice(0, 19)

/** La factura del backend usa el folio como id visible; el id numérico se recuerda para las acciones (emitir, pagar). */
const idDeFolio = new Map<string, string>()
const soloFecha = (iso: string | null | undefined) => (iso ? iso.slice(0, 10) : null)
const factura = <T extends { id: unknown; folio?: string; emitida_en?: string | null; pagada_en?: string | null }>(f: T): T => {
  const interno = String(f.id ?? '')
  const folio = f.folio ?? interno
  if (interno) idDeFolio.set(folio, interno)
  return { ...f, id: folio, emitida_en: soloFecha(f.emitida_en), pagada_en: soloFecha(f.pagada_en) }
}
/** Las pantallas esperan la fecha de pago como AAAA-MM-DD (el backend manda fecha y hora). */
const liquidacion = <T extends { pagada_en?: string | null }>(l: T): T => ({ ...l, pagada_en: soloFecha(l.pagada_en) })

const bloqueDe = (inicio: string): BloqueHorario => (inicio < '11:00' ? 'manana' : inicio < '13:30' ? 'mediodia' : 'tarde')

const repartidor = (r: any): RepartidorDetalle => ({
  vehiculo_personalizado: false, bloques_reservados: [], entregas_asignadas: 0, entregas_completadas: 0, ...r, ultima_ubicacion_en: r.ultima_ubicacion_en ?? '', placas: r.placas ?? null,
})
const ruta = (r: any): RutaDetalle => ({
  ...r, capacidad_maxima: r.capacidad_entregas, distancia_km: r.distancia_km ?? 0, hora_fin_estimada: r.hora_fin_estimada ?? r.ventana_fin,
  bloque: bloqueDe(r.ventana_inicio ?? '12:00'), paradas: (r.paradas ?? []).map((p: any) => ({ ...p, entrega_id: p.entrega_id ?? null })),
})
const pedido = (p: any): PedidoDetalle => ({
  ...p, bloque: 'mediodia' as BloqueHorario, zona: p.zona ?? '', referencias: p.referencias ?? '', metodo_pago: p.metodo_pago ?? '', ultimos_digitos: p.ultimos_digitos ?? null, direccion_entrega: p.direccion_entrega ?? '',
})
const platillo = (p: any): PlatilloDetalle => ({ ...p, calorias: p.calorias ?? 0 })
const ticket = (t: any): TicketResumen => ({ ...t, pedido_folio: t.pedido_folio ?? '' })

// ---------------------------------------------------------------- implementación real

const real: typeof mock = {
  // ---- panel de administración
  // El dashboard todavía no tiene endpoint: las cifras de resumen son de ejemplo y las alertas son las incidencias reales abiertas.
  obtenerDashboard: async () => {
    const alertas = await real.listarIncidencias({ abiertas: true })
    const { resumen } = await mock.obtenerDashboard()
    return { resumen, alertas, incidencias_del_dia: alertas.length, requieren_atencion: alertas.filter((i) => i.prioridad === 'inmediata').length } satisfies DashboardAdmin
  },
  listarEntregas: async () => (await leer<any[]>('/api/v1/admin/entregas', { fecha: FECHA_OPERACION })) as EntregaDetalle[],
  listarIncidencias: async (filtro = {}) => (await leer<IncidenciaDetalle[]>('/api/v1/admin/incidencias', { abiertas: filtro.abiertas, fecha: filtro.fecha, tipos: filtro.tipos })),
  resolverIncidencia: async (id, nota) => ids(await post<IncidenciaDetalle>(`/api/v1/admin/incidencias/${id}/resolver`, { nota })),
  listarCandidatosReasignacion: async () => [],
  reasignarIncidencia: async () => { throw pendiente('Reasignar una ruta') },
  listarAuditoria: async (entidad, entidad_id) => leer<RegistroAuditoriaDetalle[]>(`/api/v1/admin/auditoria/${encodeURIComponent(entidad)}/${encodeURIComponent(entidad_id)}`),

  // ---- empresas y contratos
  listarEmpresas: async () => leer<EmpresaDetalle[]>('/api/v1/admin/empresas'),
  obtenerEmpresa: async (id) => leer<EmpresaDetalle>(esRol('empresa') ? '/api/v1/empresa/perfil' : `/api/v1/admin/empresas/${id}`),
  crearEmpresa: async () => { throw pendiente('Dar de alta una empresa') },
  editarEmpresa: async () => { throw pendiente('Editar una empresa') },
  cambiarEstadoEmpresa: async () => { throw pendiente('Desactivar una empresa') },
  renovarContratoEmpresa: async (id) => ids(await post<EmpresaDetalle>(`/api/v1/admin/empresas/${id}/renovar-contrato`)),
  renovarContratoComedor: async (id) => ids(await post<ComedorDetalle>(`/api/v1/admin/comedores/${id}/renovar-contrato`)),
  listarContratosPorVencer: async (dias = 30) => leer<ContratoPorVencer[]>('/api/v1/admin/contratos/por-vencer', { dias }),

  // ---- bitácora
  listarBitacora: async (filtro = {}) => leer<RegistroAuditoriaDetalle[]>('/api/v1/admin/bitacora', { usuario_id: filtro.usuario_id, rol: filtro.rol, desde: filtro.desde, hasta: filtro.hasta }),
  listarActoresBitacora: async () => [{ id: 'sistema', nombre: 'Sistema', rol: null }, ...(await leer<{ id: string; nombre: string; rol: Rol | null }[]>('/api/v1/admin/bitacora/actores'))],

  // ---- invitaciones y cuenta (necesitan correo: pendientes en el backend)
  listarUsuariosAcceso: async () => [],
  invitarUsuario: async () => { throw pendiente('Invitar usuarios') },
  reenviarInvitacion: async () => { throw pendiente('Reenviar invitaciones') },
  editarUsuarioAcceso: async () => { throw pendiente('Editar usuarios') },
  cambiarEstadoUsuarioAcceso: async () => { throw pendiente('Desactivar usuarios') },
  solicitarRestablecimiento: async () => { throw pendiente('Restablecer la contraseña por correo') },
  validarTokenRestablecimiento: async () => ({ valido: false, motivo: 'invalido' as const }),
  restablecerContrasena: async () => { throw pendiente('Restablecer la contraseña') },
  obtenerInvitacionPublica: async () => ({ estado: 'invalida' as const, nombre_invitado: '', correo: '', organizacion_nombre: '', organizacion_tipo: 'plataforma' as const, rol: 'cliente' as const, portal: 'admin' as const }),
  aceptarInvitacion: async () => { throw pendiente('Aceptar invitaciones') },

  // ---- empleados de una empresa
  listarEmpleados: async () => leer<EmpleadoDetalle[]>('/api/v1/empresa/empleados'),
  agregarEmpleados: async () => { throw pendiente('Dar de alta empleados') },
  editarEmpleado: async () => { throw pendiente('Editar un empleado') },
  quitarMiEleccion: async (_empleado, fecha, tiempo_comida) => { await borrar('/api/v1/empleado/elecciones', { fecha, tiempo_comida }) },
  cambiarEstadoEmpleado: async (id, activo) => ids(await patch<Empleado>(`/api/v1/empresa/empleados/${id}/estado`, { activo })),
  elegirPlatillo: async (_empleado, fecha, tiempo_comida, platillo_id) => ids(await put<EleccionEmpleado>('/api/v1/empleado/elecciones', { fecha, tiempo_comida, platillo_id })),

  // ---- configuración, calendario y corte
  obtenerConfiguracionOperacion: async () => leer<ConfiguracionOperacion>('/api/v1/configuracion/operacion'),
  guardarConfiguracionOperacion: async (config) => ids(await put<ConfiguracionOperacion>('/api/v1/admin/configuracion', config)),
  obtenerCalendarioServicio: async (mes) => leer<CalendarioServicio>('/api/v1/admin/calendario', { mes }),
  marcarDiaSinServicio: async (datos) => ids(await post<DiaSinServicio>('/api/v1/admin/calendario/dias-sin-servicio', datos)),
  cerrarEmpresaDia: async (datos) => ids(await post<DiaSinServicio>('/api/v1/admin/calendario/cierres-empresa', datos)),
  quitarDiaSinServicio: async (id) => { await borrar(`/api/v1/admin/calendario/dias-sin-servicio/${id}`) },
  listarCortes: async () => leer<Corte[]>('/api/v1/admin/cortes'),
  obtenerVistaPreviaCorte: async () => leer<VistaPreviaCorte>('/api/v1/admin/cortes/vista-previa'),
  ejecutarCorte: async () => ids(await post<ResultadoCorte>('/api/v1/admin/cortes/ejecutar')),
  listarPedidosManana: async () => leer<PedidosMananaEmpresa>('/api/v1/empresa/pedidos-de-manana'),

  // ---- consumo y facturación
  listarFacturasEmpresa: async () => (await leer<FacturaMensual[]>('/api/v1/empresa/facturas')).map(factura),
  // La empresa ve el consumo de cualquier mes (también el en curso, «hasta hoy»); el administrador, las facturas ya generadas.
  obtenerFactura: async (_empresa, mes) => {
    if (esRol('empresa')) return factura(await leer<FacturaDetalle>('/api/v1/empresa/facturas/vista-previa', { mes }))
    const delMes = await leer<FacturaMensual[]>('/api/v1/admin/facturas', { mes })
    const encontrada = delMes.find((f) => String(f.empresa_id) === String(_empresa))
    if (!encontrada) throw new ErrorApi('Todavía no hay factura de ese mes: se genera cuando el mes termina.', 404, 'no_encontrado')
    return factura(await leer<FacturaDetalle>(`/api/v1/admin/facturas/${(encontrada as any).id}`))
  },
  listarFacturas: async (mes) => (await leer<FacturaConEmpresa[]>('/api/v1/admin/facturas', { mes })).map(factura),
  // «Generar facturación»: calcula las facturas del mes y emite las que sigan en borrador (quedan congeladas).
  generarFacturacion: async (mes) => {
    const generadas = ids(await post<FacturaMensual[]>('/api/v1/admin/facturas/generar', { mes })).map(factura)
    const emitidas: FacturaMensual[] = []
    for (const f of generadas) {
      if (f.estado !== 'borrador') continue
      emitidas.push(factura(ids(await post<FacturaMensual>(`/api/v1/admin/facturas/${idDeFolio.get(f.id)}/emitir`))))
    }
    return emitidas
  },
  marcarFacturaPagada: async (folio) => {
    const id = idDeFolio.get(folio)
    if (!id) throw new ErrorApi('No encontramos esa factura: recarga la pantalla.', 404, 'no_encontrado')
    return factura(ids(await post<FacturaMensual>(`/api/v1/admin/facturas/${id}/pagar`)))
  },
  listarLiquidaciones: async (mes) => (await leer<LiquidacionConComedor[]>('/api/v1/admin/liquidaciones', { mes })).map(liquidacion),
  listarLiquidacionesComedor: async () => (await leer<LiquidacionComedor[]>('/api/v1/comedor/liquidaciones')).map(liquidacion),
  marcarLiquidacionPagada: async (id) => liquidacion(ids(await post<LiquidacionComedor>(`/api/v1/admin/liquidaciones/${id}/pagar`))),

  // ---- comedores
  listarComedores: async () => leer<ComedorDetalle[]>('/api/v1/admin/comedores'),
  obtenerComedor: async (id) => leer<ComedorDetalle>(esRol('comedor') ? '/api/v1/comedor/perfil' : `/api/v1/admin/comedores/${id}`),
  editarComedor: async () => { throw pendiente('Editar un comedor') },
  cambiarEstadoComedor: async () => { throw pendiente('Desactivar un comedor') },

  // ---- platillos, menús y disponibilidad
  listarPlatillos: async (filtro = {}) =>
    (await leer<PlatilloDetalle[]>(esRol('comedor') ? '/api/v1/comedor/platillos' : '/api/v1/admin/platillos', esRol('comedor') ? undefined : { comedor_id: filtro.comedor_id, estado_revision: filtro.estado_revision }))
      .filter((p) => !esRol('comedor') || ((!filtro.estado_revision || p.estado_revision === filtro.estado_revision)))
      .map(platillo),
  guardarPlatillo: async (datos) => {
    const propio = esRol('comedor')
    const cuerpo: Record<string, unknown> = {
      nombre: datos.nombre, descripcion: datos.descripcion, tiempo_comida: datos.tiempo_comida, calorias: datos.calorias, alergenos: datos.alergenos, estado_revision: datos.estado_revision,
      ...(datos.precio_venta !== undefined ? { precio_venta: datos.precio_venta } : {}),
      ...(!propio ? { comedor_id: datos.comedor_id } : {}),
    }
    if (datos.id) return platillo(ids(await put<PlatilloDetalle>(`/api/v1/${propio ? 'comedor' : 'admin'}/platillos/${datos.id}`, cuerpo)))
    if (datos.precio_venta === undefined) {
      // El backend exige el precio al crear: se propone el del contrato del comedor (lo puede cambiar el administrador al revisar).
      const comedor = await real.obtenerComedor(String(datos.comedor_id))
      if (!comedor.contrato) throw new ErrorApi('Escribe el precio de venta del platillo.', 422, 'validacion')
      cuerpo.precio_venta = comedor.contrato.precio_por_platillo
    }
    return platillo(ids(await post<PlatilloDetalle>(`/api/v1/${propio ? 'comedor' : 'admin'}/platillos`, cuerpo)))
  },
  revisarPlatillo: async (id, decision, motivo) => platillo(ids(await post<PlatilloDetalle>(`/api/v1/admin/platillos/${id}/revision`, { decision, motivo }))),
  listarHistorialPrecios: async () => [], // el backend todavía no guarda historial de precios
  cambiarEstadoPlatillo: async () => { throw pendiente('Desactivar un platillo') },
  editarPlan: async () => { throw pendiente('Editar un plan') },
  programarPrecioPlatillo: async () => { throw pendiente('Programar un precio') },
  listarPlanes: async () => leer<Plan[]>('/api/v1/paquetes'),
  obtenerMenuSemanal: async (fecha_inicio) => leer<MenuSemanalDia[]>('/api/v1/admin/menu-semanal', { fecha_inicio }),
  listarDisponibilidad: async (_comedor, desde, hasta) => leer<DisponibilidadConPlatillo[]>('/api/v1/comedor/disponibilidad', { desde, hasta }),
  guardarDisponibilidad: async (cambios) => (await put<{ guardados: number }>('/api/v1/comedor/disponibilidad', { cambios })).guardados,

  // ---- producción
  listarOrdenesProduccion: async (_comedor, fecha) => leer<OrdenProduccionDetalle[]>('/api/v1/comedor/produccion', { fecha }),
  marcarOrdenesListas: async (_comedor, fecha) => (await post<{ marcadas: number }>('/api/v1/comedor/produccion/marcar-listas', { fecha })).marcadas,
  reporteProveedor: async (fecha) => leer<FilaReporteProveedor[]>('/api/v1/admin/reportes/proveedor', { fecha }),

  // ---- repartidores y rutas
  listarRepartidores: async (filtro = {}) => (await leer<any[]>('/api/v1/admin/repartidores', { estado: filtro.estado })).map(repartidor),
  obtenerCapacidadesVehiculo: async () => { const c = await get<{ automovil: number }>('/api/v1/admin/repartidores/capacidades'); return { bicicleta: 0, motocicleta: 0, automovil: c.automovil } as Record<TipoVehiculo, number> },
  cambiarDisponibilidadRepartidor: async (id) => repartidor(ids(await patch<any>(`/api/v1/admin/repartidores/${id}/disponibilidad`))),
  crearRepartidor: async () => { throw pendiente('Dar de alta repartidores') },
  editarRepartidor: async () => { throw pendiente('Editar un repartidor') },
  cambiarEstadoRepartidor: async () => { throw pendiente('Desactivar un repartidor') },
  guardarCapacidadesVehiculo: async () => { throw pendiente('Cambiar los límites por vehículo') },
  listarRutas: async (fecha) => (await leer<any[]>('/api/v1/admin/rutas', { fecha })).map(ruta),
  generarRutas: async () => { throw pendiente('Regenerar las rutas (las crea el corte)') },
  aprobarRutas: async (fecha) => (await post<{ aprobadas: number }>('/api/v1/admin/rutas/aprobar', { fecha })).aprobadas,

  // ---- pedidos y soporte
  listarPedidos: async () => (await leer<any[]>('/api/v1/admin/pedidos')).map(pedido),
  listarTickets: async () => (await leer<any[]>('/api/v1/admin/tickets')).map(ticket),
  obtenerTicket: async (id) => {
    const t = await leer<any>(`/api/v1/admin/tickets/${id}`)
    return { ...ticket(t), mensajes: t.mensajes, resumen_pedido: t.resumen_pedido ?? { plan: '', proxima_entrega: '', zona: '', total: 0, direccion: '', referencias: '' } } as TicketDetalle
  },
  enviarMensajeTicket: async (id, texto) => ids(await post<MensajeTicket>(`/api/v1/admin/tickets/${id}/mensajes`, { texto })),

  // ---- portal del empleado
  obtenerMiMenu: async () => leer<MiMenu>('/api/v1/empleado/menu'),
  listarMisElecciones: async (_empleado, limite = 8) => leer<MiEleccion[]>('/api/v1/empleado/elecciones', { limite }),

  // ---- consumo diario y clientes
  consumoDiarioEmpresa: async (empresa_id, mes) => leer<ConsumoDiario[]>(esRol('empresa') ? '/api/v1/empresa/consumo' : `/api/v1/admin/empresas/${empresa_id}/consumo`, { mes }),
  listarClientes: async () => leer<ClienteResumen[]>('/api/v1/admin/clientes'),
  obtenerCliente: async (id) => {
    const c = await leer<any>(`/api/v1/admin/clientes/${id}`)
    return { ...c, pedidos: c.pedidos.map(pedido), tickets: c.tickets.map(ticket) } as ClienteDetalle
  },

  // ---- sitio público
  enviarMensajeContacto: async (datos) => {
    const r = await post<{ id: number }>('/api/v1/contacto', { nombre: datos.nombre, correo: datos.correo, empresa: datos.empresa || undefined, mensaje: datos.mensaje }, { publico: true })
    return { id: String(r.id), nombre: datos.nombre, correo: datos.correo, empresa: datos.empresa || null, mensaje: datos.mensaje, enviado_en: ahoraIso() } as MensajeContacto
  },
}

// ---------------------------------------------------------------- lo que usan las pantallas

const elegir = <K extends keyof typeof mock>(nombre: K): (typeof mock)[K] => (USAR_MOCK ? mock[nombre] : real[nombre])

export const obtenerDashboard = elegir('obtenerDashboard')
export const listarEntregas = elegir('listarEntregas')
export const listarIncidencias = elegir('listarIncidencias')
export const resolverIncidencia = elegir('resolverIncidencia')
export const listarCandidatosReasignacion = elegir('listarCandidatosReasignacion')
export const reasignarIncidencia = elegir('reasignarIncidencia')
export const listarAuditoria = elegir('listarAuditoria')
export const listarEmpresas = elegir('listarEmpresas')
export const obtenerEmpresa = elegir('obtenerEmpresa')
export const crearEmpresa = elegir('crearEmpresa')
export const renovarContratoEmpresa = elegir('renovarContratoEmpresa')
export const renovarContratoComedor = elegir('renovarContratoComedor')
export const listarContratosPorVencer = elegir('listarContratosPorVencer')
export const listarBitacora = elegir('listarBitacora')
export const listarActoresBitacora = elegir('listarActoresBitacora')
export const listarUsuariosAcceso = elegir('listarUsuariosAcceso')
export const invitarUsuario = elegir('invitarUsuario')
export const reenviarInvitacion = elegir('reenviarInvitacion')
export const solicitarRestablecimiento = elegir('solicitarRestablecimiento')
export const validarTokenRestablecimiento = elegir('validarTokenRestablecimiento')
export const restablecerContrasena = elegir('restablecerContrasena')
export const obtenerInvitacionPublica = elegir('obtenerInvitacionPublica')
export const aceptarInvitacion = elegir('aceptarInvitacion')
export const listarEmpleados = elegir('listarEmpleados')
export const agregarEmpleados = elegir('agregarEmpleados')
export const cambiarEstadoEmpleado = elegir('cambiarEstadoEmpleado')
export const elegirPlatillo = elegir('elegirPlatillo')
export const obtenerConfiguracionOperacion = elegir('obtenerConfiguracionOperacion')
export const guardarConfiguracionOperacion = elegir('guardarConfiguracionOperacion')
export const obtenerCalendarioServicio = elegir('obtenerCalendarioServicio')
export const marcarDiaSinServicio = elegir('marcarDiaSinServicio')
export const cerrarEmpresaDia = elegir('cerrarEmpresaDia')
export const quitarDiaSinServicio = elegir('quitarDiaSinServicio')
export const listarCortes = elegir('listarCortes')
export const obtenerVistaPreviaCorte = elegir('obtenerVistaPreviaCorte')
export const ejecutarCorte = elegir('ejecutarCorte')
export const listarPedidosManana = elegir('listarPedidosManana')
export const listarFacturasEmpresa = elegir('listarFacturasEmpresa')
export const obtenerFactura = elegir('obtenerFactura')
export const listarFacturas = elegir('listarFacturas')
export const generarFacturacion = elegir('generarFacturacion')
export const marcarFacturaPagada = elegir('marcarFacturaPagada')
export const listarLiquidaciones = elegir('listarLiquidaciones')
export const listarLiquidacionesComedor = elegir('listarLiquidacionesComedor')
export const marcarLiquidacionPagada = elegir('marcarLiquidacionPagada')
export const listarComedores = elegir('listarComedores')
export const obtenerComedor = elegir('obtenerComedor')
export const listarPlatillos = elegir('listarPlatillos')
export const guardarPlatillo = elegir('guardarPlatillo')
export const revisarPlatillo = elegir('revisarPlatillo')
export const listarHistorialPrecios = elegir('listarHistorialPrecios')
export const listarPlanes = elegir('listarPlanes')
export const obtenerMenuSemanal = elegir('obtenerMenuSemanal')
export const listarDisponibilidad = elegir('listarDisponibilidad')
export const guardarDisponibilidad = elegir('guardarDisponibilidad')
export const listarOrdenesProduccion = elegir('listarOrdenesProduccion')
export const marcarOrdenesListas = elegir('marcarOrdenesListas')
export const reporteProveedor = elegir('reporteProveedor')
export const listarRepartidores = elegir('listarRepartidores')
export const obtenerCapacidadesVehiculo = elegir('obtenerCapacidadesVehiculo')
export const cambiarDisponibilidadRepartidor = elegir('cambiarDisponibilidadRepartidor')
export const crearRepartidor = elegir('crearRepartidor')
export const listarRutas = elegir('listarRutas')
export const generarRutas = elegir('generarRutas')
export const aprobarRutas = elegir('aprobarRutas')
export const listarPedidos = elegir('listarPedidos')
export const listarTickets = elegir('listarTickets')
export const obtenerTicket = elegir('obtenerTicket')
export const enviarMensajeTicket = elegir('enviarMensajeTicket')
export const obtenerMiMenu = elegir('obtenerMiMenu')
export const listarMisElecciones = elegir('listarMisElecciones')
export const consumoDiarioEmpresa = elegir('consumoDiarioEmpresa')
export const listarClientes = elegir('listarClientes')
export const obtenerCliente = elegir('obtenerCliente')
export const enviarMensajeContacto = elegir('enviarMensajeContacto')
export const editarEmpresa = elegir('editarEmpresa')
export const cambiarEstadoEmpresa = elegir('cambiarEstadoEmpresa')
export const editarComedor = elegir('editarComedor')
export const cambiarEstadoComedor = elegir('cambiarEstadoComedor')
export const editarEmpleado = elegir('editarEmpleado')
export const quitarMiEleccion = elegir('quitarMiEleccion')
export const cambiarEstadoPlatillo = elegir('cambiarEstadoPlatillo')
export const editarPlan = elegir('editarPlan')
export const programarPrecioPlatillo = elegir('programarPrecioPlatillo')
export const editarRepartidor = elegir('editarRepartidor')
export const cambiarEstadoRepartidor = elegir('cambiarEstadoRepartidor')
export const guardarCapacidadesVehiculo = elegir('guardarCapacidadesVehiculo')
export const editarUsuarioAcceso = elegir('editarUsuarioAcceso')
export const cambiarEstadoUsuarioAcceso = elegir('cambiarEstadoUsuarioAcceso')
