'use client'

import { useRouter } from 'next/navigation'
import { useEffect, useState } from 'react'
import { PORTALES, rutaCuenta } from './portales'
import type { Portal, Rol } from './types'

// Sesión de la maqueta: vive en sessionStorage y caduca tras 30 min sin actividad.
// Cuando exista el backend, esto lo reemplaza el token real y la respuesta 401/403 de la API.
const CLAVE = 'easylunch-sesion'
const INACTIVIDAD_MS = 30 * 60_000

export type EstadoSesion = { estado: 'ninguna' } | { estado: 'expirada'; rol: Rol } | { estado: 'activa'; rol: Rol }

export function iniciarSesion(rol: Rol) {
  try { window.sessionStorage.setItem(CLAVE, JSON.stringify({ rol, expira: Date.now() + INACTIVIDAD_MS })) } catch {}
}

export function leerSesion(): EstadoSesion {
  try {
    const crudo = window.sessionStorage.getItem(CLAVE)
    if (!crudo) return { estado: 'ninguna' }
    const { rol, expira } = JSON.parse(crudo) as { rol: Rol; expira: number }
    return Date.now() > expira ? { estado: 'expirada', rol } : { estado: 'activa', rol }
  } catch {
    return { estado: 'ninguna' }
  }
}

export function renovarSesion() {
  const sesion = leerSesion()
  if (sesion.estado === 'activa') iniciarSesion(sesion.rol)
}

export function cerrarSesion() {
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
