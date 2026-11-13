// Cliente HTTP del backend de EasyLunch (Laravel, /api/v1). Es lo único que sabe de fetch, del token y de la forma de los errores.
// Los errores de la API v1 son { mensaje, codigo?, errores? }; el login y el perfil (ruta del equipo) usan { message }.

import { API_URL } from './config'
import { portalDeRol, rutaCuenta } from './portales'
import { leerToken, marcarSesionExpirada } from './sesion'

/** Error de la API: `mensaje` ya viene en español y se puede mostrar tal cual. */
export class ErrorApi extends Error {
  constructor(mensaje: string, public estado: number, public codigo?: string, public errores?: Record<string, string[]>) {
    super(mensaje)
    this.name = 'ErrorApi'
  }
}

type Metodo = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
type Opciones = {
  cuerpo?: unknown
  query?: Record<string, unknown>
  /** true = ruta pública (login, contacto): no manda token y un 401 no cierra la sesión. */
  publico?: boolean
}

function conQuery(ruta: string, query?: Record<string, unknown>) {
  if (!query) return ruta
  const partes: string[] = []
  for (const [clave, valor] of Object.entries(query)) {
    if (valor === undefined || valor === null || valor === '') continue
    if (Array.isArray(valor)) valor.forEach((v) => partes.push(`${encodeURIComponent(clave)}[]=${encodeURIComponent(String(v))}`))
    else partes.push(`${encodeURIComponent(clave)}=${encodeURIComponent(typeof valor === 'boolean' ? (valor ? '1' : '0') : String(valor))}`)
  }
  return partes.length ? `${ruta}?${partes.join('&')}` : ruta
}

function mensajeDe(estado: number, cuerpo: any): string {
  if (cuerpo?.errores && typeof cuerpo.errores === 'object') {
    const primero = Object.values(cuerpo.errores as Record<string, string[]>)[0]?.[0] // el resumen de Laravel dice «(and 4 more errors)»: mejor el primer error
    if (primero) return primero
  }
  if (cuerpo?.errors && typeof cuerpo.errors === 'object') {
    const primero = Object.values(cuerpo.errors as Record<string, string[]>)[0]?.[0]
    if (primero) return primero
  }
  if (typeof cuerpo?.mensaje === 'string') return cuerpo.mensaje
  if (typeof cuerpo?.message === 'string' && estado !== 500) return cuerpo.message
  if (estado === 501) return 'Esta función todavía no está disponible en el servidor.'
  if (estado >= 500) return 'El servidor tuvo un problema. Intenta de nuevo en unos minutos.'
  return `No se pudo completar la solicitud (${estado}).`
}

export async function http<T>(metodo: Metodo, ruta: string, opciones: Opciones = {}): Promise<T> {
  const encabezados: Record<string, string> = { Accept: 'application/json' }
  if (opciones.cuerpo !== undefined) encabezados['Content-Type'] = 'application/json'
  const token = opciones.publico ? null : leerToken()
  if (token) encabezados.Authorization = `Bearer ${token}`

  let respuesta: Response
  try {
    respuesta = await fetch(`${API_URL}${conQuery(ruta, opciones.query)}`, {
      method: metodo,
      headers: encabezados,
      body: opciones.cuerpo === undefined ? undefined : JSON.stringify(opciones.cuerpo),
    })
  } catch {
    throw new ErrorApi('No se pudo conectar con el servidor. Revisa que el backend esté encendido.', 0, 'sin_conexion')
  }

  const texto = await respuesta.text()
  let cuerpo: any = null
  try { cuerpo = texto ? JSON.parse(texto) : null } catch { /* respuesta que no es JSON */ }

  if (!respuesta.ok) {
    if (respuesta.status === 401 && !opciones.publico) {
      const rol = marcarSesionExpirada()
      const portal = rol ? portalDeRol(rol) : null
      if (portal && typeof window !== 'undefined') window.location.assign(rutaCuenta(portal, 'sesion-expirada'))
    }
    throw new ErrorApi(mensajeDe(respuesta.status, cuerpo), respuesta.status, cuerpo?.codigo, cuerpo?.errores ?? cuerpo?.errors)
  }
  return cuerpo as T
}

export const get = <T>(ruta: string, query?: Record<string, unknown>) => http<T>('GET', ruta, { query })
export const post = <T>(ruta: string, cuerpo?: unknown, opciones: Omit<Opciones, 'cuerpo'> = {}) => http<T>('POST', ruta, { cuerpo: cuerpo ?? {}, ...opciones })
export const put = <T>(ruta: string, cuerpo?: unknown) => http<T>('PUT', ruta, { cuerpo: cuerpo ?? {} })
export const patch = <T>(ruta: string, cuerpo?: unknown) => http<T>('PATCH', ruta, { cuerpo: cuerpo ?? {} })
export const borrar = <T>(ruta: string, cuerpo?: unknown) => http<T>('DELETE', ruta, { cuerpo })
