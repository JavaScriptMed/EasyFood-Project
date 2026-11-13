// Inicio de sesión de los portales. En modo real valida contra el backend (POST /api/auth/login) y guarda el token;
// en modo maqueta conserva las reglas de ejemplo de antes.

import { API_URL, USAR_MOCK } from './config'
import { ErrorApi, post } from './http'
import { PORTALES } from './portales'
import { iniciarSesion } from './sesion'
import type { Portal, Rol } from './types'

export type ResultadoLogin =
  | { estado: 'ok'; rol: Rol; destino: string }
  | { estado: 'credenciales' | 'bloqueada' | 'otro_portal' | 'conexion' | 'error'; mensaje: string }

/** Nombres de rol del backend → los del front. */
const ROLES: Record<string, Rol> = {
  admin: 'administrador', support: 'soporte', provider: 'comedor', company: 'empresa', employee: 'empleado', customer: 'cliente', driver: 'repartidor',
}

type RespuestaLogin = { token: string; user: { id: number | string; name: string; email: string; role: string; status: string } }

/** Reglas de la maqueta (sin backend): el correo decide el rol. */
function loginMock(portal: Portal, correo: string): ResultadoLogin {
  const c = correo.toLowerCase()
  if (portal === 'admin' && c.includes('bloqueado')) return { estado: 'bloqueada', mensaje: 'Esta cuenta está bloqueada temporalmente. Contacta a soporte.' }
  const rol: Rol | null = portal === 'admin' ? (c.includes('admin') ? 'administrador' : c.includes('soporte') ? 'soporte' : null)
    : portal === 'comedores' ? (c.includes('comedor') ? 'comedor' : null)
    : portal === 'empresas' ? (/empresa|rrhh/.test(c) ? 'empresa' : null)
    : /empleado/.test(c) ? 'empleado' : null
  if (!rol) return { estado: 'credenciales', mensaje: 'No pudimos validar tus datos. Revisa tu correo y contraseña.' }
  iniciarSesion(rol)
  return { estado: 'ok', rol, destino: PORTALES[portal].inicio }
}

export async function iniciarSesionEnPortal(portal: Portal, correo: string, contrasena: string): Promise<ResultadoLogin> {
  if (USAR_MOCK) {
    await new Promise((r) => setTimeout(r, 450))
    return loginMock(portal, correo)
  }
  try {
    const { token, user } = await post<RespuestaLogin>('/api/auth/login', { email: correo.trim(), password: contrasena }, { publico: true })
    const rol = ROLES[user.role]
    if (!rol || !PORTALES[portal].roles.includes(rol)) {
      // La cuenta existe pero es de otro portal: no se inicia sesión y se revoca el token que se acaba de crear.
      void fetch(`${API_URL}/api/auth/logout`, { method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } }).catch(() => {})
      return { estado: 'otro_portal', mensaje: `Esta cuenta no pertenece al ${PORTALES[portal].nombre.toLowerCase()}. Entra por el portal que le corresponde.` }
    }
    iniciarSesion(rol, { token, usuario: { id: String(user.id), nombre: user.name, correo: user.email } })
    return { estado: 'ok', rol, destino: PORTALES[portal].inicio }
  } catch (e) {
    if (e instanceof ErrorApi) {
      if (e.estado === 422 || e.estado === 401) return { estado: 'credenciales', mensaje: 'No pudimos validar tus datos. Revisa tu correo y contraseña.' }
      if (e.estado === 403) return { estado: 'bloqueada', mensaje: 'Esta cuenta está bloqueada temporalmente. Contacta a soporte.' }
      if (e.estado === 0) return { estado: 'conexion', mensaje: e.message }
      return { estado: 'error', mensaje: e.message }
    }
    return { estado: 'error', mensaje: 'No pudimos iniciar sesión. Intenta de nuevo.' }
  }
}
