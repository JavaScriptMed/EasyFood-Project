'use client'

import { useRouter } from 'next/navigation'
import { useEffect, useState } from 'react'
import { API_URL } from './config'
import { PORTALES, rutaCuenta } from './portales'
import type { Portal, Rol } from './types'

// Sesión: vive en sessionStorage (se borra al cerrar la pestaña) y caduca tras 30 min sin actividad.
// En modo real guarda además el token Bearer de Sanctum que recibe del login; si la API responde 401 (token revocado o
// vencido) la sesión se marca como expirada y el portal manda a «Tu sesión expiró».
const CLAVE = 'easylunch-sesion'
const INACTIVIDAD_MS = 30 * 60_000

export type EstadoSesion = { estado: 'ninguna' } | { estado: 'expirada'; rol: Rol } | { estado: 'activa'; rol: Rol }

export type UsuarioSesion = { id: string; nombre: string; correo: string }
type SesionGuardada = { rol: Rol; expira: number; token?: string; usuario?: UsuarioSesion }

function leerCrudo(): SesionGuardada | null {
  try {
    const crudo = window.sessionStorage.getItem(CLAVE)
    return crudo ? (JSON.parse(crudo) as SesionGuardada) : null
  } catch {
    return null
  }
}

/** Guarda la sesión. En modo real recibe el token y los datos de quien entró; en la maqueta solo el rol. */
export function iniciarSesion(rol: Rol, datos?: { token: string; usuario: UsuarioSesion }) {
  const previa = leerCrudo()
  const sesion: SesionGuardada = { rol, expira: Date.now() + INACTIVIDAD_MS, token: datos?.token ?? previa?.token, usuario: datos?.usuario ?? previa?.usuario }
  try { window.sessionStorage.setItem(CLAVE, JSON.stringify(sesion)) } catch {}
}

/** Token Bearer de la sesión actual (null si no hay o es la maqueta). */
export function leerToken(): string | null {
  return leerCrudo()?.token ?? null
}

export function leerUsuario(): UsuarioSesion | null {
  return leerCrudo()?.usuario ?? null
}

/** La API dijo 401: el token ya no sirve. Marca la sesión como expirada para que el portal lo muestre. */
export function marcarSesionExpirada(): Rol | null {
  const sesion = leerCrudo()
  if (!sesion) return null
  try { window.sessionStorage.setItem(CLAVE, JSON.stringify({ ...sesion, expira: 0 })) } catch {}
  return sesion.rol
}

export function leerSesion(): EstadoSesion {
  const sesion = leerCrudo()
  if (!sesion) return { estado: 'ninguna' }
  return Date.now() > sesion.expira ? { estado: 'expirada', rol: sesion.rol } : { estado: 'activa', rol: sesion.rol }
}

export function renovarSesion() {
  const sesion = leerSesion()
  if (sesion.estado === 'activa') iniciarSesion(sesion.rol)
}

export function cerrarSesion() {
  // Se avisa al backend para revocar el token (sin esperar la respuesta: la pantalla no debe quedarse esperando).
  const token = leerToken()
  if (token) {
    try { void fetch(`${API_URL}/api/auth/logout`, { method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` }, keepalive: true }).catch(() => {}) } catch {}
  }
  try { window.sessionStorage.removeItem(CLAVE) } catch {}
}

/**
 * Protege un portal. Devuelve true cuando hay sesión vigente con un rol permitido; si no, redirige:
 * sin sesión al login, sesión caducada a "Tu sesión expiró" y rol equivocado a "Sin permiso".
 */
export function useSesionPortal(portal: Portal, proteger = true) {
  const router = useRouter()
  const [permitido, setPermitido] = useState(false)

  useEffect(() => {
    if (!proteger) return
    const revisar = () => {
      const sesion = leerSesion()
      if (sesion.estado === 'ninguna') { setPermitido(false); router.replace(PORTALES[portal].login) }
      else if (sesion.estado === 'expirada') { setPermitido(false); router.replace(rutaCuenta(portal, 'sesion-expirada')) }
      else if (!PORTALES[portal].roles.includes(sesion.rol)) { setPermitido(false); router.replace(rutaCuenta(portal, 'sin-permiso')) }
      else setPermitido(true)
    }
    renovarSesion()
    revisar()
    const intervalo = window.setInterval(revisar, 15_000)
    const actividad = () => renovarSesion()
    window.addEventListener('pointerdown', actividad)
    window.addEventListener('keydown', actividad)
    return () => { window.clearInterval(intervalo); window.removeEventListener('pointerdown', actividad); window.removeEventListener('keydown', actividad) }
  }, [portal, proteger, router])

  return proteger ? permitido : true
}
