"use client"

import Link from "next/link"
import { usePathname } from "next/navigation"
import { useState } from "react"
import { SelectorTema } from "@/components/tema"
import { listarIncidencias } from "@/lib/api"
import { cerrarSesion, useSesionPortal } from "@/lib/sesion"
import { useAsync } from "@/lib/use-async"
import { Bell, ChevronRight, LayoutDashboard, UtensilsCrossed, ShoppingBag, Route, Bike, Users, LifeBuoy, Settings, Menu, X, Building2, Landmark, Receipt, LogOut } from "lucide-react"

const grupos = [
  { titulo: "Operación", items: [{ label: "Dashboard", href: "/admin", icon: LayoutDashboard }, { label: "Pedidos", href: "/admin/pedidos", icon: ShoppingBag }, { label: "Rutas", href: "/admin/rutas", icon: Route }, { label: "Repartidores", href: "/admin/repartidores", icon: Bike }, { label: "Catálogo", href: "/admin/menus", icon: UtensilsCrossed }] },
  { titulo: "Cuentas", items: [{ label: "Comedores", href: "/admin/comedores", icon: Building2 }, { label: "Empresas y contratos", href: "/admin/empresas", icon: Landmark }, { label: "Clientes", href: "/admin/clientes", icon: Users }] },
  { titulo: "Finanzas", items: [{ label: "Facturación", href: "/admin/facturacion", icon: Receipt }] },
  { titulo: "Sistema", items: [{ label: "Soporte", href: "/admin/soporte", icon: LifeBuoy }, { label: "Configuración", href: "/admin/configuracion", icon: Settings }] },
]

export function AdminShell({ children }: { children: React.ReactNode; active?: string }) {
  const path = usePathname()
  const [open, setOpen] = useState(false)
  const sesionOk = useSesionPortal("admin")
  const abiertas = useAsync(() => listarIncidencias({ abiertas: true }))
  const pendientes = abiertas.data?.length ?? 0
  const activo = (href: string) => (href === "/admin" ? path === href : path === href || path.startsWith(`${href}/`))
  if (!sesionOk) return <div className="min-h-screen bg-background" />
  return <div className="min-h-screen bg-background">
    {open && <div className="fixed inset-0 z-30 bg-black/40 md:hidden" onClick={() => setOpen(false)} aria-hidden="true" />}
    <aside className={`fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r bg-card transition-transform md:translate-x-0 ${open ? "translate-x-0" : "-translate-x-full"}`}>
      <div className="flex items-center justify-between px-5 pb-5 pt-6">
        <Link href="/admin" className="flex items-center gap-3">
          <span className="grid size-10 place-items-center rounded-xl bg-foreground text-primary shadow-sm"><UtensilsCrossed className="size-5" /></span>
          <span><span className="block font-display text-lg font-extrabold leading-none">Easy<span className="text-primary">Lunch</span></span><span className="mt-1 block text-[10px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">Administración</span></span>
        </Link>
        <button className="md:hidden" onClick={() => setOpen(false)} aria-label="Cerrar menú"><X /></button>
      </div>
      <nav className="flex-1 overflow-y-auto px-3 pb-4">
        {grupos.map((grupo) => <div key={grupo.titulo} className="mt-5 first:mt-1">
          <p className="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.18em] text-muted-foreground/80">{grupo.titulo}</p>
          <div className="flex flex-col gap-0.5">{grupo.items.map(({ label, href, icon: Icon }) => <Link key={label} href={href} onClick={() => setOpen(false)} aria-current={activo(href) ? "page" : undefined} className={`group flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition ${activo(href) ? "bg-foreground text-background shadow-sm" : "text-muted-foreground hover:bg-muted hover:text-foreground"}`}><Icon className={`size-4 ${activo(href) ? "text-primary" : "transition-colors group-hover:text-foreground"}`} />{label}</Link>)}</div>
        </div>)}
      </nav>
      <div className="m-3 flex items-center gap-3 rounded-xl border bg-muted/40 p-3">
        <span className="grid size-9 place-items-center rounded-full bg-primary/20 text-sm font-bold">A</span>
        <div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold leading-tight">Admin</p><p className="truncate text-xs text-muted-foreground">Administrador</p></div>
        <Link href="/login" onClick={cerrarSesion} aria-label="Cerrar sesión" className="rounded-lg p-2 text-muted-foreground transition hover:bg-muted hover:text-foreground"><LogOut className="size-4" /></Link>
      </div>
    </aside>
    <main className="md:pl-64">
      <header className="sticky top-0 z-20 flex h-14 items-center gap-3 border-b bg-background/85 px-5 backdrop-blur md:px-8">
        <button className="md:hidden" onClick={() => setOpen(true)} aria-label="Abrir menú"><Menu /></button>
        <nav aria-label="Ruta de navegación" className="flex min-w-0 items-center gap-1.5 text-sm"><span className="text-muted-foreground">Panel</span><ChevronRight className="size-3.5 text-muted-foreground/60" aria-hidden="true" /><span className="truncate font-semibold">{grupos.flatMap((g) => g.items).find((i) => activo(i.href))?.label ?? "Administración"}</span></nav>
        <div className="ml-auto flex items-center gap-2"><SelectorTema />
          <Link href="/admin/rutas" aria-label={pendientes ? `${pendientes} incidencias abiertas` : "Sin incidencias abiertas"} className="relative rounded-lg p-2 text-muted-foreground transition hover:bg-muted hover:text-foreground"><Bell className="size-[18px]" />{pendientes > 0 && <span className="absolute right-0.5 top-0.5 grid min-w-4 place-items-center rounded-full bg-destructive px-1 text-[10px] font-bold leading-4 text-white">{pendientes}</span>}</Link>
        </div>
      </header>
      <div className="page-enter">{children}</div>
    </main>
  </div>
}
