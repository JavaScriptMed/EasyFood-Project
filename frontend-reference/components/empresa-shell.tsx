'use client'

import Link from 'next/link'
import { usePathname, useRouter } from 'next/navigation'
import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { ClipboardList, FileText, Home, LogOut, Menu, Receipt, UserCog, Users, Utensils, X } from 'lucide-react'
import { ErrorEstado } from '@/components/estados'
import { SelectorTema } from '@/components/tema'
import { Skeleton } from '@/components/ui/skeleton'
import { agregarEmpleados, cambiarEstadoEmpleado, listarEmpleados, obtenerConfiguracionOperacion, obtenerEmpresa } from '@/lib/api'
import { EMPRESA_PORTAL_ID } from '@/lib/config'
import { iniciales } from '@/lib/format'
import { esRutaPublica } from '@/lib/portales'
import { cerrarSesion, useSesionPortal } from '@/lib/sesion'
import type { EmpleadoDetalle, EmpresaDetalle, ResultadoAltaEmpleados } from '@/lib/types'
import { useAsync } from '@/lib/use-async'

type EmpresaContext = {
  empresa: EmpresaDetalle
  empleados: EmpleadoDetalle[]
  horaCorte: string
  recargar: () => void
  agregarEmpleados: (personas: { nombre: string; correo: string }[]) => Promise<ResultadoAltaEmpleados>
  cambiarEstadoEmpleado: (id: string, activo: boolean) => Promise<void>
}

const Ctx = createContext<EmpresaContext | null>(null)

export function useEmpresa() {
  const ctx = useContext(Ctx)
  if (!ctx) throw new Error('useEmpresa debe usarse dentro de EmpresaShell')
  return ctx
}

const items = [
  { label: 'Inicio', href: '/empresas', icon: Home },
  { label: 'Empleados', href: '/empresas/empleados', icon: Users },
  { label: 'Pedidos de mañana', href: '/empresas/pedidos', icon: ClipboardList },
  { label: 'Consumo y cobro', href: '/empresas/consumo', icon: Receipt },
  { label: 'Facturas', href: '/empresas/facturas', icon: FileText },
  { label: 'Equipo de RR. HH.', href: '/empresas/equipo', icon: UserCog },
]

export function EmpresaShell({ children }: { children: React.ReactNode }) {
  const path = usePathname()
  const router = useRouter()
  const [open, setOpen] = useState(false)
  const isLogin = esRutaPublica(path)
  const allowed = useSesionPortal('empresas', !isLogin)
  const datos = useAsync(async () => {
    if (isLogin || !allowed) return undefined
    const [empresa, empleados, operacion] = await Promise.all([obtenerEmpresa(EMPRESA_PORTAL_ID), listarEmpleados(EMPRESA_PORTAL_ID), obtenerConfiguracionOperacion()])
    return { empresa, empleados, horaCorte: operacion.hora_corte }
  }, [isLogin, allowed])

  useEffect(() => setOpen(false), [path])

  const alta = useCallback(async (personas: { nombre: string; correo: string }[]) => {
    const resultado = await agregarEmpleados(EMPRESA_PORTAL_ID, personas)
    datos.reload({ silent: true })
    return resultado
  }, [datos.reload])

  const cambiarEstado = useCallback(async (id: string, activo: boolean) => {
    // Se refleja al instante y se confirma con el servidor; si falla, se vuelve a cargar la lista real.
    datos.setData((actual) => actual && { ...actual, empleados: actual.empleados.map((e) => (e.id === id ? { ...e, activo, eleccion_manana: activo ? e.eleccion_manana : null } : e)) })
    try {
      await cambiarEstadoEmpleado(id, activo)
    } catch (error) {
      datos.reload({ silent: true })
      throw error
    }
  }, [datos.setData, datos.reload])

  const value = useMemo(
    () => (datos.data ? { empresa: datos.data.empresa, empleados: datos.data.empleados, horaCorte: datos.data.horaCorte, recargar: () => datos.reload({ silent: true }), agregarEmpleados: alta, cambiarEstadoEmpleado: cambiarEstado } : null),
    [datos.data, alta, cambiarEstado],
  )
  const empresa = datos.data?.empresa

  if (isLogin) return <>{children}</>
  if (!allowed) return <div className="min-h-screen bg-background" />

  return (
    <Ctx.Provider value={value}>
      <div className="min-h-screen bg-background">
        {open && <div className="fixed inset-0 z-20 bg-black/40 lg:hidden" onClick={() => setOpen(false)} aria-hidden="true" />}
        <aside className={`fixed inset-y-0 left-0 z-30 flex w-72 max-w-[85vw] flex-col border-r bg-card p-5 transition-transform lg:translate-x-0 ${open ? 'translate-x-0' : '-translate-x-full'}`}>
          <div className="mb-8 flex items-start justify-between">
            <div className="flex items-center gap-3">
              <div className="grid size-10 place-items-center rounded-xl bg-primary text-primary-foreground"><Utensils /></div>
              <div>
                <p className="font-display font-extrabold">Easy<span className="text-primary">Lunch</span></p>
                <p className="text-xs text-muted-foreground">Portal de empresas</p>
              </div>
            </div>
            <button className="lg:hidden" onClick={() => setOpen(false)} aria-label="Cerrar menú"><X /></button>
          </div>
          <nav className="flex flex-col gap-1">
            {items.map(({ label, href, icon: Icon }) => (
              <Link key={href} href={href} className={`flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-semibold transition ${path === href ? 'bg-foreground text-background shadow-sm [&_svg]:text-primary' : 'text-muted-foreground hover:bg-muted hover:text-foreground'}`}>
                <Icon className="size-4" />{label}
              </Link>
            ))}
          </nav>
          <div className="mt-auto border-t pt-4">
            <Link href="/empresas/login" onClick={cerrarSesion} className="flex items-center gap-3 px-3 py-2 text-sm text-muted-foreground hover:text-foreground"><LogOut className="size-4" /> Cerrar sesión</Link>
          </div>
        </aside>
        <div className="lg:pl-72">
          <header className="sticky top-0 z-10 flex h-16 items-center gap-3 border-b bg-background/90 px-4 backdrop-blur sm:px-5 lg:px-8">
            <button className="lg:hidden" onClick={() => setOpen(true)} aria-label="Abrir menú"><Menu /></button>
            <div className="min-w-0 flex-1">
              <div className="truncate text-sm font-semibold">{empresa ? empresa.nombre : <Skeleton className="h-4 w-40" />}</div>
              <p className="truncate text-xs text-muted-foreground">Contacto de RR. HH.</p>
            </div>
            <SelectorTema /><span className="hidden text-sm font-semibold sm:block">{empresa?.contacto.nombre}</span>
            <div className="grid size-9 place-items-center rounded-full bg-primary/20 text-sm font-bold">{empresa ? iniciales(empresa.contacto.nombre, 9) : ''}</div>
          </header>
          <main className="mx-auto max-w-[1280px] p-4 sm:p-5 lg:p-8">
            {value ? children : datos.error ? <ErrorEstado error={datos.error} onReintentar={datos.reload} className="rounded-xl border bg-card" /> : (
              <div className="flex flex-col gap-4" aria-hidden="true">
                <Skeleton className="h-16 w-1/2" />
                <div className="grid gap-4 md:grid-cols-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-36 rounded-xl" />)}</div>
                <Skeleton className="h-64 rounded-xl" />
              </div>
            )}
          </main>
        </div>
      </div>
    </Ctx.Provider>
  )
}
