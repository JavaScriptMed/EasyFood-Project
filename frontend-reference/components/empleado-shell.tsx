'use client'

import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { LogOut, UtensilsCrossed } from 'lucide-react'
import { SelectorTema } from '@/components/tema'
import { esRutaPublica } from '@/lib/portales'
import { cerrarSesion, useSesionPortal } from '@/lib/sesion'

/** Estructura del portal del empleado: barra superior ligera y contenido centrado. */
export function EmpleadoShell({ children }: { children: React.ReactNode }) {
  const path = usePathname()
  const publica = esRutaPublica(path)
  const sesionOk = useSesionPortal('empleados', !publica)
  if (publica) return <>{children}</>
  if (!sesionOk) return <div className="min-h-screen bg-background" />
  return (
    <div className="min-h-screen bg-background">
      <header className="sticky top-0 z-20 border-b bg-background/85 backdrop-blur">
        <div className="mx-auto flex h-16 max-w-5xl items-center justify-between gap-4 px-5 lg:px-8">
          <Link href="/empleados" className="flex items-center gap-3">
            <span className="grid size-10 place-items-center rounded-xl bg-foreground text-primary shadow-sm"><UtensilsCrossed className="size-5" /></span>
            <span><span className="block font-display text-lg font-extrabold leading-none">Easy<span className="text-primary">Lunch</span></span><span className="mt-1 block text-[10px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">Portal de empleados</span></span>
          </Link>
          <div className="flex items-center gap-2"><SelectorTema />
          <Link href="/empleados/login" onClick={cerrarSesion} className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition hover:bg-muted hover:text-foreground"><LogOut className="size-4" /> Cerrar sesión</Link></div>
        </div>
      </header>
      <main className="page-enter mx-auto max-w-5xl p-5 lg:p-8">{children}</main>
    </div>
  )
}
