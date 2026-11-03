export type RequisitoContrasena = { texto: string; ok: boolean }

export const etiquetasSeguridad = ['Muy débil', 'Débil', 'Aceptable', 'Buena', 'Excelente'] as const

/** Evalúa una contraseña: puntaje 0 a 4 para el medidor y la lista de requisitos. */
export function evaluarContrasena(clave: string) {
  const requisitos: RequisitoContrasena[] = [
    { texto: 'Al menos 8 caracteres', ok: clave.length >= 8 },
    { texto: 'Mayúsculas y minúsculas', ok: /[a-záéíóúñ]/.test(clave) && /[A-ZÁÉÍÓÚÑ]/.test(clave) },
    { texto: 'Al menos un número', ok: /\d/.test(clave) },
    { texto: 'Un símbolo o 12 caracteres o más', ok: /[^A-Za-z0-9áéíóúñÁÉÍÓÚÑ]/.test(clave) || clave.length >= 12 },
  ]
  const cumplidos = requisitos.filter((r) => r.ok).length
  const puntaje = clave.length === 0 ? 0 : clave.length < 8 ? Math.min(cumplidos, 1) : Math.max(1, cumplidos)
  return { puntaje, etiqueta: etiquetasSeguridad[puntaje], requisitos, valida: clave.length >= 8 && /[A-Za-zÁÉÍÓÚáéíóúñÑ]/.test(clave) && /\d/.test(clave) }
}

/** Mensaje de error si la contraseña no cumple el mínimo; null si es válida. */
export function errorDeContrasena(clave: string) {
  if (clave.length < 8) return 'La contraseña debe tener al menos 8 caracteres.'
  if (!/[A-Za-zÁÉÍÓÚáéíóúñÑ]/.test(clave) || !/\d/.test(clave)) return 'La contraseña debe incluir letras y números.'
  return null
}
