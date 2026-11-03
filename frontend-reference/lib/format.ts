const mxn = new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 0 })
export const formatMXN = (n: number) => mxn.format(n)

const dateFormat = new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })
export const formatDate = (iso: string) => dateFormat.format(new Date(`${iso}T00:00:00Z`)).replace('.', '')

const MESES_LARGOS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']
const MESES_CORTOS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic']
const DIAS_LARGOS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado']
const DIAS_CORTOS = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb']

const partes = (fecha: string) => {
  const [y, m, d] = fecha.slice(0, 10).split('-').map(Number)
  return { y, m: m - 1, d, dia: new Date(Date.UTC(y, m - 1, d)).getUTCDay() }
}

/** "2026-10" → "Octubre 2026" */
export const etiquetaMes = (mes: string) => {
  const { y, m } = partes(`${mes}-01`)
  return `${MESES_LARGOS[m][0].toUpperCase()}${MESES_LARGOS[m].slice(1)} ${y}`
}

/** "2026-10-19" → "lunes 19 de octubre" */
export const formatFechaLarga = (fecha: string) => {
  const { m, d, dia } = partes(fecha)
  return `${DIAS_LARGOS[dia]} ${d} de ${MESES_LARGOS[m]}`
}

/** "2026-11-16" → "Lun 16 nov" */
export const etiquetaDia = (fecha: string) => {
  const { m, d, dia } = partes(fecha)
  return `${DIAS_CORTOS[dia]} ${d} ${MESES_CORTOS[m]}`
}

/** "2026-10-19" → "Lun 19" */
export const etiquetaDiaCorta = (fecha: string) => {
  const { d, dia } = partes(fecha)
  return `${DIAS_CORTOS[dia]} ${d}`
}

/** ("2026-11-16", "2026-11-21") → "16–21 nov" */
export const formatRangoFechas = (inicio: string, fin: string) => {
  const a = partes(inicio)
  const b = partes(fin)
  if (inicio === fin) return `${a.d} ${MESES_CORTOS[a.m]}`
  return a.m === b.m ? `${a.d}–${b.d} ${MESES_CORTOS[b.m]}` : `${a.d} ${MESES_CORTOS[a.m]}–${b.d} ${MESES_CORTOS[b.m]}`
}

export const sumarDias = (fecha: string, dias: number) => {
  const { y, m, d } = partes(fecha)
  return new Date(Date.UTC(y, m, d + dias)).toISOString().slice(0, 10)
}

export const mesesEntre = (inicio: string, fin: string) => {
  const a = partes(inicio)
  const b = partes(fin)
  return Math.max(1, (b.y - a.y) * 12 + (b.m - a.m) + 1)
}

export const iniciales = (nombre: string, max = 2) => nombre.split(' ').map((p) => p[0]).join('').slice(0, max)

export const horaDe = (iso: string) => iso.slice(11, 16)

/** "hace 4 min", "hace 2 h" o "ayer, 18:42" respecto a `ahora` (por defecto, el momento actual). */
export function tiempoRelativo(iso: string, ahora: string | Date = new Date()) {
  const ref = typeof ahora === 'string' ? new Date(ahora) : ahora
  const minutos = Math.max(0, Math.round((ref.getTime() - new Date(iso).getTime()) / 60_000))
  if (minutos < 60) return `hace ${Math.max(1, minutos)} min`
  if (minutos < 24 * 60 && new Date(iso).getDate() === ref.getDate()) return `hace ${Math.floor(minutos / 60)} h`
  return `ayer, ${horaDe(iso)}`
}

/** "18:00" → "6:00 p. m." */
export const formatHora12 = (hora: string) => {
  const [h, m] = hora.split(':').map(Number)
  return Number.isNaN(h) ? '' : `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'a. m.' : 'p. m.'}`
}

export const diasEntre = (desde: string, hasta: string) => Math.round((Date.parse(`${hasta}T00:00:00Z`) - Date.parse(`${desde}T00:00:00Z`)) / 86_400_000)

/** Suma meses conservando el día; si el mes destino es más corto, usa su último día. */
export const sumarMeses = (fecha: string, meses: number) => {
  const { y, m, d } = partes(fecha)
  const ultimo = new Date(Date.UTC(y, m + meses + 1, 0)).getUTCDate()
  return new Date(Date.UTC(y, m + meses, Math.min(d, ultimo))).toISOString().slice(0, 10)
}
