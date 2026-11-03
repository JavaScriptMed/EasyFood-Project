// Cálculo de consumo y facturas a partir de las elecciones de los empleados.
// Es una función pura sobre arreglos para que la usen tanto los datos de ejemplo como /lib/api.ts;
// en producción este cálculo vive en el backend.

import type { ContratoEmpresa, EleccionEmpleado, Empleado, FacturaMensual, LineaConsumoEmpleado, Usuario } from './types'

export function calcularConsumo(
  empleados: Empleado[],
  buscarUsuario: (id: string) => Usuario | undefined,
  elecciones: EleccionEmpleado[],
  mes: string,
): LineaConsumoEmpleado[] {
  const ids = new Set(empleados.map((e) => e.id))
  const porEmpleado = new Map<string, EleccionEmpleado[]>()
  for (const eleccion of elecciones) {
    if (!eleccion.fecha.startsWith(mes) || !ids.has(eleccion.empleado_id)) continue
    const lista = porEmpleado.get(eleccion.empleado_id)
    if (lista) lista.push(eleccion)
    else porEmpleado.set(eleccion.empleado_id, [eleccion])
  }
  return empleados.flatMap((empleado) => {
    const filas = porEmpleado.get(empleado.id)
    if (!filas) return []
    const usuario = buscarUsuario(empleado.usuario_id)
    const importe = filas.reduce((suma, f) => suma + f.precio_congelado, 0)
    const parte_empresa = filas.reduce((suma, f) => suma + Math.round((f.precio_congelado * f.porcentaje_subsidio_congelado) / 100), 0)
    return [{ empleado_id: empleado.id, nombre: usuario?.nombre ?? empleado.id, correo: usuario?.correo ?? '', platillos: filas.length, importe, parte_empresa, parte_empleado: importe - parte_empresa }]
  })
}

/**
 * EasyLunch factura todo a la empresa: lo que consumieron los empleados (parte de la empresa + parte de
 * los empleados) más los platillos que faltaron para el mínimo, cobrados a precio completo.
 */
export function calcularTotales(
  empresa_id: string,
  contrato: ContratoEmpresa,
  lineas: LineaConsumoEmpleado[],
  mes: string,
  folio: string,
): Omit<FacturaMensual, 'estado' | 'emitida_en' | 'pagada_en'> {
  const platillos = lineas.reduce((s, l) => s + l.platillos, 0)
  const parte_empresa = lineas.reduce((s, l) => s + l.parte_empresa, 0)
  const parte_empleados = lineas.reduce((s, l) => s + l.parte_empleado, 0)
  const platillos_faltantes = Math.max(0, contrato.compra_minima_mensual - platillos)
  const diferencia_minimo = platillos_faltantes * contrato.precio_por_platillo
  return { id: folio, empresa_id, mes, platillos, parte_empresa, parte_empleados, platillos_faltantes, diferencia_minimo, total: parte_empresa + parte_empleados + diferencia_minimo }
}

export const folioFactura = (mes: string, indiceEmpresa: number) => `EL-${mes.replace('-', '')}-${String(indiceEmpresa + 1).padStart(3, '0')}`
