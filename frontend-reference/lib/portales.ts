import type { Portal, Rol } from './types'

export const PORTALES: Record<Portal, { nombre: string; base: string; login: string; inicio: string; roles: Rol[] }> = {
  admin: { nombre: 'Panel administrativo', base: '/admin', login: '/login', inicio: '/admin', roles: ['administrador', 'soporte'] },
  comedores: { nombre: 'Portal de comedores', base: '/comedores', login: '/comedores/login', inicio: '/comedores', roles: ['comedor'] },
  empresas: { nombre: 'Portal de empresas', base: '/empresas', login: '/empresas/login', inicio: '/empresas', roles: ['empresa'] },
  empleados: { nombre: 'Portal de empleados', base: '/empleados', login: '/empleados/login', inicio: '/empleados', roles: ['empleado'] },
}

export const portalDeRol = (rol: Rol): Portal | null => (Object.keys(PORTALES) as Portal[]).find((p) => PORTALES[p].roles.includes(rol)) ?? null

/** Pantallas de cuenta que no exigen sesión, por portal. */
export const RUTAS_CUENTA = ['olvide-contrasena', 'restablecer-contrasena', 'aceptar-invitacion', 'sin-permiso', 'sesion-expirada'] as const

export const rutaCuenta = (portal: Portal, pantalla: (typeof RUTAS_CUENTA)[number]) => `${PORTALES[portal].base}/${pantalla}`

/** ¿La ruta es pública (login o pantalla de cuenta)? */
export const esRutaPublica = (path: string) =>
  (Object.keys(PORTALES) as Portal[]).some((p) => path === PORTALES[p].login || RUTAS_CUENTA.some((r) => path === rutaCuenta(p, r)))
