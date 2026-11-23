// Datos de ejemplo de los indicadores del dashboard (modo maqueta: NEXT_PUBLIC_USAR_MOCK=true). Con el backend encendido salen de /api/v1.

import { FECHA_HOY } from './config'
import { sumarDias } from './format'
import * as db from './mock-data'
import type { CalificacionPendiente, CostoOperativo, FacturaSemaforo, IndicadoresAdmin, IndicadoresEmpresa } from './types'

const lunesDe = (fecha: string) => sumarDias(fecha, -((new Date(`${fecha}T00:00:00Z`).getUTCDay() + 6) % 7))
const LUNES = lunesDe(FECHA_HOY)
const SEMANAS = Array.from({ length: 6 }, (_, i) => sumarDias(LUNES, -7 * (5 - i)))
const redondear = (n: number) => Math.round(n * 100) / 100
const promedio = (a: number[]) => redondear(a.reduce((s, n) => s + n, 0) / a.length)

const PLATILLOS = [212, 224, 231, 219, 240, 158] // la última es la semana en curso (a medias)
const ENTREGA = [4.2, 4.3, 4.1, 4.4, 4.5, 4.4]
const MENU = [4.1, 4.2, 4.0, 4.3, 4.4, 4.5]
const QUEJAS = [4, 3, 5, 2, 2, 1]
const OPERATIVOS = [2360, 2450, 2540, 2360, 2630, 780]
const INGRESOS_CLIENTES = [1180, 1320, 1250, 1410, 1500, 980]

// Los costos que se registran en pantalla mueven el balance de la semana en curso.
const operativosDeLaSemanaEnCurso = () => costos_operativos.filter((c) => c.activo && c.fecha >= LUNES).reduce((s, c) => s + c.monto, 0)

const balanceSemana = (i: number) => {
  const ingresos_empresas = PLATILLOS[i] * 130
  const ingresos_clientes = INGRESOS_CLIENTES[i]
  const costo_maquila = redondear((PLATILLOS[i] + Math.round(INGRESOS_CLIENTES[i] / 49)) * 49)
  const costos_operativos = i === 5 ? operativosDeLaSemanaEnCurso() : OPERATIVOS[i]
  const ingresos = ingresos_empresas + ingresos_clientes
  const costos = redondear(costo_maquila + costos_operativos)
  return { semana_inicio: SEMANAS[i], ingresos_empresas, ingresos_clientes, ingresos, costo_maquila, costos_operativos, costos, utilidad: redondear(ingresos - costos) }
}

const semaforoDe = (i: number): FacturaSemaforo['semaforo'] => (i % 3 === 0 ? 'verde' : i % 3 === 1 ? 'rojo' : 'gris')
const facturasDeEjemplo = (): FacturaSemaforo[] => db.empresas.map((e, i) => {
  const semaforo = semaforoDe(i)
  const total = 18000 + i * 3250
  return {
    empresa_id: e.id, empresa: e.nombre, folio: semaforo === 'gris' ? null : `EL-202610-${String(i + 1).padStart(3, '0')}`, total: semaforo === 'gris' ? null : total,
    estado: semaforo === 'verde' ? 'pagada' : semaforo === 'rojo' ? 'emitida' : 'sin_generar', semaforo, emitida_en: semaforo === 'gris' ? null : '2026-10-02', pagada_en: semaforo === 'verde' ? '2026-10-09' : null,
  }
})

export function indicadoresAdmin(): IndicadoresAdmin {
  const porcentaje = db.configuracion_operacion.ahorro_apps_porcentaje
  const limite = db.configuracion_operacion.costo_maquila_limite
  const facturas = facturasDeEjemplo()
  const balance = PLATILLOS.map((_, i) => balanceSemana(i))
  const cocinas = db.comedores.map((c) => {
    const precio = db.contratos_comedor.find((x) => x.comedor_id === c.id)?.precio_por_platillo ?? 49
    return { comedor_id: c.id, comedor: c.nombre, precio_por_platillo: precio, en_limite: precio <= limite }
  })
  const porEmpresa = db.empresas.map((e, i) => ({ empresa_id: e.id, empresa: e.nombre, platillos: Math.max(6, Math.round(PLATILLOS[5] * (0.26 - i * 0.025))) })).sort((a, b) => b.platillos - a.platillos)
  const gastoPromedio = 130 * 16 // platillos del mes por empleado × precio
  const ahorroPromedio = redondear((gastoPromedio * porcentaje) / 100)
  const empleados = db.empleados.filter((e) => e.activo).length
  const nuevos = db.empresas.filter((e) => (db.contratos_empresa.find((c) => c.empresa_id === e.id)?.vigencia_inicio ?? '').slice(0, 7) === FECHA_HOY.slice(0, 7))

  return {
    periodo: { semana_inicio: LUNES, semana_fin: sumarDias(LUNES, 6), mes: FECHA_HOY.slice(0, 7), actualizado_en: `${FECHA_HOY}T10:00:00` },
    platillos_entregados: { semana: PLATILLOS[5], por_empresa: porEmpresa, serie: SEMANAS.map((s, i) => ({ semana_inicio: s, platillos: PLATILLOS[i] })) },
    calificacion_entrega: {
      promedio: ENTREGA[5], puntualidad: 4.2, trato_chofer: 4.7, empaque: 4.3, calificaciones: 42,
      serie: SEMANAS.map((s, i) => ({ semana_inicio: s, promedio: ENTREGA[i], puntualidad: ENTREGA[i] - 0.2, trato_chofer: ENTREGA[i] + 0.2, empaque: ENTREGA[i] - 0.1, calificaciones: 30 + i * 4 })),
    },
    calificacion_menu: {
      promedio: MENU[5], calificaciones: 118,
      por_comedor: db.comedores.map((c, i) => ({ comedor: c.nombre, promedio: redondear(MENU[5] + 0.2 - i * 0.4), calificaciones: 70 - i * 22 })),
      serie: SEMANAS.map((s, i) => ({ semana_inicio: s, promedio: MENU[i], calificaciones: 60 + i * 11 })),
    },
    paquete_popular: { mes: FECHA_HOY.slice(0, 7), paquete: { id: 'PLAN-7', nombre: 'Plan semanal', dias: 6, pedidos: 18 }, paquetes: [
      { id: 'PLAN-7', nombre: 'Plan semanal', dias: 6, pedidos: 18 }, { id: 'PLAN-2', nombre: 'Plan 2 días', dias: 2, pedidos: 11 }, { id: 'PLAN-1', nombre: 'Plan 1 día', dias: 1, pedidos: 7 },
    ] },
    ahorro_estimado: {
      mes: FECHA_HOY.slice(0, 7), porcentaje_sobreprecio: porcentaje, empleados, por_empleado_promedio: ahorroPromedio, ahorro_total: redondear(ahorroPromedio * empleados),
      por_empresa: porEmpresa.map((e) => ({ empresa_id: e.empresa_id, empresa: e.empresa, empleados: Math.round(e.platillos / 4), ahorro_promedio: ahorroPromedio, ahorro_total: redondear(ahorroPromedio * Math.round(e.platillos / 4)) })),
    },
    incidencias: { semana: { total: QUEJAS[5], abiertas: 1, por_tipo: [
      { tipo: 'comida_fria', etiqueta: 'Comida fría', cantidad: 1 }, { tipo: 'platillo_danado', etiqueta: 'Empaque dañado', cantidad: 0 }, { tipo: 'entrega_tardia', etiqueta: 'Entrega tardía', cantidad: 0 },
    ] }, serie: SEMANAS.map((s, i) => ({ semana_inicio: s, total: QUEJAS[i] })) },
    contratos_nuevos: {
      mes: FECHA_HOY.slice(0, 7), cantidad: nuevos.length, mes_anterior: 2,
      contratos: nuevos.map((e) => { const c = db.contratos_empresa.find((x) => x.empresa_id === e.id)!; return { empresa_id: e.id, empresa: e.nombre, vigencia_inicio: c.vigencia_inicio, vigencia_fin: c.vigencia_fin, meses: 6, compra_minima_mensual: c.compra_minima_mensual } }),
    },
    balance: { semana: balance[5], serie: balance },
    costo_maquila: {
      limite, platillos_semana: PLATILLOS[5] + 20, costo_maquila_semana: balance[5].costo_maquila, costo_promedio_semana: 49, en_limite: cocinas.every((c) => c.en_limite), cocinas,
    },
    facturacion_b2b: {
      mes: '2026-10', pagadas: facturas.filter((f) => f.semaforo === 'verde').length, pendientes: facturas.filter((f) => f.semaforo === 'rojo').length, sin_emitir: facturas.filter((f) => f.semaforo === 'gris').length,
      monto_cobrado: facturas.filter((f) => f.semaforo === 'verde').reduce((s, f) => s + (f.total ?? 0), 0), monto_pendiente: facturas.filter((f) => f.semaforo === 'rojo').reduce((s, f) => s + (f.total ?? 0), 0), facturas,
    },
  }
}

export function indicadoresEmpresa(empresa_id: string): IndicadoresEmpresa {
  const porcentaje = db.configuracion_operacion.ahorro_apps_porcentaje
  const empleados = db.empleados.filter((e) => e.empresa_id === empresa_id && e.activo)
  const ahorroPorPlatillo = redondear((130 * porcentaje) / 100)
  const platillosDelMes = 16
  const diasDeLaSemana = [0, 1, 2, 3, 4].map((d) => ({ fecha: sumarDias(LUNES, d), platillos: Math.max(0, empleados.length - (d === 1 ? 2 : 0)) })).filter((d) => d.fecha <= FECHA_HOY)
  const facturas = facturasDeEjemplo().filter((f) => f.empresa_id === empresa_id)
  return {
    periodo: { semana_inicio: LUNES, semana_fin: sumarDias(LUNES, 6), mes: FECHA_HOY.slice(0, 7), actualizado_en: `${FECHA_HOY}T10:00:00` },
    platillos_entregados: { semana: diasDeLaSemana.reduce((s, d) => s + d.platillos, 0), por_dia: diasDeLaSemana, serie: SEMANAS.map((s, i) => ({ semana_inicio: s, platillos: Math.round((PLATILLOS[i] * empleados.length) / 300) })) },
    calificacion_entrega: { promedio: 4.4, puntualidad: 4.2, trato_chofer: 4.7, empaque: 4.3, calificaciones: 9 },
    calificacion_menu: { promedio: MENU[5], calificaciones: 21, serie: SEMANAS.map((s, i) => ({ semana_inicio: s, promedio: MENU[i], calificaciones: 12 + i })) },
    ahorro_estimado: {
      mes: FECHA_HOY.slice(0, 7), porcentaje_sobreprecio: porcentaje, empleados: empleados.length, por_empleado_promedio: redondear(ahorroPorPlatillo * platillosDelMes), ahorro_total: redondear(ahorroPorPlatillo * platillosDelMes * empleados.length),
      por_empleado: empleados.slice(0, 12).map((e, i) => ({ empleado_id: e.id, empleado: db.usuarios.find((u) => u.id === e.usuario_id)?.nombre ?? e.id, platillos: platillosDelMes - (i % 4), ahorro: redondear(ahorroPorPlatillo * (platillosDelMes - (i % 4))) })),
    },
    incidencias: { semana: { total: 1, abiertas: 1, por_tipo: [
      { tipo: 'comida_fria', etiqueta: 'Comida fría', cantidad: 1 }, { tipo: 'platillo_danado', etiqueta: 'Empaque dañado', cantidad: 0 }, { tipo: 'entrega_tardia', etiqueta: 'Entrega tardía', cantidad: 0 },
    ] }, serie: SEMANAS.map((s, i) => ({ semana_inicio: s, total: Math.min(QUEJAS[i], 2) })) },
    facturacion: { pagadas: facturas.filter((f) => f.semaforo === 'verde').length, pendientes: facturas.filter((f) => f.semaforo === 'rojo').length, monto_pendiente: facturas.filter((f) => f.semaforo === 'rojo').reduce((s, f) => s + (f.total ?? 0), 0), facturas },
  }
}

export const costos_operativos: CostoOperativo[] = [
  { id: 'COS-001', fecha: sumarDias(LUNES, 1), categoria: 'reparto', descripcion: 'Combustible y mantenimiento de reparto', monto: 540, registrado_por: 'Admin', activo: true },
  { id: 'COS-002', fecha: sumarDias(LUNES, -6), categoria: 'reparto', descripcion: 'Combustible y mantenimiento de reparto', monto: 1800, registrado_por: 'Admin', activo: true },
  { id: 'COS-003', fecha: sumarDias(LUNES, -6), categoria: 'empaque', descripcion: 'Charolas y bolsas', monto: 560, registrado_por: 'Admin', activo: true },
  { id: 'COS-004', fecha: sumarDias(LUNES, -13), categoria: 'nomina', descripcion: 'Coordinación de operación', monto: 2400, registrado_por: 'Admin', activo: true },
  { id: 'COS-005', fecha: sumarDias(LUNES, -13), categoria: 'otro', descripcion: 'Capturado por error', monto: 999, registrado_por: 'Admin', activo: false },
]

export const calificaciones_pendientes: CalificacionPendiente[] = [
  { fecha: sumarDias(FECHA_HOY, -1), entrega_calificada: false, platillos: [{ tiempo_comida: 'comida', platillo_id: db.platillos[0].id, platillo_nombre: db.platillos[0].nombre, calificado: false }] },
  { fecha: sumarDias(FECHA_HOY, -2), entrega_calificada: true, platillos: [{ tiempo_comida: 'comida', platillo_id: db.platillos[1].id, platillo_nombre: db.platillos[1].nombre, calificado: false }] },
]
