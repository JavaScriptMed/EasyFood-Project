// Capa de acceso a datos. Cada operación es una función async que hoy lee y escribe /lib/mock-data.ts
// con 300 ms de retardo. Para conectar el backend real, reemplaza el cuerpo de cada función por un fetch
// con la misma firma; las pantallas no cambian.
//
// Para probar los estados de error: localStorage.setItem('easylunch:simular-error', '1') y recarga la vista.

import { AHORA_MOCK, FECHA_HOY, FECHA_MANANA, MESES, MES_ACTUAL, RETARDO_API_MS, USUARIO_ACTUAL_ID, VIGENCIA_ENLACE_RESTABLECER_MIN, VIGENCIA_INVITACION_DIAS } from './config'
import { calcularConsumo, calcularTotales } from './facturacion'
import { errorDeContrasena } from './contrasena'
import { diasEntre, etiquetaMes, sumarDias, sumarMeses } from './format'
import { PORTALES, portalDeRol } from './portales'
import * as db from './mock-data'
import type {
  CalendarioServicio, CambioDisponibilidad, CandidatoReasignacion, ContratoPorVencer, FiltroBitacora, IncidenciaDetalle, TipoIncidencia, Comedor, ComedorDetalle, ConfiguracionOperacion, Corte, DiaSinServicio, EntregaEmpresa, VistaPreviaCorte, DashboardAdmin, DisponibilidadConPlatillo, EleccionEmpleado, Empleado, EmpleadoDetalle, Empresa, EmpresaDetalle,
  ProgramarPrecio, EdicionComedor, EdicionEmpleado, EdicionEmpresa, EdicionPlan, EdicionRepartidor, EdicionUsuarioAcceso, EntregaDetalle, EstadoContrato, FacturaConEmpresa, FacturaDetalle, FacturaMensual, FilaReporteProveedor, GuardarPlatillo, HistorialPrecio,
  LiquidacionComedor, LiquidacionConComedor, MenuSemanalDia, MensajeTicket, NuevaEmpresa, NuevoRepartidor, OrdenProduccionDetalle, PedidoDetalle,
  PedidosMananaEmpresa, Plan, Platillo, PlatilloDetalle, RegistroAuditoriaDetalle, RepartidorDetalle, ResultadoAltaEmpleados, ResultadoCorte, RutaDetalle,
  MensajeContacto, NuevoMensajeContacto, ClienteDetalle, ClienteResumen, ConsumoDiario, EstadoInvitacion, Incidencia, MiEleccion, MiMenu, OpcionMenu, InvitacionDetalle, InvitacionPublica, Invitacion, NuevaInvitacion, Portal, ResultadoAceptacion, SolicitudRestablecimiento, TipoOrganizacion, UsuarioAcceso, ValidacionToken, RegistroAuditoria, Repartidor, Ruta, TicketDetalle, TicketResumen, TiempoComida, TipoVehiculo, Usuario, UsuarioResumen,
} from './types'

// ---------------------------------------------------------------- infraestructura

const esperar = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms))

function falloSimulado() {
  try { return typeof window !== 'undefined' && window.localStorage.getItem('easylunch:simular-error') === '1' } catch { return false }
}

/** Ejecuta `operacion` tras el retardo y devuelve una copia, para que las pantallas no muten la "base de datos". */
async function llamar<T>(operacion: () => T, ms = RETARDO_API_MS): Promise<T> {
  await esperar(ms)
  if (falloSimulado()) throw new Error('No se pudo conectar con el servidor. Intenta de nuevo.')
  return structuredClone(operacion())
}

const pad = (n: number) => String(n).padStart(2, '0')
function ahora() {
  const d = new Date()
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`
}

function auditar(accion: string, entidad: string, entidad_id: string, detalle: string, actor: string | null = USUARIO_ACTUAL_ID) {
  db.registros_auditoria.push({ id: `AUD-${Date.now()}-${db.registros_auditoria.length}`, usuario_id: actor, accion, entidad, entidad_id, detalle, creado_en: ahora() })
}

const buscarUsuario = (id: string) => db.usuarios.find((u) => u.id === id)
const nombreDe = (id: string) => buscarUsuario(id)?.nombre ?? id
const resumenUsuario = (u: Usuario): UsuarioResumen => ({ id: u.id, nombre: u.nombre, correo: u.correo, telefono: u.telefono })
const contratoEmpresaDe = (empresa_id: string) => db.contratos_empresa.find((c) => c.empresa_id === empresa_id)!
const contratoComedorDe = (comedor_id: string) => db.contratos_comedor.find((c) => c.comedor_id === comedor_id)!
const empleadosDe = (empresa_id: string) => db.empleados.filter((e) => e.empresa_id === empresa_id)
const siguienteId = (prefijo: string, existentes: string[]) => `${prefijo}${String(Math.max(0, ...existentes.map((id) => Number(id.replace(/\D/g, '').slice(-3)) || 0)) + 1).padStart(3, '0')}`

function estadoContrato(vigencia_fin: string): EstadoContrato {
  if (vigencia_fin < FECHA_HOY) return 'vencido'
  return vigencia_fin <= sumarDias(FECHA_HOY, 30) ? 'por_vencer' : 'activo'
}

// ---------------------------------------------------------------- panel de administración

export const obtenerDashboard = () => llamar<DashboardAdmin>(() => {
  const alertas = db.incidencias.filter((i) => i.estado === 'abierta')
  return { resumen: db.resumen_dashboard, alertas, incidencias_del_dia: db.incidencias.length, requieren_atencion: alertas.filter((i) => i.prioridad === 'inmediata').length }
})

export const listarEntregas = () => llamar<EntregaDetalle[]>(() => db.entregas_empresa.map((e) => ({ ...e, empresa_nombre: db.empresas.find((x) => x.id === e.empresa_id)?.nombre ?? e.empresa_id })))

const detalleIncidencia = (i: Incidencia): IncidenciaDetalle => {
  const ruta = db.rutas.find((r) => r.id === i.ruta_id)
  const repartidor = db.repartidores.find((r) => r.id === (i.repartidor_reasignado_id ?? ruta?.repartidor_id))
  return { ...i, ruta_zona: ruta?.zona ?? null, repartidor_nombre: repartidor ? nombreDe(repartidor.usuario_id) : null }
}

export const listarIncidencias = (filtro: { abiertas?: boolean; fecha?: string; tipos?: TipoIncidencia[] } = {}) =>
  llamar<IncidenciaDetalle[]>(() =>
    db.incidencias
      .filter((i) => (!filtro.abiertas || i.estado === 'abierta') && (!filtro.fecha || i.fecha === filtro.fecha) && (!filtro.tipos || filtro.tipos.includes(i.tipo)))
      .sort((a, b) => (a.hora ?? '99:99').localeCompare(b.hora ?? '99:99'))
      .map(detalleIncidencia),
  )

export const resolverIncidencia = (id: string, nota?: string) => llamar<IncidenciaDetalle>(() => {
  const incidencia = db.incidencias.find((i) => i.id === id)
  if (!incidencia) throw new Error('La incidencia no existe.')
  incidencia.estado = 'resuelta'
  if (nota?.trim()) incidencia.nota = nota.trim()
  auditar('resolver', 'incidencia', id, `Resolvió la incidencia: ${incidencia.descripcion}`)
  return detalleIncidencia(incidencia)
})

function candidatosDe(incidencia: Incidencia): CandidatoReasignacion[] {
  const ruta = db.rutas.find((r) => r.id === incidencia.ruta_id)
  if (!ruta) return []
  const ocupadas = (repartidor_id: string) => db.rutas.filter((r) => r.fecha === ruta.fecha && r.repartidor_id === repartidor_id).reduce((suma, r) => suma + r.entregas_asignadas, 0)
  return db.repartidores
    .filter((r) => r.id !== ruta.repartidor_id && r.estado !== 'no_disponible')
    .map((r) => {
      const capacidad_libre = Math.max(0, db.capacidades_vehiculo[r.tipo_vehiculo] - ocupadas(r.id))
      return { repartidor: { ...r, nombre: nombreDe(r.usuario_id) }, capacidad_libre, alcanza: capacidad_libre >= ruta.entregas_asignadas }
    })
    .filter((c) => c.capacidad_libre > 0)
    .sort((a, b) => Number(b.alcanza) - Number(a.alcanza) || b.capacidad_libre - a.capacidad_libre)
}

/** Repartidores con capacidad libre ese día para tomar la ruta de la incidencia. */
export const listarCandidatosReasignacion = (incidencia_id: string) => llamar<CandidatoReasignacion[]>(() => {
  const incidencia = db.incidencias.find((i) => i.id === incidencia_id)
  if (!incidencia) throw new Error('La incidencia no existe.')
  return candidatosDe(incidencia)
})

export const reasignarIncidencia = (datos: { id: string; repartidor_id: string; nota: string }) => llamar<IncidenciaDetalle>(() => {
  const incidencia = db.incidencias.find((i) => i.id === datos.id)
  if (!incidencia) throw new Error('La incidencia no existe.')
  const ruta = db.rutas.find((r) => r.id === incidencia.ruta_id)
  if (!ruta) throw new Error('Esta incidencia no está ligada a una ruta.')
  const candidato = candidatosDe(incidencia).find((c) => c.repartidor.id === datos.repartidor_id)
  if (!candidato) throw new Error('Ese repartidor ya no tiene capacidad libre.')
  if (!candidato.alcanza) throw new Error(`A ${candidato.repartidor.nombre} solo le caben ${candidato.capacidad_libre} entregas y la ruta tiene ${ruta.entregas_asignadas}.`)
  ruta.repartidor_id = candidato.repartidor.id
  ruta.tipo_vehiculo = candidato.repartidor.tipo_vehiculo
  ruta.capacidad_maxima = db.capacidades_vehiculo[ruta.tipo_vehiculo]
  incidencia.estado = 'reasignada'
  incidencia.repartidor_reasignado_id = candidato.repartidor.id
  if (datos.nota.trim()) incidencia.nota = datos.nota.trim()
  auditar('reasignar', 'ruta', ruta.id, `Reasignó la ruta ${ruta.id} a ${candidato.repartidor.nombre}`)
  return detalleIncidencia(incidencia)
})

const conUsuario = (r: RegistroAuditoria): RegistroAuditoriaDetalle => ({ ...r, usuario_nombre: r.usuario_id ? nombreDe(r.usuario_id) : null, usuario_rol: r.usuario_id ? buscarUsuario(r.usuario_id)?.rol ?? null : null })

export const listarAuditoria = (entidad: string, entidad_id: string) =>
  llamar<RegistroAuditoriaDetalle[]>(() =>
    db.registros_auditoria
      .filter((r) => r.entidad === entidad && r.entidad_id === entidad_id)
      .sort((a, b) => a.creado_en.localeCompare(b.creado_en))
      .map(conUsuario),
  )

// ---------------------------------------------------------------- empresas y contratos

function detalleEmpresa(empresa: Empresa): EmpresaDetalle {
  const contrato = contratoEmpresaDe(empresa.id)
  const empleados = empleadosDe(empresa.id)
  const lineas = calcularConsumo(empleados, buscarUsuario, db.elecciones_empleado, MES_ACTUAL)
  return {
    ...empresa,
    contacto: resumenUsuario(buscarUsuario(empresa.usuario_id)!),
    contrato,
    total_empleados: empleados.length,
    platillos_mes: lineas.reduce((suma, l) => suma + l.platillos, 0),
    estado_contrato: estadoContrato(contrato.vigencia_fin),
    dias_para_vencer: diasEntre(FECHA_HOY, contrato.vigencia_fin),
  }
}

export const listarEmpresas = () => llamar<EmpresaDetalle[]>(() => db.empresas.map(detalleEmpresa))

export const obtenerEmpresa = (empresa_id: string) => llamar<EmpresaDetalle>(() => {
  const empresa = db.empresas.find((e) => e.id === empresa_id)
  if (!empresa) throw new Error('La empresa no existe.')
  return detalleEmpresa(empresa)
})

export const editarEmpresa = (empresa_id: string, datos: EdicionEmpresa) => llamar<EmpresaDetalle>(() => {
  const empresa = db.empresas.find((e) => e.id === empresa_id)
  if (!empresa) throw new Error('La empresa no existe.')
  if (!datos.nombre.trim()) throw new Error('Escribe el nombre de la empresa.')
  if (datos.porcentaje_subsidio < 0 || datos.porcentaje_subsidio > 100) throw new Error('El subsidio debe estar entre 0 y 100 %.')
  if (datos.compra_minima_mensual < 0) throw new Error('El mínimo mensual no puede ser negativo.')
  const contrato = contratoEmpresaDe(empresa_id)
  Object.assign(empresa, { nombre: datos.nombre.trim(), direccion_entrega: datos.direccion_entrega.trim() })
  Object.assign(contrato, { porcentaje_subsidio: datos.porcentaje_subsidio, compra_minima_mensual: datos.compra_minima_mensual, ventana_entrega_inicio: datos.ventana_entrega_inicio, ventana_entrega_fin: datos.ventana_entrega_fin, hora_entrega: datos.ventana_entrega_inicio })
  const contacto = buscarUsuario(empresa.usuario_id)
  if (contacto) Object.assign(contacto, { nombre: datos.contacto_nombre.trim() || contacto.nombre, telefono: datos.contacto_telefono.trim() || null })
  auditar('actualizar', 'empresa', empresa_id, `Editó la empresa ${empresa.nombre}`)
  return detalleEmpresa(empresa)
})

export const cambiarEstadoEmpresa = (empresa_id: string, activo: boolean) => llamar<EmpresaDetalle>(() => {
  const empresa = db.empresas.find((e) => e.id === empresa_id)
  if (!empresa) throw new Error('La empresa no existe.')
  empresa.activo = activo
  auditar(activo ? 'activar' : 'desactivar', 'empresa', empresa_id, `${activo ? 'Reactivó' : 'Desactivó'} la empresa ${empresa.nombre}`)
  return detalleEmpresa(empresa)
})

export const crearEmpresa = (datos: NuevaEmpresa) => llamar<EmpresaDetalle>(() => {
  if (!datos.nombre.trim()) throw new Error('Escribe el nombre de la empresa.')
  const id = siguienteId('EMP-', db.empresas.map((e) => e.id))
  const correo = datos.contacto_rrhh.match(/[^\s@]+@[^\s@]+\.[^\s@]+/)?.[0] ?? `rrhh@${id.toLowerCase()}.mx`
  const nombreContacto = datos.contacto_rrhh.replace(correo, '').replace(/[,;<>]/g, ' ').trim() || 'Contacto de RR. HH.'
  const usuario: Usuario = { id: `USR-${id}`, nombre: nombreContacto, correo, telefono: null, rol: 'empresa', activo: true }
  const empresa: Empresa = { id, usuario_id: usuario.id, nombre: datos.nombre.trim(), dominio: correo.split('@')[1], zona: 'Guadalajara', direccion_entrega: datos.direccion_entrega.trim() }
  const [ventana_inicio = '12:30', ventana_fin = '13:15'] = datos.horario_entrega.split(/\s*[–-]\s*/)
  const inicio = datos.vigencia_inicio || FECHA_HOY
  const fin = new Date(`${inicio}T00:00:00Z`)
  fin.setUTCMonth(fin.getUTCMonth() + 6)
  fin.setUTCDate(fin.getUTCDate() - 1)
  db.usuarios.push(usuario)
  db.empresas.push(empresa)
  db.contratos_empresa.push({
    id: `CTE-${id.slice(4)}`, empresa_id: id, porcentaje_subsidio: datos.porcentaje_subsidio, compra_minima_mensual: datos.compra_minima_mensual, precio_por_platillo: 130,
    vigencia_inicio: inicio, vigencia_fin: fin.toISOString().slice(0, 10), hora_entrega: ventana_inicio, ventana_entrega_inicio: ventana_inicio, ventana_entrega_fin: ventana_fin,
  })
  auditar('crear', 'empresa', id, `Creó la empresa ${empresa.nombre}`)
  return detalleEmpresa(empresa)
})

export const renovarContratoEmpresa = (empresa_id: string) => llamar<EmpresaDetalle>(() => {
  const empresa = db.empresas.find((e) => e.id === empresa_id)
  if (!empresa) throw new Error('La empresa no existe.')
  const contrato = contratoEmpresaDe(empresa_id)
  contrato.vigencia_fin = sumarMeses(contrato.vigencia_fin < FECHA_HOY ? FECHA_HOY : contrato.vigencia_fin, 6)
  auditar('renovar', 'contrato_empresa', empresa_id, `Renovó el contrato de ${empresa.nombre} por 6 meses`)
  return detalleEmpresa(empresa)
})

export const renovarContratoComedor = (comedor_id: string) => llamar<ComedorDetalle>(() => {
  const comedor = db.comedores.find((c) => c.id === comedor_id)
  if (!comedor) throw new Error('El comedor no existe.')
  const contrato = contratoComedorDe(comedor_id)
  contrato.vigencia_fin = sumarMeses(contrato.vigencia_fin < FECHA_HOY ? FECHA_HOY : contrato.vigencia_fin, 6)
  auditar('renovar', 'contrato_comedor', comedor_id, `Renovó el contrato de ${comedor.nombre} por 6 meses`)
  return detalleComedor(comedor)
})

/** Contratos de empresas y comedores que vencen en `dias` o menos (incluye los ya vencidos). */
export const listarContratosPorVencer = (dias = 30) => llamar<ContratoPorVencer[]>(() =>
  [
    ...db.empresas.map((e) => ({ tipo: 'empresa' as const, id: e.id, nombre: e.nombre, vigencia_fin: contratoEmpresaDe(e.id).vigencia_fin })),
    ...db.comedores.map((c) => ({ tipo: 'comedor' as const, id: c.id, nombre: c.nombre, vigencia_fin: contratoComedorDe(c.id).vigencia_fin })),
  ]
    .map((c) => ({ ...c, dias: diasEntre(FECHA_HOY, c.vigencia_fin) }))
    .filter((c) => c.dias <= dias)
    .sort((a, b) => a.dias - b.dias),
)

// ---------------------------------------------------------------- bitácora

export const listarBitacora = (filtro: FiltroBitacora = {}) => llamar<RegistroAuditoriaDetalle[]>(() =>
  db.registros_auditoria
    .filter((r) => {
      const dia = r.creado_en.slice(0, 10)
      const usuarioOk = !filtro.usuario_id || (filtro.usuario_id === 'sistema' ? r.usuario_id === null : r.usuario_id === filtro.usuario_id)
      const rolOk = !filtro.rol || (!!r.usuario_id && buscarUsuario(r.usuario_id)?.rol === filtro.rol)
      return usuarioOk && rolOk && (!filtro.desde || dia >= filtro.desde) && (!filtro.hasta || dia <= filtro.hasta)
    })
    .sort((a, b) => b.creado_en.localeCompare(a.creado_en))
    .map(conUsuario),
)

/** Quiénes aparecen en la bitácora, para el filtro por usuario. */
export const listarActoresBitacora = () => llamar<{ id: string; nombre: string; rol: Usuario['rol'] | null }[]>(() => {
  const ids = [...new Set(db.registros_auditoria.map((r) => r.usuario_id))]
  return ids
    .map((id) => (id ? { id, nombre: nombreDe(id), rol: buscarUsuario(id)?.rol ?? null } : { id: 'sistema', nombre: 'Sistema', rol: null }))
    .sort((a, b) => a.nombre.localeCompare(b.nombre))
})

// ---------------------------------------------------------------- invitaciones

const ahoraMock = () => `${FECHA_HOY}T${ahora().slice(11)}`
const estadoInvitacion = (i: Invitacion): EstadoInvitacion => (i.aceptada_en ? 'aceptada' : i.vence_en < ahoraMock() ? 'vencida' : 'enviada')
const conEstadoInvitacion = (i: Invitacion): InvitacionDetalle => ({ ...i, estado: estadoInvitacion(i) })
const portalDeOrganizacion = (tipo: TipoOrganizacion): Portal => (tipo === 'comedor' ? 'comedores' : tipo === 'empresa' ? 'empresas' : 'admin')
const nombreOrganizacion = (tipo: TipoOrganizacion, id: string | null) =>
  tipo === 'comedor' ? db.comedores.find((c) => c.id === id)?.nombre ?? 'tu comedor' : tipo === 'empresa' ? db.empresas.find((e) => e.id === id)?.nombre ?? 'tu empresa' : 'EasyLunch'
const vencimientoInvitacion = () => `${sumarDias(FECHA_HOY, VIGENCIA_INVITACION_DIAS)}T${ahora().slice(11)}`
const correoValido = (correo: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo.trim())

function crearInvitacion(usuario_id: string, correo: string, rol: Invitacion['rol'], organizacion_tipo: TipoOrganizacion, organizacion_id: string | null) {
  const invitacion: Invitacion = {
    id: `INV-${Date.now().toString(36)}-${db.invitaciones.length}`, usuario_id, correo, rol, organizacion_tipo, organizacion_id,
    token: `inv-${Math.random().toString(36).slice(2, 12)}`, enviada_en: ahoraMock(), vence_en: vencimientoInvitacion(), aceptada_en: null,
  }
  db.invitaciones.push(invitacion)
  return invitacion
}

const rolesInvitables: Record<TipoOrganizacion, Invitacion['rol'][]> = { comedor: ['comedor'], empresa: ['empresa'], plataforma: ['administrador', 'soporte'] }

/** Contacto titular más las personas invitadas a un comedor, una empresa o la plataforma. */
export const listarUsuariosAcceso = (organizacion: { tipo: TipoOrganizacion; id: string | null }) => llamar<UsuarioAcceso[]>(() => {
  const titular = organizacion.tipo === 'comedor' ? db.comedores.find((c) => c.id === organizacion.id)?.usuario_id : organizacion.tipo === 'empresa' ? db.empresas.find((e) => e.id === organizacion.id)?.usuario_id : USUARIO_ACTUAL_ID
  const filas: UsuarioAcceso[] = []
  const usuarioTitular = titular ? buscarUsuario(titular) : undefined
  if (usuarioTitular) filas.push({ usuario_id: usuarioTitular.id, nombre: usuarioTitular.nombre, correo: usuarioTitular.correo, rol: usuarioTitular.rol, activo: usuarioTitular.activo, es_principal: true, invitacion: null })
  db.invitaciones
    .filter((i) => i.organizacion_tipo === organizacion.tipo && i.organizacion_id === organizacion.id && i.rol !== 'empleado')
    .sort((a, b) => b.enviada_en.localeCompare(a.enviada_en))
    .forEach((i) => {
      const usuario = buscarUsuario(i.usuario_id)!
      filas.push({ usuario_id: usuario.id, nombre: usuario.nombre, correo: usuario.correo, rol: usuario.rol, activo: usuario.activo, es_principal: false, invitacion: conEstadoInvitacion(i) })
    })
  return filas
})

export const invitarUsuario = (datos: NuevaInvitacion) => llamar<UsuarioAcceso>(() => {
  if (!datos.nombre.trim()) throw new Error('Escribe el nombre de la persona.')
  if (!correoValido(datos.correo)) throw new Error('Escribe un correo válido.')
  if (!rolesInvitables[datos.organizacion_tipo].includes(datos.rol)) throw new Error('Ese rol no corresponde a esta organización.')
  const correo = datos.correo.trim().toLowerCase()
  if (db.usuarios.some((u) => u.correo.toLowerCase() === correo)) throw new Error('Ese correo ya tiene una cuenta o una invitación.')
  const usuario: Usuario = { id: `USR-INV-${Date.now().toString(36)}`, nombre: datos.nombre.trim(), correo, telefono: null, rol: datos.rol, activo: false }
  db.usuarios.push(usuario)
  const invitacion = crearInvitacion(usuario.id, correo, datos.rol, datos.organizacion_tipo, datos.organizacion_id)
  auditar('invitar', 'usuario', usuario.id, `Invitó a ${usuario.nombre} a ${nombreOrganizacion(datos.organizacion_tipo, datos.organizacion_id)}`)
  return { usuario_id: usuario.id, nombre: usuario.nombre, correo, rol: usuario.rol, activo: false, es_principal: false, invitacion: conEstadoInvitacion(invitacion) }
})

const filaDeUsuario = (usuario: Usuario): UsuarioAcceso => {
  const invitacion = db.invitaciones.find((i) => i.usuario_id === usuario.id)
  return { usuario_id: usuario.id, nombre: usuario.nombre, correo: usuario.correo, rol: usuario.rol, activo: usuario.activo, es_principal: !invitacion, invitacion: invitacion ? conEstadoInvitacion(invitacion) : null }
}

export const editarUsuarioAcceso = (usuario_id: string, datos: EdicionUsuarioAcceso) => llamar<UsuarioAcceso>(() => {
  const usuario = buscarUsuario(usuario_id)
  if (!usuario) throw new Error('El usuario no existe.')
  if (!datos.nombre.trim()) throw new Error('Escribe el nombre de la persona.')
  usuario.nombre = datos.nombre.trim()
  usuario.rol = datos.rol
  auditar('actualizar', 'usuario', usuario_id, `Editó al usuario ${usuario.nombre}`)
  return filaDeUsuario(usuario)
})

export const cambiarEstadoUsuarioAcceso = (usuario_id: string, activo: boolean) => llamar<UsuarioAcceso>(() => {
  const usuario = buscarUsuario(usuario_id)
  if (!usuario) throw new Error('El usuario no existe.')
  if (usuario.id === USUARIO_ACTUAL_ID && !activo) throw new Error('No puedes desactivar tu propia cuenta.')
  usuario.activo = activo
  auditar(activo ? 'activar' : 'desactivar', 'usuario', usuario_id, `${activo ? 'Reactivó' : 'Desactivó'} al usuario ${usuario.nombre}`)
  return filaDeUsuario(usuario)
})

/** Renueva la vigencia y el enlace de una invitación enviada o vencida. */
export const reenviarInvitacion = (invitacion_id: string) => llamar<InvitacionDetalle>(() => {
  const invitacion = db.invitaciones.find((i) => i.id === invitacion_id)
  if (!invitacion) throw new Error('La invitación no existe.')
  if (invitacion.aceptada_en) throw new Error('Esta invitación ya fue aceptada.')
  invitacion.token = `inv-${Math.random().toString(36).slice(2, 12)}`
  invitacion.enviada_en = ahoraMock()
  invitacion.vence_en = vencimientoInvitacion()
  auditar('reenviar_invitacion', 'usuario', invitacion.usuario_id, `Reenvió la invitación a ${invitacion.correo}`)
  return conEstadoInvitacion(invitacion)
})

// ---------------------------------------------------------------- cuenta: restablecer contraseña y aceptar invitación

/** Siempre responde igual, exista o no el correo, para no revelar qué cuentas existen. */
export const solicitarRestablecimiento = (correo: string) => llamar<SolicitudRestablecimiento>(() => {
  if (!correoValido(correo)) throw new Error('Escribe un correo válido.')
  const usuario = db.usuarios.find((u) => u.activo && u.correo.toLowerCase() === correo.trim().toLowerCase())
  const portal = usuario ? portalDeRol(usuario.rol) : null
  if (!usuario || !portal) return { enviado: true, enlace_simulado: null }
  db.tokens_restablecimiento.filter((t) => t.usuario_id === usuario.id).forEach((t) => { t.usado = true })
  const token = `rst-${Math.random().toString(36).slice(2, 14)}`
  db.tokens_restablecimiento.push({ token, usuario_id: usuario.id, vence_ms: Date.now() + VIGENCIA_ENLACE_RESTABLECER_MIN * 60_000, usado: false })
  return { enviado: true, enlace_simulado: `${PORTALES[portal].base}/restablecer-contrasena?token=${token}` }
})

function revisarToken(token: string): ValidacionToken & { usuario?: Usuario } {
  const registro = db.tokens_restablecimiento.find((t) => t.token === token)
  if (!registro) return { valido: false, motivo: 'invalido' }
  if (registro.usado) return { valido: false, motivo: 'usado' }
  if (registro.vence_ms < Date.now()) return { valido: false, motivo: 'vencido' }
  const usuario = buscarUsuario(registro.usuario_id)
  if (!usuario) return { valido: false, motivo: 'invalido' }
  const [local, dominio] = usuario.correo.split('@')
  return { valido: true, correo_oculto: `${local[0]}***@${dominio}`, usuario }
}

export const validarTokenRestablecimiento = (token: string) => llamar<ValidacionToken>(() => {
  const { valido, motivo, correo_oculto } = revisarToken(token)
  return { valido, motivo, correo_oculto }
})

export const restablecerContrasena = (token: string, contrasena: string) => llamar<void>(() => {
  const revision = revisarToken(token)
  if (!revision.valido || !revision.usuario) throw new Error('Este enlace ya no es válido. Solicita uno nuevo.')
  const error = errorDeContrasena(contrasena)
  if (error) throw new Error(error)
  db.tokens_restablecimiento.find((t) => t.token === token)!.usado = true
  auditar('restablecer_contrasena', 'usuario', revision.usuario.id, 'Restableció su contraseña', revision.usuario.id)
})

export const obtenerInvitacionPublica = (token: string) => llamar<InvitacionPublica>(() => {
  const invitacion = db.invitaciones.find((i) => i.token === token)
  const usuario = invitacion && buscarUsuario(invitacion.usuario_id)
  if (!invitacion || !usuario) return { estado: 'invalida', nombre_invitado: '', correo: '', organizacion_nombre: '', organizacion_tipo: 'plataforma', rol: 'cliente', portal: 'admin' }
  return {
    estado: estadoInvitacion(invitacion), nombre_invitado: usuario.nombre, correo: invitacion.correo, organizacion_nombre: nombreOrganizacion(invitacion.organizacion_tipo, invitacion.organizacion_id),
    organizacion_tipo: invitacion.organizacion_tipo, rol: invitacion.rol, portal: portalDeRol(invitacion.rol) ?? portalDeOrganizacion(invitacion.organizacion_tipo),
  }
})

export const aceptarInvitacion = (token: string, contrasena: string) => llamar<ResultadoAceptacion>(() => {
  const invitacion = db.invitaciones.find((i) => i.token === token)
  const usuario = invitacion && buscarUsuario(invitacion.usuario_id)
  if (!invitacion || !usuario) throw new Error('Esta invitación no es válida.')
  const estado = estadoInvitacion(invitacion)
  if (estado === 'aceptada') throw new Error('Esta invitación ya fue aceptada. Inicia sesión.')
  if (estado === 'vencida') throw new Error('Esta invitación venció. Pide que te la reenvíen.')
  const error = errorDeContrasena(contrasena)
  if (error) throw new Error(error)
  invitacion.aceptada_en = ahoraMock()
  usuario.activo = true
  const organizacion_nombre = nombreOrganizacion(invitacion.organizacion_tipo, invitacion.organizacion_id)
  auditar('aceptar_invitacion', 'usuario', usuario.id, `Aceptó la invitación a ${organizacion_nombre}`, usuario.id)
  return { portal: portalDeRol(invitacion.rol) ?? portalDeOrganizacion(invitacion.organizacion_tipo), rol: invitacion.rol, nombre: usuario.nombre, organizacion_nombre }
})

// ---------------------------------------------------------------- empleados de una empresa

function detalleEmpleados(empleados: Empleado[]): EmpleadoDetalle[] {
  const ids = new Set(empleados.map((e) => e.id))
  const porMes = new Map<string, Record<string, number>>()
  const manana = new Map<string, EleccionEmpleado>()
  for (const eleccion of db.elecciones_empleado) {
    if (!ids.has(eleccion.empleado_id)) continue
    if (eleccion.fecha === FECHA_MANANA) manana.set(eleccion.empleado_id, eleccion)
    const mes = eleccion.fecha.slice(0, 7)
    const acumulado = porMes.get(eleccion.empleado_id) ?? {}
    acumulado[mes] = (acumulado[mes] ?? 0) + 1
    porMes.set(eleccion.empleado_id, acumulado)
  }
  const invitaciones = new Map(db.invitaciones.filter((i) => i.rol === 'empleado').map((i) => [i.usuario_id, i]))
  return empleados.map((e) => {
    const usuario = buscarUsuario(e.usuario_id)!
    const eleccion = manana.get(e.id)
    return {
      ...e,
      nombre: usuario.nombre,
      correo: usuario.correo,
      platillos_por_mes: porMes.get(e.id) ?? {},
      invitacion: invitaciones.has(e.usuario_id) ? conEstadoInvitacion(invitaciones.get(e.usuario_id)!) : null,
      eleccion_manana: eleccion ? { platillo_id: eleccion.platillo_id, platillo_nombre: db.platillos.find((p) => p.id === eleccion.platillo_id)?.nombre ?? eleccion.platillo_id, origen: eleccion.origen } : null,
    }
  })
}

export const listarEmpleados = (empresa_id: string) => llamar<EmpleadoDetalle[]>(() => {
  const lista = empleadosDe(empresa_id)
  const nuevos = lista.filter((e) => e.id.includes('-N')).reverse()
  return detalleEmpleados([...nuevos, ...lista.filter((e) => !e.id.includes('-N'))])
})

export const agregarEmpleados = (empresa_id: string, personas: { nombre: string; correo: string }[]) => llamar<ResultadoAltaEmpleados>(() => {
  const empresa = db.empresas.find((e) => e.id === empresa_id)
  if (!empresa) throw new Error('La empresa no existe.')
  const conocidos = new Set(db.usuarios.map((u) => u.correo.toLowerCase()))
  let agregados = 0
  for (const persona of personas) {
    const correo = (persona.correo || db.correoDesdeNombre(persona.nombre, empresa.dominio)).toLowerCase()
    if (!persona.nombre || conocidos.has(correo)) continue
    conocidos.add(correo)
    const id = `${empresa_id}-N${empleadosDe(empresa_id).filter((e) => e.id.includes('-N')).length + 1}`
    db.usuarios.push({ id: `USR-${id}`, nombre: persona.nombre, correo, telefono: null, rol: 'empleado', activo: true })
    db.empleados.push({ id, usuario_id: `USR-${id}`, empresa_id, departamento: null, activo: true })
    crearInvitacion(`USR-${id}`, correo, 'empleado', 'empresa', empresa_id)
    agregados++
  }
  if (agregados) auditar('crear', 'empleado', empresa_id, `Agregó ${agregados} empleados a ${empresa.nombre}`)
  return { agregados, omitidos: personas.length - agregados }
})

function quitarEleccion(eleccion: EleccionEmpleado) {
  const disponibilidad = db.disponibilidad_dia.find((d) => d.platillo_id === eleccion.platillo_id && d.fecha === eleccion.fecha)
  if (disponibilidad) disponibilidad.porciones_tomadas = Math.max(0, disponibilidad.porciones_tomadas - 1)
  db.elecciones_empleado.splice(db.elecciones_empleado.indexOf(eleccion), 1)
}

export const cambiarEstadoEmpleado = (empleado_id: string, activo: boolean) => llamar<Empleado>(() => {
  const empleado = db.empleados.find((e) => e.id === empleado_id)
  if (!empleado) throw new Error('El empleado no existe.')
  empleado.activo = activo
  const usuario = buscarUsuario(empleado.usuario_id)
  if (usuario) usuario.activo = activo
  if (!activo) db.elecciones_empleado.filter((e) => e.empleado_id === empleado_id && e.fecha === FECHA_MANANA).forEach(quitarEleccion)
  auditar(activo ? 'activar' : 'desactivar', 'empleado', empleado_id, `${activo ? 'Activó' : 'Desactivó'} al empleado ${usuario?.nombre ?? empleado_id}`)
  return empleado
})

export const editarEmpleado = (empleado_id: string, datos: EdicionEmpleado) => llamar<Empleado>(() => {
  const empleado = db.empleados.find((e) => e.id === empleado_id)
  if (!empleado) throw new Error('El empleado no existe.')
  if (!datos.nombre.trim()) throw new Error('Escribe el nombre del empleado.')
  const usuario = buscarUsuario(empleado.usuario_id)
  if (usuario) usuario.nombre = datos.nombre.trim()
  empleado.departamento = datos.departamento.trim() || null
  auditar('actualizar', 'empleado', empleado_id, `Editó al empleado ${datos.nombre.trim()}`)
  return empleado
})

/** El empleado quita su elección de un día (mientras el corte siga abierto). */
export const quitarMiEleccion = (empleado_id: string, fecha: string, tiempo_comida: TiempoComida) => llamar<void>(() => {
  if (corteCerrado(fecha)) throw new Error('Los pedidos de ese día ya se cerraron.')
  const eleccion = db.elecciones_empleado.find((e) => e.empleado_id === empleado_id && e.fecha === fecha && e.tiempo_comida === tiempo_comida)
  if (!eleccion) throw new Error('No tienes una elección para ese día.')
  quitarEleccion(eleccion)
})

// ---------------------------------------------------------------- elecciones y corte de pedidos

function registrarEleccion(empleado: Empleado, fecha: string, tiempo_comida: TiempoComida, platillo_id: string, origen: EleccionEmpleado['origen']) {
  const contrato = contratoEmpresaDe(empleado.empresa_id)
  const previa = db.elecciones_empleado.find((e) => e.empleado_id === empleado.id && e.fecha === fecha && e.tiempo_comida === tiempo_comida)
  if (previa) quitarEleccion(previa)
  const disponibilidad = db.disponibilidad_dia.find((d) => d.platillo_id === platillo_id && d.fecha === fecha)!
  disponibilidad.porciones_tomadas++
  const eleccion: EleccionEmpleado = {
    id: `ELEC-${Date.now()}-${db.elecciones_empleado.length}`, empleado_id: empleado.id, fecha, tiempo_comida, platillo_id,
    precio_congelado: contrato.precio_por_platillo, porcentaje_subsidio_congelado: contrato.porcentaje_subsidio, origen,
  }
  db.elecciones_empleado.push(eleccion)
  return eleccion
}

const hayCupo = (platillo_id: string, fecha: string) => {
  const d = db.disponibilidad_dia.find((x) => x.platillo_id === platillo_id && x.fecha === fecha)
  return !!d && d.porciones_tomadas < d.porciones_maximas
}

/** El empleado elige su platillo; se congelan el precio y el subsidio vigentes del contrato. */
export const elegirPlatillo = (empleado_id: string, fecha: string, tiempo_comida: TiempoComida, platillo_id: string) => llamar<EleccionEmpleado>(() => {
  const empleado = db.empleados.find((e) => e.id === empleado_id)
  if (!empleado) throw new Error('El empleado no existe.')
  if (!empleado.activo) throw new Error('Este empleado está desactivado y no puede elegir platillo.')
  if (corteCerrado(fecha)) throw new Error('Los pedidos de ese día ya se cerraron.')
  if (empresaCerrada(empleado.empresa_id, fecha)) throw new Error('Tu empresa no tiene servicio ese día.')
  const actual = db.elecciones_empleado.find((e) => e.empleado_id === empleado_id && e.fecha === fecha && e.tiempo_comida === tiempo_comida)
  if (actual?.platillo_id !== platillo_id && !hayCupo(platillo_id, fecha)) throw new Error('Ya no hay porciones disponibles de este platillo.')
  return registrarEleccion(empleado, fecha, tiempo_comida, platillo_id, 'manual')
})

// ---------------------------------------------------------------- operación: configuración y calendario

export const obtenerConfiguracionOperacion = () => llamar<ConfiguracionOperacion>(() => db.configuracion_operacion)

export const guardarConfiguracionOperacion = (config: ConfiguracionOperacion) => llamar<ConfiguracionOperacion>(() => {
  if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(config.hora_corte)) throw new Error('La hora de corte no es válida.')
  if (!Number.isInteger(config.capacidad_auto_entregas) || config.capacidad_auto_entregas < 1) throw new Error('La capacidad del auto en entregas debe ser un número entero mayor a 0.')
  if (!Number.isInteger(config.capacidad_auto_platillos) || config.capacidad_auto_platillos < 1) throw new Error('La capacidad del auto en platillos debe ser un número entero mayor a 0.')
  Object.assign(db.configuracion_operacion, config)
  db.capacidades_vehiculo.automovil = config.capacidad_auto_entregas
  auditar('actualizar', 'configuracion_operacion', 'operacion', `Actualizó la configuración de operación (corte a las ${config.hora_corte})`)
  return db.configuracion_operacion
})

const diaSemana = (fecha: string) => new Date(`${fecha}T00:00:00Z`).getUTCDay()
const esDiaDeEntrega = (fecha: string) => diaSemana(fecha) !== 0 && (db.configuracion_operacion.dias_entrega === 'lunes_sabado' || diaSemana(fecha) !== 6)
const esDiaDeServicio = (fecha: string) => esDiaDeEntrega(fecha) && !db.dias_sin_servicio.some((d) => d.fecha === fecha && d.tipo !== 'cierre_empresa')
const empresaCerrada = (empresa_id: string, fecha: string) => db.dias_sin_servicio.some((d) => d.fecha === fecha && d.tipo === 'cierre_empresa' && d.empresa_id === empresa_id)
const corteCerrado = (fecha: string) => db.cortes.find((c) => c.fecha_entrega === fecha && c.estado !== 'fallido' && !c.sin_cambios)

function proximoDiaDeServicio(desde = FECHA_HOY) {
  for (let i = 1; i <= 90; i++) {
    const fecha = sumarDias(desde, i)
    if (esDiaDeServicio(fecha)) return fecha
  }
  throw new Error('No hay días de servicio en los próximos 90 días.')
}

function validarDiaEditable(fecha: string) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(fecha)) throw new Error('La fecha no es válida.')
  if (fecha <= FECHA_HOY || corteCerrado(fecha)) throw new Error('Ese día ya pasó o ya está cerrado por el corte; no se puede modificar.')
}

export const obtenerCalendarioServicio = (mes: string) => llamar<CalendarioServicio>(() => ({
  mes,
  dias: db.dias_sin_servicio
    .filter((d) => d.fecha.startsWith(mes))
    .map((d) => ({ ...d, empresa_nombre: d.empresa_id ? db.empresas.find((e) => e.id === d.empresa_id)?.nombre ?? d.empresa_id : null })),
  fechas_cerradas: [...new Set(db.cortes.filter((c) => c.fecha_entrega.startsWith(mes) && c.estado !== 'fallido').map((c) => c.fecha_entrega))],
}))

/** Marca un festivo o un día sin servicio para todo el servicio; reemplaza la marca anterior de ese día. */
export const marcarDiaSinServicio = (datos: { fecha: string; tipo: 'festivo' | 'sin_servicio'; motivo: string }) => llamar<DiaSinServicio>(() => {
  validarDiaEditable(datos.fecha)
  if (!datos.motivo.trim()) throw new Error('Escribe el motivo.')
  db.dias_sin_servicio.splice(0, db.dias_sin_servicio.length, ...db.dias_sin_servicio.filter((d) => !(d.fecha === datos.fecha && d.tipo !== 'cierre_empresa')))
  const dia: DiaSinServicio = { id: `DSS-${Date.now()}`, fecha: datos.fecha, tipo: datos.tipo, motivo: datos.motivo.trim(), empresa_id: null }
  db.dias_sin_servicio.push(dia)
  auditar('crear', 'dia_sin_servicio', dia.id, `Marcó el ${datos.fecha} como ${datos.tipo === 'festivo' ? 'festivo' : 'día sin servicio'}: ${dia.motivo}`)
  return dia
})

export const cerrarEmpresaDia = (datos: { fecha: string; empresa_id: string; motivo: string }) => llamar<DiaSinServicio>(() => {
  validarDiaEditable(datos.fecha)
  const empresa = db.empresas.find((e) => e.id === datos.empresa_id)
  if (!empresa) throw new Error('La empresa no existe.')
  if (!datos.motivo.trim()) throw new Error('Escribe el motivo.')
  if (empresaCerrada(empresa.id, datos.fecha)) throw new Error(`${empresa.nombre} ya está cerrada ese día.`)
  const dia: DiaSinServicio = { id: `DSS-${Date.now()}`, fecha: datos.fecha, tipo: 'cierre_empresa', motivo: datos.motivo.trim(), empresa_id: empresa.id }
  db.dias_sin_servicio.push(dia)
  auditar('crear', 'dia_sin_servicio', dia.id, `Cerró ${empresa.nombre} el ${datos.fecha}`)
  return dia
})

export const quitarDiaSinServicio = (id: string) => llamar<void>(() => {
  const indice = db.dias_sin_servicio.findIndex((d) => d.id === id)
  if (indice < 0) throw new Error('Ese día ya no está marcado.')
  validarDiaEditable(db.dias_sin_servicio[indice].fecha)
  const [quitado] = db.dias_sin_servicio.splice(indice, 1)
  auditar('eliminar', 'dia_sin_servicio', id, `Quitó la marca del ${quitado.fecha}`)
})

// ---------------------------------------------------------------- corte de pedidos

export const listarCortes = () => llamar<Corte[]>(() => [...db.cortes].sort((a, b) => b.ejecutado_en.localeCompare(a.ejecutado_en)))

export const obtenerVistaPreviaCorte = () => llamar<VistaPreviaCorte>(() => {
  const fecha = proximoDiaDeServicio()
  const activos = db.empleados.filter((e) => e.activo && !empresaCerrada(e.empresa_id, fecha))
  const conEleccion = new Set(db.elecciones_empleado.filter((e) => e.fecha === fecha).map((e) => e.empleado_id))
  return {
    fecha_entrega: fecha,
    hora_corte: db.configuracion_operacion.hora_corte,
    regla_sin_eleccion: db.configuracion_operacion.regla_sin_eleccion,
    ya_cerrado: !!corteCerrado(fecha),
    empleados_activos: activos.length,
    sin_elegir: activos.filter((e) => !conEleccion.has(e.id)).length,
  }
})

/**
 * Cierra las elecciones del próximo día de servicio: aplica la regla a quien no eligió, genera las órdenes de
 * producción, consolida las entregas por empresa y crea las rutas. Es idempotente: si el día ya estaba cerrado
 * no toca nada y lo registra como "sin cambios".
 */
export const ejecutarCorte = () => llamar<ResultadoCorte>(() => {
  const config = db.configuracion_operacion
  const fecha = proximoDiaDeServicio()
  const compacta = fecha.replace(/-/g, '')

  const registrar = (campos: Partial<Corte>) => {
    const corte: Corte = {
      id: siguienteId('CORTE-', db.cortes.map((c) => c.id)), fecha_entrega: fecha, ejecutado_en: ahora(), ejecutado_por: USUARIO_ACTUAL_ID, estado: 'correcto', sin_cambios: false,
      elecciones_cerradas: 0, empleados_por_defecto: 0, ordenes_generadas: 0, entregas_consolidadas: 0, rutas_creadas: 0, mensaje: null, ...campos,
    }
    db.cortes.push(corte)
    auditar('ejecutar_corte', 'corte', corte.id, `Ejecutó el corte del ${fecha}${corte.sin_cambios ? ' (sin cambios)' : corte.estado === 'fallido' ? ' (fallido)' : ''}`)
    return corte
  }
  const vacio = (corte: Corte): ResultadoCorte => ({ corte, fecha_entrega: fecha, sin_cambios: false, elecciones_cerradas: 0, empleados_por_defecto: [], empleados_sin_entrega: 0, ordenes_por_comedor: [], entregas_por_empresa: [], rutas: [], advertencias: [] })

  const previo = corteCerrado(fecha)
  if (previo) {
    const corte = registrar({ ...previo, id: siguienteId('CORTE-', db.cortes.map((c) => c.id)), ejecutado_en: ahora(), ejecutado_por: USUARIO_ACTUAL_ID, sin_cambios: true, mensaje: 'Sin cambios; ya estaba cerrado.' })
    const resultado = { ...(db.resultados_corte[previo.id] ?? vacio(previo)), corte, sin_cambios: true }
    db.resultados_corte[corte.id] = resultado
    return resultado
  }

  const menu = db.disponibilidad_dia.filter((d) => d.fecha === fecha)
  if (!menu.length) return vacio(registrar({ estado: 'fallido', mensaje: 'No hay menú publicado para esa fecha.' }))

  const advertencias: string[] = []
  const cerradas = new Set(db.dias_sin_servicio.filter((d) => d.fecha === fecha && d.tipo === 'cierre_empresa').map((d) => d.empresa_id))
  const empresaDe = (empleado_id: string) => db.empleados.find((e) => e.id === empleado_id)!.empresa_id

  const canceladas = db.elecciones_empleado.filter((e) => e.fecha === fecha && cerradas.has(empresaDe(e.empleado_id)))
  canceladas.forEach(quitarEleccion)
  if (canceladas.length) advertencias.push(`${canceladas.length} elecciones canceladas porque su empresa está cerrada ese día.`)

  // Empleados sin elección: se aplica la regla configurada.
  const ultimaEleccion = new Map<string, EleccionEmpleado>()
  for (const e of db.elecciones_empleado) if (e.fecha < fecha) ultimaEleccion.set(e.empleado_id, e)
  const platilloDelDia = () => menu.find((d) => d.porciones_tomadas < d.porciones_maximas)?.platillo_id
  const eligieron = new Set(db.elecciones_empleado.filter((e) => e.fecha === fecha).map((e) => e.empleado_id))
  const porDefecto: ResultadoCorte['empleados_por_defecto'] = []
  let sinEntrega = 0
  let sinCupo = 0
  let reemplazados = 0
  for (const empleado of db.empleados.filter((e) => e.activo && !cerradas.has(e.empresa_id) && !eligieron.has(e.id))) {
    if (config.regla_sin_eleccion === 'no_entregar') { sinEntrega++; continue }
    let platillo_id: string | undefined
    if (config.regla_sin_eleccion === 'ultimo_platillo') {
      const ultima = ultimaEleccion.get(empleado.id)
      if (ultima && hayCupo(ultima.platillo_id, fecha)) platillo_id = ultima.platillo_id
      else { platillo_id = platilloDelDia(); if (platillo_id) reemplazados++ }
    } else platillo_id = platilloDelDia()
    if (!platillo_id) { sinCupo++; continue }
    registrarEleccion(empleado, fecha, 'comida', platillo_id, 'por_defecto')
    porDefecto.push({ empleado_id: empleado.id, nombre: nombreDe(empleado.usuario_id), empresa_nombre: db.empresas.find((e) => e.id === empleado.empresa_id)!.nombre, platillo_nombre: db.platillos.find((p) => p.id === platillo_id)!.nombre })
  }
  if (reemplazados) advertencias.push(`${reemplazados} empleados sin historial (o sin cupo en su último platillo) recibieron el platillo del día.`)
  if (sinCupo) advertencias.push(`${sinCupo} empleados sin cupo en ningún platillo; no se les asignó entrega.`)

  const elecciones = db.elecciones_empleado.filter((e) => e.fecha === fecha)

  // Órdenes de producción por platillo (se reemplazan las del día).
  db.ordenes_produccion.splice(0, db.ordenes_produccion.length, ...db.ordenes_produccion.filter((o) => o.fecha !== fecha))
  const recoleccion = { desayuno: '08:00', comida: '10:30', cena: '12:00' } as const
  const porPlatillo = new Map<string, number>()
  for (const e of elecciones) porPlatillo.set(e.platillo_id, (porPlatillo.get(e.platillo_id) ?? 0) + 1)
  const porComedor = new Map<string, { comedor_id: string; comedor_nombre: string; ordenes: number; porciones: number }>()
  let numeroOrden = 0
  for (const [platillo_id, cantidad] of porPlatillo) {
    const platillo = db.platillos.find((p) => p.id === platillo_id)!
    db.ordenes_produccion.push({ id: `OP-${compacta}-${String(++numeroOrden).padStart(2, '0')}`, comedor_id: platillo.comedor_id, platillo_id, fecha, porciones_totales: cantidad, porciones_empresas: cantidad, porciones_individuales: 0, hora_recoleccion: recoleccion[platillo.tiempo_comida], estado: 'pendiente' })
    const acumulado = porComedor.get(platillo.comedor_id) ?? { comedor_id: platillo.comedor_id, comedor_nombre: db.comedores.find((c) => c.id === platillo.comedor_id)!.nombre, ordenes: 0, porciones: 0 }
    acumulado.ordenes++
    acumulado.porciones += cantidad
    porComedor.set(platillo.comedor_id, acumulado)
  }

  // Entregas consolidadas por empresa.
  db.entregas_empresa.splice(0, db.entregas_empresa.length, ...db.entregas_empresa.filter((e) => e.fecha !== fecha))
  const platillosPorEmpresa = new Map<string, number>()
  for (const e of elecciones) platillosPorEmpresa.set(empresaDe(e.empleado_id), (platillosPorEmpresa.get(empresaDe(e.empleado_id)) ?? 0) + 1)
  let numeroEntrega = Math.max(...db.entregas_empresa.map((e) => Number(e.id.replace(/\D/g, ''))), 1100)
  const entregas = db.empresas.filter((e) => platillosPorEmpresa.has(e.id)).map((empresa) => {
    const contrato = contratoEmpresaDe(empresa.id)
    const entrega: EntregaEmpresa = { id: `EL-${++numeroEntrega}`, empresa_id: empresa.id, ruta_id: null, fecha, zona: empresa.zona, ventana_inicio: contrato.ventana_entrega_inicio, ventana_fin: contrato.ventana_entrega_fin, estado: 'en_consolidacion', tipo_vehiculo: 'automovil' }
    db.entregas_empresa.push(entrega)
    return { entrega, empresa, platillos: platillosPorEmpresa.get(empresa.id)!, hora: contrato.hora_entrega }
  })

  // Rutas: se llena cada auto hasta su capacidad (entregas y platillos), agrupando por zona.
  const idsRuta = db.rutas.filter((r) => r.fecha === fecha).map((r) => r.id)
  db.rutas.splice(0, db.rutas.length, ...db.rutas.filter((r) => r.fecha !== fecha))
  db.paradas.splice(0, db.paradas.length, ...db.paradas.filter((p) => !idsRuta.includes(p.ruta_id)))
  const autos = db.repartidores.filter((r) => r.tipo_vehiculo === 'automovil' && r.estado !== 'no_disponible')
  const ordenadas = [...entregas].sort((a, b) => a.empresa.zona.localeCompare(b.empresa.zona) || a.hora.localeCompare(b.hora))
  const grupos: (typeof entregas)[] = []
  let actual: typeof entregas = []
  let platillosActual = 0
  for (const item of ordenadas) {
    if (actual.length && (actual.length + 1 > config.capacidad_auto_entregas || platillosActual + item.platillos > config.capacidad_auto_platillos)) { grupos.push(actual); actual = []; platillosActual = 0 }
    if (!actual.length && item.platillos > config.capacidad_auto_platillos) advertencias.push(`${item.empresa.nombre} (${item.platillos} platillos) excede la capacidad de un auto; se le asignó una ruta completa.`)
    actual.push(item)
    platillosActual += item.platillos
  }
  if (actual.length) grupos.push(actual)
  const rutas: ResultadoCorte['rutas'] = []
  grupos.forEach((grupo, i) => {
    const total = grupo.reduce((suma, g) => suma + g.platillos, 0)
    const repartidor = autos[i]
    if (!repartidor) { advertencias.push(`Sin repartidor disponible para ${grupo.length} entregas (${grupo.map((g) => g.empresa.nombre).join(', ')}); quedaron sin ruta.`); return }
    const porHora = [...grupo].sort((a, b) => a.hora.localeCompare(b.hora))
    const ruta: Ruta = {
      id: `R-${compacta.slice(4)}-${String(i + 1).padStart(2, '0')}`, fecha, repartidor_id: repartidor.id, tipo_vehiculo: 'automovil', zona: [...new Set(grupo.map((g) => g.empresa.zona))].join(' · '), bloque: 'mediodia',
      ventana_inicio: porHora[0].entrega.ventana_inicio, ventana_fin: [...porHora].sort((a, b) => b.entrega.ventana_fin.localeCompare(a.entrega.ventana_fin))[0].entrega.ventana_fin,
      capacidad_maxima: config.capacidad_auto_entregas, entregas_asignadas: grupo.length, distancia_km: Math.round((6 + grupo.length * 3.7) * 10) / 10, hora_fin_estimada: porHora[porHora.length - 1].hora, excede_bloque: false, estado: 'borrador',
    }
    db.rutas.push(ruta)
    porHora.forEach((g, orden) => {
      g.entrega.ruta_id = ruta.id
      db.paradas.push({ id: `PAR-${ruta.id}-${orden + 1}`, ruta_id: ruta.id, orden: orden + 1, entrega_id: g.entrega.id, nombre_destinatario: g.empresa.nombre, direccion: g.empresa.direccion_entrega, hora_estimada: g.hora, estado: 'pendiente' })
    })
    rutas.push({ id: ruta.id, repartidor_nombre: nombreDe(repartidor.usuario_id), zona: ruta.zona, entregas: grupo.length, platillos: total })
  })

  const corte = registrar({
    estado: advertencias.length ? 'con_advertencias' : 'correcto', elecciones_cerradas: elecciones.length, empleados_por_defecto: porDefecto.length, ordenes_generadas: numeroOrden,
    entregas_consolidadas: entregas.length, rutas_creadas: rutas.length, mensaje: advertencias.length ? advertencias.join(' ') : null,
  })
  const resultado: ResultadoCorte = {
    corte, fecha_entrega: fecha, sin_cambios: false, elecciones_cerradas: elecciones.length, empleados_por_defecto: porDefecto, empleados_sin_entrega: sinEntrega + sinCupo,
    ordenes_por_comedor: [...porComedor.values()],
    entregas_por_empresa: entregas.map((g) => ({ empresa_id: g.empresa.id, empresa_nombre: g.empresa.nombre, platillos: g.platillos, hora_entrega: g.hora, ruta_id: g.entrega.ruta_id })),
    rutas, advertencias,
  }
  db.resultados_corte[corte.id] = resultado
  return resultado
})

export const listarPedidosManana = (empresa_id: string) => llamar<PedidosMananaEmpresa>(() => {
  const empleados = empleadosDe(empresa_id)
  const elecciones = db.elecciones_empleado.filter((e) => e.fecha === FECHA_MANANA)
  const grupos = db.MENU_MANANA
    .map((platillo_id) => ({
      platillo_id,
      platillo_nombre: db.platillos.find((p) => p.id === platillo_id)!.nombre,
      personas: elecciones.filter((e) => e.platillo_id === platillo_id).flatMap((e) => {
        const empleado = empleados.find((x) => x.id === e.empleado_id)
        return empleado ? [{ empleado_id: empleado.id, nombre: nombreDe(empleado.usuario_id) }] : []
      }),
    }))
    .filter((g) => g.personas.length)
    .sort((a, b) => b.personas.length - a.personas.length)
  const pendientes = empleados
    .filter((e) => e.activo && !elecciones.some((x) => x.empleado_id === e.id))
    .map((e) => ({ empleado_id: e.id, nombre: nombreDe(e.usuario_id) }))
  return { fecha: FECHA_MANANA, total: grupos.reduce((suma, g) => suma + g.personas.length, 0), grupos, pendientes }
})

// ---------------------------------------------------------------- consumo y facturación

function totalesFactura(factura: FacturaMensual): FacturaMensual {
  // Las facturas emitidas quedan congeladas; el borrador se recalcula con el consumo vigente.
  if (factura.estado !== 'borrador') return factura
  const lineas = calcularConsumo(empleadosDe(factura.empresa_id), buscarUsuario, db.elecciones_empleado, factura.mes)
  return { ...factura, ...calcularTotales(factura.empresa_id, contratoEmpresaDe(factura.empresa_id), lineas, factura.mes, factura.id) }
}

const facturaDe = (empresa_id: string, mes: string) => {
  const factura = db.facturas_mensuales.find((f) => f.empresa_id === empresa_id && f.mes === mes)
  if (!factura) throw new Error('No hay factura para ese mes.')
  return factura
}

export const listarFacturasEmpresa = (empresa_id: string) => llamar<FacturaMensual[]>(() => MESES.map((mes) => totalesFactura(facturaDe(empresa_id, mes))))

export const obtenerFactura = (empresa_id: string, mes: string) => llamar<FacturaDetalle>(() => {
  const factura = totalesFactura(facturaDe(empresa_id, mes))
  const lineas = calcularConsumo(empleadosDe(empresa_id), buscarUsuario, db.elecciones_empleado, mes)
  return { ...factura, importe_consumo: factura.parte_empresa + factura.parte_empleados, lineas }
})

export const listarFacturas = (mes: string) => llamar<FacturaConEmpresa[]>(() =>
  db.empresas.map((empresa) => ({ ...totalesFactura(facturaDe(empresa.id, mes)), empresa: detalleEmpresa(empresa) })),
)

/** Convierte en "emitida" todas las facturas en borrador del mes y las congela. Devuelve las que emitió. */
export const generarFacturacion = (mes: string) => llamar<FacturaMensual[]>(() => {
  const emitidas: FacturaMensual[] = []
  db.facturas_mensuales.forEach((factura, i) => {
    if (factura.mes !== mes || factura.estado !== 'borrador') return
    db.facturas_mensuales[i] = { ...totalesFactura(factura), estado: 'emitida', emitida_en: FECHA_HOY, pagada_en: null }
    emitidas.push(db.facturas_mensuales[i])
  })
  if (emitidas.length) auditar('generar_facturacion', 'factura_mensual', mes, `Generó la facturación de ${etiquetaMes(mes)} (${emitidas.length} facturas)`)
  return emitidas
})

export const marcarFacturaPagada = (factura_id: string) => llamar<FacturaMensual>(() => {
  const factura = db.facturas_mensuales.find((f) => f.id === factura_id)
  if (!factura) throw new Error('La factura no existe.')
  if (factura.estado !== 'emitida') throw new Error('Solo se puede marcar como pagada una factura emitida.')
  factura.estado = 'pagada'
  factura.pagada_en = FECHA_HOY
  auditar('marcar_pagada', 'factura_mensual', factura_id, `Marcó como pagada la factura ${factura_id}`)
  return factura
})

export const listarLiquidaciones = (mes: string) => llamar<LiquidacionConComedor[]>(() =>
  db.liquidaciones_comedor.filter((l) => l.mes === mes).map((l) => ({ ...l, comedor: db.comedores.find((c) => c.id === l.comedor_id)! })),
)

export const listarLiquidacionesComedor = (comedor_id: string) => llamar<LiquidacionComedor[]>(() =>
  db.liquidaciones_comedor.filter((l) => l.comedor_id === comedor_id).sort((a, b) => b.mes.localeCompare(a.mes)),
)

export const marcarLiquidacionPagada = (liquidacion_id: string) => llamar<LiquidacionComedor>(() => {
  const liquidacion = db.liquidaciones_comedor.find((l) => l.id === liquidacion_id)
  if (!liquidacion) throw new Error('La liquidación no existe.')
  liquidacion.estado = 'pagado'
  liquidacion.pagada_en = FECHA_HOY
  auditar('marcar_pagada', 'liquidacion_comedor', liquidacion_id, `Marcó como pagada la liquidación de ${db.comedores.find((c) => c.id === liquidacion.comedor_id)?.nombre ?? liquidacion.comedor_id} (${liquidacion.mes})`)
  return liquidacion
})

// ---------------------------------------------------------------- comedores

function detalleComedor(comedor: Comedor): ComedorDetalle {
  const contrato = contratoComedorDe(comedor.id)
  const entregados = db.liquidaciones_comedor.find((l) => l.comedor_id === comedor.id && l.mes === MES_ACTUAL)?.platillos_entregados ?? 0
  return {
    ...comedor,
    contacto: resumenUsuario(buscarUsuario(comedor.usuario_id)!),
    contrato,
    platillos_publicados: db.platillos.filter((p) => p.comedor_id === comedor.id && p.estado_revision === 'publicado').length,
    platillos_entregados_mes: entregados,
    avance_minimo_porcentaje: Math.min(100, Math.round((entregados / contrato.compra_minima_mensual) * 100)),
    estado_contrato: estadoContrato(contrato.vigencia_fin),
    dias_para_vencer: diasEntre(FECHA_HOY, contrato.vigencia_fin),
  }
}

export const listarComedores = () => llamar<ComedorDetalle[]>(() => db.comedores.map(detalleComedor))

export const editarComedor = (comedor_id: string, datos: EdicionComedor) => llamar<ComedorDetalle>(() => {
  const comedor = db.comedores.find((c) => c.id === comedor_id)
  if (!comedor) throw new Error('El comedor no existe.')
  if (!datos.nombre.trim()) throw new Error('Escribe el nombre del comedor.')
  if (datos.precio_por_platillo <= 0) throw new Error('El precio por platillo debe ser mayor a cero.')
  Object.assign(comedor, { nombre: datos.nombre.trim(), zona: datos.zona.trim() || comedor.zona })
  Object.assign(contratoComedorDe(comedor_id), { precio_por_platillo: datos.precio_por_platillo, compra_minima_mensual: datos.compra_minima_mensual })
  const contacto = buscarUsuario(comedor.usuario_id)
  if (contacto) Object.assign(contacto, { nombre: datos.contacto_nombre.trim() || contacto.nombre, telefono: datos.contacto_telefono.trim() || null })
  auditar('actualizar', 'comedor', comedor_id, `Editó el comedor ${comedor.nombre}`)
  return detalleComedor(comedor)
})

export const cambiarEstadoComedor = (comedor_id: string, activo: boolean) => llamar<ComedorDetalle>(() => {
  const comedor = db.comedores.find((c) => c.id === comedor_id)
  if (!comedor) throw new Error('El comedor no existe.')
  comedor.activo = activo
  auditar(activo ? 'activar' : 'desactivar', 'comedor', comedor_id, `${activo ? 'Reactivó' : 'Desactivó'} el comedor ${comedor.nombre}`)
  return detalleComedor(comedor)
})

export const obtenerComedor = (comedor_id: string) => llamar<ComedorDetalle>(() => {
  const comedor = db.comedores.find((c) => c.id === comedor_id)
  if (!comedor) throw new Error('El comedor no existe.')
  return detalleComedor(comedor)
})

// ---------------------------------------------------------------- platillos, menús y disponibilidad

const conComedor = (p: Platillo): PlatilloDetalle => ({ ...p, comedor_nombre: db.comedores.find((c) => c.id === p.comedor_id)?.nombre ?? p.comedor_id })

export const listarPlatillos = (filtro: { comedor_id?: string; estado_revision?: Platillo['estado_revision'] } = {}) =>
  llamar<PlatilloDetalle[]>(() =>
    db.platillos.filter((p) => (!filtro.comedor_id || p.comedor_id === filtro.comedor_id) && (!filtro.estado_revision || p.estado_revision === filtro.estado_revision)).map(conComedor),
  )

export const guardarPlatillo = (datos: GuardarPlatillo) => llamar<PlatilloDetalle>(() => {
  if (!datos.nombre.trim()) throw new Error('Escribe el nombre del platillo.')
  const existente = datos.id ? db.platillos.find((p) => p.id === datos.id) : undefined
  if (existente) {
    Object.assign(existente, { ...datos, nombre: datos.nombre.trim(), precio_venta: datos.precio_venta ?? existente.precio_venta })
    auditar('actualizar', 'platillo', existente.id, `Actualizó el platillo ${existente.nombre}`)
    return conComedor(existente)
  }
  const platillo: Platillo = {
    id: siguienteId('P-', db.platillos.map((p) => p.id)), comedor_id: datos.comedor_id, nombre: datos.nombre.trim(), descripcion: datos.descripcion, tiempo_comida: datos.tiempo_comida,
    calorias: datos.calorias, alergenos: datos.alergenos, etiquetas: [], precio_venta: datos.precio_venta ?? contratoComedorDe(datos.comedor_id).precio_por_platillo, foto_url: null,
    estado_revision: datos.estado_revision, motivo_rechazo: null, activo: true,
  }
  db.platillos.push(platillo)
  auditar('crear', 'platillo', platillo.id, `Creó el platillo ${platillo.nombre}`)
  return conComedor(platillo)
})

export const revisarPlatillo = (platillo_id: string, decision: 'aprobar' | 'rechazar', motivo?: string) => llamar<PlatilloDetalle>(() => {
  const platillo = db.platillos.find((p) => p.id === platillo_id)
  if (!platillo) throw new Error('El platillo no existe.')
  if (decision === 'rechazar' && !motivo?.trim()) throw new Error('Escribe el motivo del rechazo.')
  platillo.estado_revision = decision === 'aprobar' ? 'publicado' : 'rechazado'
  platillo.motivo_rechazo = decision === 'rechazar' ? motivo!.trim() : null
  auditar(decision, 'platillo', platillo_id, `${decision === 'aprobar' ? 'Aprobó' : 'Rechazó'} el platillo ${platillo.nombre}`)
  return conComedor(platillo)
})

export const cambiarEstadoPlatillo = (platillo_id: string, activo: boolean) => llamar<PlatilloDetalle>(() => {
  const platillo = db.platillos.find((p) => p.id === platillo_id)
  if (!platillo) throw new Error('El platillo no existe.')
  platillo.activo = activo
  auditar(activo ? 'activar' : 'desactivar', 'platillo', platillo_id, `${activo ? 'Reactivó' : 'Desactivó'} el platillo ${platillo.nombre}`)
  return conComedor(platillo)
})

export const editarPlan = (plan_id: string, datos: EdicionPlan) => llamar<Plan>(() => {
  const plan = db.planes.find((p) => p.id === plan_id)
  if (!plan) throw new Error('El plan no existe.')
  if (!datos.nombre.trim()) throw new Error('Escribe el nombre del plan.')
  if (datos.descuento_porcentaje < 0 || datos.descuento_porcentaje > 100) throw new Error('El descuento debe estar entre 0 y 100 %.')
  Object.assign(plan, { nombre: datos.nombre.trim(), descuento_porcentaje: datos.descuento_porcentaje, detalle: datos.detalle.trim(), destacado: datos.destacado })
  auditar('actualizar', 'plan', plan_id, `Editó el plan ${plan.nombre}`)
  return plan
})

export const listarHistorialPrecios = (platillo_id: string) =>
  llamar<HistorialPrecio[]>(() => db.historial_precios.filter((h) => h.platillo_id === platillo_id).sort((a, b) => b.vigente_desde.localeCompare(a.vigente_desde)))

/** Programa un precio de venta nuevo; el anterior queda vigente hasta el día previo. */
export const programarPrecioPlatillo = (platillo_id: string, datos: ProgramarPrecio) => llamar<HistorialPrecio>(() => {
  const platillo = db.platillos.find((p) => p.id === platillo_id)
  if (!platillo) throw new Error('El platillo no existe.')
  if (!(datos.precio_venta > 0)) throw new Error('El precio debe ser mayor a cero.')
  if (!datos.vigente_desde || datos.vigente_desde < FECHA_MANANA) throw new Error('El precio nuevo debe empezar a partir de mañana.')
  const actual = db.historial_precios.filter((h) => h.platillo_id === platillo_id && !h.vigente_hasta).sort((a, b) => b.vigente_desde.localeCompare(a.vigente_desde))[0]
  if (actual) actual.vigente_hasta = sumarDias(datos.vigente_desde, -1)
  const registro: HistorialPrecio = { id: `HP-${Date.now()}`, platillo_id, vigente_desde: datos.vigente_desde, vigente_hasta: null, precio_venta: datos.precio_venta, costo: actual?.costo ?? 0 }
  db.historial_precios.push(registro)
  auditar('programar_precio', 'platillo', platillo_id, `Programó el precio de ${platillo.nombre} en $${datos.precio_venta} desde ${datos.vigente_desde}`)
  return registro
})

export const listarPlanes = () => llamar<Plan[]>(() => db.planes)

export const obtenerMenuSemanal = (fecha_inicio: string) => llamar<MenuSemanalDia[]>(() =>
  Array.from({ length: 6 }, (_, i) => {
    const fecha = sumarDias(fecha_inicio, i)
    const celdas: MenuSemanalDia['celdas'] = {}
    for (const d of db.disponibilidad_dia.filter((x) => x.fecha === fecha)) {
      const platillo = db.platillos.find((p) => p.id === d.platillo_id)
      if (platillo && !celdas[platillo.tiempo_comida]) celdas[platillo.tiempo_comida] = { platillo_id: platillo.id, platillo_nombre: platillo.nombre, precio_venta: platillo.precio_venta, porciones_maximas: d.porciones_maximas }
    }
    return { fecha, celdas }
  }),
)

export const listarDisponibilidad = (comedor_id: string, desde: string, hasta: string) =>
  llamar<DisponibilidadConPlatillo[]>(() =>
    db.disponibilidad_dia
      .filter((d) => d.fecha >= desde && d.fecha <= hasta)
      .flatMap((d) => {
        const platillo = db.platillos.find((p) => p.id === d.platillo_id)
        return platillo?.comedor_id === comedor_id ? [{ ...d, platillo_nombre: platillo.nombre }] : []
      }),
  )

export const guardarDisponibilidad = (cambios: CambioDisponibilidad[]) => llamar<number>(() => {
  for (const cambio of cambios) {
    const fila = db.disponibilidad_dia.find((d) => d.platillo_id === cambio.platillo_id && d.fecha === cambio.fecha)
    if (fila) fila.porciones_maximas = cambio.porciones_maximas
    else db.disponibilidad_dia.push({ id: `DISP-${cambio.fecha}-${cambio.platillo_id}`, ...cambio, porciones_tomadas: 0 })
  }
  auditar('actualizar', 'disponibilidad_dia', 'lote', `Actualizó la disponibilidad (${cambios.length} cambios)`)
  return cambios.length
})

// ---------------------------------------------------------------- producción

export const listarOrdenesProduccion = (comedor_id: string, fecha: string) =>
  llamar<OrdenProduccionDetalle[]>(() =>
    db.ordenes_produccion.filter((o) => o.comedor_id === comedor_id && o.fecha === fecha).map((o) => {
      const platillo = db.platillos.find((p) => p.id === o.platillo_id)!
      return { ...o, platillo_nombre: platillo.nombre, tiempo_comida: platillo.tiempo_comida }
    }),
  )

export const marcarOrdenesListas = (comedor_id: string, fecha: string) => llamar<number>(() => {
  const ordenes = db.ordenes_produccion.filter((o) => o.comedor_id === comedor_id && o.fecha === fecha)
  ordenes.forEach((o) => { o.estado = 'lista' })
  if (ordenes.length) auditar('marcar_lista', 'orden_produccion', `${comedor_id}:${fecha}`, `Marcó como lista la producción del ${fecha}`)
  return ordenes.length
})

export const reporteProveedor = (fecha: string) => llamar<FilaReporteProveedor[]>(() =>
  db.ordenes_produccion.filter((o) => o.fecha === fecha).map((o) => {
    const platillo = db.platillos.find((p) => p.id === o.platillo_id)!
    return {
      comedor_nombre: db.comedores.find((c) => c.id === o.comedor_id)!.nombre, platillo_nombre: platillo.nombre, tiempo_comida: platillo.tiempo_comida,
      porciones: o.porciones_totales, costo_total: o.porciones_totales * contratoComedorDe(o.comedor_id).precio_por_platillo,
    }
  }),
)

// ---------------------------------------------------------------- repartidores y rutas

const conNombre = (r: Repartidor): RepartidorDetalle => ({ ...r, nombre: nombreDe(r.usuario_id) })

export const listarRepartidores = (filtro: { estado?: RepartidorDetalle['estado'] } = {}) =>
  llamar<RepartidorDetalle[]>(() => db.repartidores.filter((r) => !filtro.estado || r.estado === filtro.estado).map(conNombre))

export const obtenerCapacidadesVehiculo = () => llamar<Record<TipoVehiculo, number>>(() => db.capacidades_vehiculo)

export const cambiarDisponibilidadRepartidor = (repartidor_id: string) => llamar<RepartidorDetalle>(() => {
  const repartidor = db.repartidores.find((r) => r.id === repartidor_id)
  if (!repartidor) throw new Error('El repartidor no existe.')
  repartidor.estado = repartidor.estado === 'no_disponible' ? 'libre' : 'no_disponible'
  auditar('cambiar_disponibilidad', 'repartidor', repartidor_id, `${repartidor.estado === 'no_disponible' ? 'Marcó como no disponible' : 'Liberó'} al repartidor ${nombreDe(repartidor.usuario_id)}`)
  return conNombre(repartidor)
})

export const crearRepartidor = (datos: NuevoRepartidor) => llamar<RepartidorDetalle>(() => {
  if (!datos.nombre.trim()) throw new Error('Escribe el nombre del repartidor.')
  if (datos.tipo_vehiculo !== 'bicicleta' && !datos.placas.trim()) throw new Error('Las placas son obligatorias para moto y carro.')
  const id = siguienteId('REP-', db.repartidores.map((r) => r.id))
  const usuario: Usuario = { id: `USR-${id}`, nombre: datos.nombre.trim(), correo: datos.correo.trim() || `${id.toLowerCase()}@easylunch.mx`, telefono: datos.telefono.trim() || null, rol: 'repartidor', activo: true }
  const repartidor: Repartidor = {
    id, usuario_id: usuario.id, numero_empleado: datos.numero_empleado.trim() || `EMP-${id.slice(4).padStart(4, '0')}`, tipo_vehiculo: datos.tipo_vehiculo, vehiculo_personalizado: false,
    placas: datos.tipo_vehiculo === 'bicicleta' ? null : datos.placas.trim(), zona_base: datos.zona_base.trim() || 'Centro', estado: 'libre', bloques_reservados: [],
    entregas_asignadas: 0, entregas_completadas: 0, ultima_ubicacion_en: AHORA_MOCK,
  }
  db.usuarios.push(usuario)
  db.repartidores.push(repartidor)
  auditar('crear', 'repartidor', id, `Dio de alta al repartidor ${usuario.nombre}`)
  return conNombre(repartidor)
})

export const editarRepartidor = (repartidor_id: string, datos: EdicionRepartidor) => llamar<RepartidorDetalle>(() => {
  const repartidor = db.repartidores.find((r) => r.id === repartidor_id)
  if (!repartidor) throw new Error('El repartidor no existe.')
  if (!datos.nombre.trim()) throw new Error('Escribe el nombre del repartidor.')
  if (datos.tipo_vehiculo !== 'bicicleta' && !datos.placas.trim()) throw new Error('Las placas son obligatorias para moto y carro.')
  const usuario = buscarUsuario(repartidor.usuario_id)
  if (usuario) Object.assign(usuario, { nombre: datos.nombre.trim(), telefono: datos.telefono.trim() || null })
  Object.assign(repartidor, { numero_empleado: datos.numero_empleado.trim() || repartidor.numero_empleado, tipo_vehiculo: datos.tipo_vehiculo, placas: datos.tipo_vehiculo === 'bicicleta' ? null : datos.placas.trim(), zona_base: datos.zona_base.trim() || repartidor.zona_base })
  auditar('actualizar', 'repartidor', repartidor_id, `Editó al repartidor ${datos.nombre.trim()}`)
  return conNombre(repartidor)
})

export const cambiarEstadoRepartidor = (repartidor_id: string, activo: boolean) => llamar<RepartidorDetalle>(() => {
  const repartidor = db.repartidores.find((r) => r.id === repartidor_id)
  if (!repartidor) throw new Error('El repartidor no existe.')
  repartidor.activo = activo
  if (!activo) repartidor.estado = 'no_disponible'
  else if (repartidor.estado === 'no_disponible') repartidor.estado = 'libre'
  auditar(activo ? 'activar' : 'desactivar', 'repartidor', repartidor_id, `${activo ? 'Reactivó' : 'Desactivó'} al repartidor ${nombreDe(repartidor.usuario_id)}`)
  return conNombre(repartidor)
})

/** Cuántas entregas cabe en cada tipo de vehículo; las rutas nuevas usan estos límites. */
export const guardarCapacidadesVehiculo = (capacidades: Record<TipoVehiculo, number>) => llamar<Record<TipoVehiculo, number>>(() => {
  for (const tipo of Object.keys(capacidades) as TipoVehiculo[]) {
    if (!Number.isInteger(capacidades[tipo]) || capacidades[tipo] < 1) throw new Error('Cada límite debe ser un número entero mayor a cero.')
  }
  Object.assign(db.capacidades_vehiculo, capacidades)
  auditar('actualizar', 'repartidor', 'capacidades', 'Cambió los límites de entregas por vehículo')
  return db.capacidades_vehiculo
})

const conParadas = (ruta: Ruta): RutaDetalle => ({
  ...ruta,
  repartidor_nombre: nombreDe(db.repartidores.find((r) => r.id === ruta.repartidor_id)!.usuario_id),
  paradas: db.paradas.filter((p) => p.ruta_id === ruta.id).sort((a, b) => a.orden - b.orden),
})

export const listarRutas = (fecha: string) => llamar<RutaDetalle[]>(() => db.rutas.filter((r) => r.fecha === fecha).map(conParadas))

/** Genera (y, por ahora, solo recalcula) las rutas del día; tarda más que el resto porque en el backend es un cálculo pesado. */
export const generarRutas = (fecha: string) => llamar<RutaDetalle[]>(() => {
  const rutas = db.rutas.filter((r) => r.fecha === fecha)
  rutas.forEach((r) => { r.estado = 'borrador' })
  auditar('generar_rutas', 'ruta', fecha, `Generó las rutas del ${fecha}`)
  return rutas.map(conParadas)
}, 2000)

export const aprobarRutas = (fecha: string) => llamar<number>(() => {
  const rutas = db.rutas.filter((r) => r.fecha === fecha)
  rutas.forEach((r) => { r.estado = 'aprobada' })
  auditar('aprobar_rutas', 'ruta', fecha, `Aprobó las rutas del ${fecha}`)
  return rutas.length
})

// ---------------------------------------------------------------- pedidos y soporte

export const listarPedidos = () => llamar<PedidoDetalle[]>(() =>
  db.pedidos.map((p) => ({ ...p, cliente_nombre: nombreDe(p.cliente_id), plan_nombre: db.planes.find((x) => x.id === p.plan_id)?.nombre ?? p.plan_id })),
)

export const listarTickets = () => llamar<TicketResumen[]>(() => db.tickets.map((t) => ({ ...t, cliente_nombre: nombreDe(t.cliente_id) })))

export const obtenerTicket = (ticket_id: string) => llamar<TicketDetalle>(() => {
  const ticket = db.tickets.find((t) => t.id === ticket_id)
  if (!ticket) throw new Error('El ticket no existe.')
  return {
    ...ticket,
    cliente_nombre: nombreDe(ticket.cliente_id),
    mensajes: db.mensajes_ticket.filter((m) => m.ticket_id === ticket_id).sort((a, b) => a.enviado_en.localeCompare(b.enviado_en)),
    resumen_pedido: db.resumen_pedido_ticket,
  }
})

export const enviarMensajeTicket = (ticket_id: string, texto: string) => llamar<MensajeTicket>(() => {
  const ticket = db.tickets.find((t) => t.id === ticket_id)
  if (!ticket) throw new Error('El ticket no existe.')
  const mensaje: MensajeTicket = { id: `MSG-${Date.now()}`, ticket_id, autor: 'agente', texto: texto.trim(), enviado_en: ahora() }
  db.mensajes_ticket.push(mensaje)
  ticket.actualizado_en = mensaje.enviado_en
  ticket.mensajes_sin_leer = 0
  return mensaje
})

// ---------------------------------------------------------------- portal del empleado

export const obtenerMiMenu = (empleado_id: string) => llamar<MiMenu>(() => {
  const empleado = db.empleados.find((e) => e.id === empleado_id)
  if (!empleado) throw new Error('El empleado no existe.')
  const empresa = db.empresas.find((e) => e.id === empleado.empresa_id)!
  const contrato = contratoEmpresaDe(empresa.id)
  const fecha = proximoDiaDeServicio()
  const cerradaPorCorte = !!corteCerrado(fecha)
  const cerradaPorEmpresa = empresaCerrada(empresa.id, fecha)
  const opciones: OpcionMenu[] = db.disponibilidad_dia
    .filter((d) => d.fecha === fecha)
    .flatMap((d) => {
      const platillo = db.platillos.find((p) => p.id === d.platillo_id)
      if (!platillo || platillo.estado_revision !== 'publicado' || !platillo.activo) return []
      const restantes = Math.max(0, d.porciones_maximas - d.porciones_tomadas)
      return [{ platillo: conComedor(platillo), porciones_maximas: d.porciones_maximas, porciones_tomadas: d.porciones_tomadas, porciones_restantes: restantes, agotado: restantes === 0 }]
    })
  const eleccion = db.elecciones_empleado.find((e) => e.empleado_id === empleado_id && e.fecha === fecha)
  const empresa_cubre = Math.round((contrato.precio_por_platillo * contrato.porcentaje_subsidio) / 100)
  return {
    fecha, hora_corte: db.configuracion_operacion.hora_corte, cerrado: cerradaPorCorte || cerradaPorEmpresa, motivo_cerrado: cerradaPorEmpresa ? 'empresa_cerrada' : cerradaPorCorte ? 'corte' : null,
    empleado: { id: empleado.id, nombre: nombreDe(empleado.usuario_id), empresa_nombre: empresa.nombre, activo: empleado.activo }, hora_entrega: contrato.hora_entrega,
    precio: contrato.precio_por_platillo, porcentaje_subsidio: contrato.porcentaje_subsidio, empresa_cubre, empleado_paga: contrato.precio_por_platillo - empresa_cubre, opciones,
    eleccion: eleccion ? { platillo_id: eleccion.platillo_id, platillo_nombre: db.platillos.find((p) => p.id === eleccion.platillo_id)?.nombre ?? eleccion.platillo_id, origen: eleccion.origen } : null,
  }
})

export const listarMisElecciones = (empleado_id: string, limite = 8) => llamar<MiEleccion[]>(() =>
  db.elecciones_empleado
    .filter((e) => e.empleado_id === empleado_id)
    .sort((a, b) => b.fecha.localeCompare(a.fecha))
    .slice(0, limite)
    .map((e) => {
      const empresa_cubre = Math.round((e.precio_congelado * e.porcentaje_subsidio_congelado) / 100)
      return { id: e.id, fecha: e.fecha, platillo_nombre: db.platillos.find((p) => p.id === e.platillo_id)?.nombre ?? e.platillo_id, precio: e.precio_congelado, empresa_cubre, empleado_paga: e.precio_congelado - empresa_cubre, origen: e.origen }
    }),
)

// ---------------------------------------------------------------- consumo diario y clientes

/** Platillos consumidos por día en un mes, para la gráfica del detalle de la empresa. */
export const consumoDiarioEmpresa = (empresa_id: string, mes: string) => llamar<ConsumoDiario[]>(() => {
  const ids = new Set(empleadosDe(empresa_id).map((e) => e.id))
  const porDia = new Map<string, number>()
  for (const e of db.elecciones_empleado) if (e.fecha.startsWith(mes) && ids.has(e.empleado_id)) porDia.set(e.fecha, (porDia.get(e.fecha) ?? 0) + 1)
  return [...porDia].map(([fecha, platillos]) => ({ fecha, platillos })).sort((a, b) => a.fecha.localeCompare(b.fecha))
})

const resumenCliente = (u: Usuario): ClienteResumen => {
  const pedidos = db.pedidos.filter((p) => p.cliente_id === u.id)
  const vigentes = pedidos.filter((p) => p.estado !== 'cancelado')
  return {
    usuario_id: u.id, nombre: u.nombre, correo: u.correo, activo: u.activo, pedidos_total: pedidos.length,
    pedidos_activos: pedidos.filter((p) => p.estado === 'activo' || p.estado === 'recibido' || p.estado === 'pendiente_pago').length,
    gasto_total: vigentes.reduce((suma, p) => suma + p.total, 0), ultimo_pedido: pedidos.map((p) => p.fecha_inicio).sort().at(-1) ?? null,
    tickets_abiertos: db.tickets.filter((t) => t.cliente_id === u.id && (t.estado === 'abierto' || t.estado === 'en_proceso')).length,
  }
}

export const listarClientes = () => llamar<ClienteResumen[]>(() => db.usuarios.filter((u) => u.rol === 'cliente').map(resumenCliente).sort((a, b) => b.gasto_total - a.gasto_total))

export const obtenerCliente = (usuario_id: string) => llamar<ClienteDetalle>(() => {
  const usuario = db.usuarios.find((u) => u.id === usuario_id && u.rol === 'cliente')
  if (!usuario) throw new Error('El cliente no existe.')
  return {
    ...resumenCliente(usuario),
    pedidos: db.pedidos.filter((p) => p.cliente_id === usuario_id).map((p) => ({ ...p, cliente_nombre: usuario.nombre, plan_nombre: db.planes.find((x) => x.id === p.plan_id)?.nombre ?? p.plan_id })),
    tickets: db.tickets.filter((t) => t.cliente_id === usuario_id),
  }
})

// ---------------------------------------------------------------- sitio público

/** Formulario de contacto de la página de inicio. Valida igual que la pantalla para no depender solo del navegador. */
export const enviarMensajeContacto = (datos: NuevoMensajeContacto) => llamar<MensajeContacto>(() => {
  if (datos.nombre.trim().length < 2) throw new Error('Escribe tu nombre.')
  if (!correoValido(datos.correo)) throw new Error('Escribe un correo válido.')
  if (datos.mensaje.trim().length < 10) throw new Error('Cuéntanos un poco más en tu mensaje (mínimo 10 caracteres).')
  const registro: MensajeContacto = { id: `CON-${Date.now().toString(36)}`, nombre: datos.nombre.trim(), correo: datos.correo.trim().toLowerCase(), empresa: datos.empresa.trim() || null, mensaje: datos.mensaje.trim(), enviado_en: ahora() }
  db.mensajes_contacto.push(registro)
  return registro
})
