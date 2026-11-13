// Configuración del front y constantes de la maqueta.
//
// Modo real (por defecto): el front habla con el backend en NEXT_PUBLIC_API_URL y las fechas salen del reloj.
// Modo maqueta: NEXT_PUBLIC_USAR_MOCK=true usa /lib/api-mock.ts y /lib/mock-data.ts, sin backend (útil para diseñar).

/** Backend de EasyLunch (Laravel). Sin barra final. */
export const API_URL = (process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000').replace(/\/$/, '')

/** true = pantallas con datos de ejemplo y sin backend. */
export const USAR_MOCK = process.env.NEXT_PUBLIC_USAR_MOCK === 'true'

const dosDigitos = (n: number) => String(n).padStart(2, '0')
const aIso = (d: Date) => `${d.getFullYear()}-${dosDigitos(d.getMonth() + 1)}-${dosDigitos(d.getDate())}`
/** Los últimos `n` meses (AAAA-MM), del actual hacia atrás. */
const ultimosMeses = (n: number) => Array.from({ length: n }, (_, i) => { const d = new Date(); d.setDate(1); d.setMonth(d.getMonth() - i); return aIso(d).slice(0, 7) })

export const MES_ACTUAL = USAR_MOCK ? '2026-10' : ultimosMeses(1)[0]
export const MESES = USAR_MOCK ? ['2026-10', '2026-09', '2026-08', '2026-07'] : ultimosMeses(4)
export const FECHA_MANANA = '2026-11-02' // solo la maqueta: próximo día de entrega (lunes)
export const HORA_CORTE = 18

export const USUARIO_ACTUAL_ID = 'USR-ADM-01'
// En modo real el backend identifica la empresa, el comedor o el empleado por el token de la sesión: estos ids no se envían.
export const EMPRESA_PORTAL_ID = USAR_MOCK ? 'EMP-001' : 'mi-empresa'
export const COMEDOR_PORTAL_ID = USAR_MOCK ? 'COM-001' : 'mi-comedor'

export const RETARDO_API_MS = 300

export const AVISO_FACTURACION = 'EasyLunch factura una sola vez a la empresa. El cobro a cada empleado lo gestiona la empresa.'
export const AVISO_COBRO_EMPLEADO = 'La empresa decide cómo cobrar la parte del empleado (nómina u otro medio)'

/** "Hoy": en la maqueta una fecha fija; en modo real, el día de tu computadora. */
export const FECHA_HOY = USAR_MOCK ? '2026-10-30' : aIso(new Date())
/** "Ahora" para los tiempos relativos del panel (hace 4 min, ayer…): en la maqueta una hora fija; en modo real, el momento en que se abre la página. */
export const AHORA_MOCK = USAR_MOCK ? '2026-11-16T12:48:00' : `${aIso(new Date())}T${dosDigitos(new Date().getHours())}:${dosDigitos(new Date().getMinutes())}:00`

export const VIGENCIA_INVITACION_DIAS = 7
export const VIGENCIA_ENLACE_RESTABLECER_MIN = 60

export const EMPLEADO_PORTAL_ID = USAR_MOCK ? 'EMP-001-E004' : 'mi-empleado'
/**
 * Día de operación que muestran Pedidos, Rutas y Repartidores. En la maqueta una fecha fija; en modo real, hoy
 * (o el siguiente día de servicio si ya pasó la hora de corte y las rutas que se acaban de armar son de mañana).
 */
function fechaDeOperacion() {
  const ahora = new Date()
  const dia = new Date(ahora.getFullYear(), ahora.getMonth(), ahora.getDate() + (ahora.getHours() >= HORA_CORTE ? 1 : 0))
  while (dia.getDay() === 0) dia.setDate(dia.getDate() + 1) // el domingo no hay servicio
  return aIso(dia)
}
export const FECHA_OPERACION = USAR_MOCK ? '2026-11-16' : fechaDeOperacion()
