"use client"

import { useMemo, useState } from "react"
import { Search, X } from "lucide-react"
import { AdminShell } from "@/components/admin-shell"
import { BloqueEstado, FilasEstado } from "@/components/estados"
import { PageTitle, Pill, StatCard } from "@/components/panel"
import { Input } from "@/components/ui/input"
import { listarClientes, obtenerCliente } from "@/lib/api"
import { etiquetasEstadoPedido, etiquetasEstadoTicket } from "@/lib/etiquetas"
import { formatDate, formatMXN, formatRangoFechas, iniciales } from "@/lib/format"
import type { EstadoPedido, EstadoTicket } from "@/lib/types"
import { useAsync } from "@/lib/use-async"

const tonoPedido = { pendiente_pago: "warning", recibido: "info", activo: "brand", completado: "success", cancelado: "danger" } as const
const tonoTicket = { abierto: "danger", en_proceso: "warning", resuelto: "success", cerrado: "neutral" } as const

export default function ClientesPage() {
  const clientes = useAsync(listarClientes)
  const [query, setQuery] = useState("")
  const [seleccionId, setSeleccionId] = useState<string | null>(null)
  const lista = clientes.data ?? []
  const visibles = useMemo(() => lista.filter((c) => `${c.nombre} ${c.correo}`.toLowerCase().includes(query.trim().toLowerCase())), [lista, query])
  const resumen = { activos: lista.filter((c) => c.pedidos_activos > 0).length, ingresos: lista.reduce((s, c) => s + c.gasto_total, 0), tickets: lista.reduce((s, c) => s + c.tickets_abiertos, 0) }
  const cargando = clientes.loading

  return <AdminShell>
    <div className="min-h-screen bg-background p-5 md:p-8"><div className="mx-auto max-w-7xl">
      <div className="mb-7 border-b pb-6"><PageTitle eyebrow="Cuentas" title="Clientes" description="Quién compra, cuánto gasta y qué atención necesita." /></div>

      <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Clientes" value={cargando ? "–" : lista.length} helper="Con cuenta en la plataforma" />
        <StatCard label="Con pedidos activos" value={cargando ? "–" : resumen.activos} helper="Pedidos recibidos, activos o por pagar" />
        <StatCard label="Ingresos" tono="success" value={cargando ? "–" : formatMXN(resumen.ingresos)} helper="Sin contar pedidos cancelados" />
        <StatCard label="Tickets abiertos" tono={resumen.tickets ? "warning" : undefined} value={cargando ? "–" : resumen.tickets} helper="Abiertos o en proceso" />
      </div>

      <div className="relative mb-4 max-w-md"><Search className="absolute left-3 top-2.5 size-4 text-muted-foreground" aria-hidden="true" /><Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Buscar por nombre o correo" aria-label="Buscar cliente" className="pl-9" /></div>

      <div className="overflow-hidden rounded-2xl border bg-card shadow-sm"><div className="overflow-x-auto">
        <table className="w-full min-w-[860px] text-left text-sm">
          <thead className="border-b bg-muted/40 text-xs uppercase text-muted-foreground"><tr><th className="px-5 py-3.5">Cliente</th><th className="px-5 py-3.5 text-right">Pedidos</th><th className="px-5 py-3.5 text-right">Activos</th><th className="px-5 py-3.5 text-right">Gasto total</th><th className="px-5 py-3.5">Último pedido</th><th className="px-5 py-3.5">Soporte</th></tr></thead>
          <tbody>
            <FilasEstado columnas={6} cargando={clientes.loading} error={clientes.error} vacio={!visibles.length} mensajeVacio={query ? "No hay clientes que coincidan con la búsqueda." : "Todavía no hay clientes registrados."} onReintentar={clientes.reload} />
            {visibles.map((c) => (
              <tr key={c.usuario_id} onClick={() => setSeleccionId(c.usuario_id)} className="cursor-pointer border-b last:border-0 hover:bg-muted/40">
                <td className="px-5 py-4"><div className="flex items-center gap-3"><span className="grid size-9 shrink-0 place-items-center rounded-full bg-primary/20 text-xs font-bold">{iniciales(c.nombre)}</span><div className="min-w-0"><p className="font-semibold">{c.nombre}</p><p className="truncate text-xs text-muted-foreground">{c.correo}</p></div></div></td>
                <td className="px-5 py-4 text-right tabular-nums">{c.pedidos_total}</td>
                <td className="px-5 py-4 text-right tabular-nums">{c.pedidos_activos}</td>
                <td className="px-5 py-4 text-right font-semibold tabular-nums">{formatMXN(c.gasto_total)}</td>
                <td className="px-5 py-4 text-muted-foreground">{c.ultimo_pedido ? formatDate(c.ultimo_pedido) : "—"}</td>
                <td className="px-5 py-4">{c.tickets_abiertos ? <Pill tono="warning">{c.tickets_abiertos} {c.tickets_abiertos === 1 ? "ticket abierto" : "tickets abiertos"}</Pill> : <span className="text-xs text-muted-foreground">Sin tickets</span>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div></div>
    </div></div>
    {seleccionId && <DetalleCliente id={seleccionId} onCerrar={() => setSeleccionId(null)} />}
  </AdminShell>
}

function DetalleCliente({ id, onCerrar }: { id: string; onCerrar: () => void }) {
  const cliente = useAsync(() => obtenerCliente(id), [id])
  const c = cliente.data
  return <div className="fixed inset-0 z-50 flex justify-end bg-black/35" onClick={onCerrar}>
    <aside role="dialog" aria-label="Detalle del cliente" className="h-full w-full max-w-xl overflow-y-auto bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
      <div className="flex items-start justify-between gap-4">
        <div><p className="text-xs font-semibold uppercase tracking-[0.18em] text-muted-foreground">Cliente</p><h2 className="mt-1 font-display text-2xl font-bold tracking-tight">{c?.nombre ?? "Cargando…"}</h2><p className="text-sm text-muted-foreground">{c?.correo}</p></div>
        <button onClick={onCerrar} aria-label="Cerrar"><X /></button>
      </div>
      <BloqueEstado cargando={cliente.loading} error={cliente.error} vacio={false} mensajeVacio="" onReintentar={cliente.reload} cantidad={3} className="mt-6" />
      {c && <>
        <div className="mt-6 grid grid-cols-3 gap-3">
          <StatCard label="Pedidos" value={c.pedidos_total} />
          <StatCard label="Gasto" value={formatMXN(c.gasto_total)} />
          <StatCard label="Tickets" value={c.tickets_abiertos} helper="abiertos" />
        </div>
        <section className="mt-7"><h3 className="font-display text-lg font-bold">Pedidos</h3>
          <div className="mt-3 flex flex-col gap-3">
            <BloqueEstado cargando={false} error={null} vacio={!c.pedidos.length} mensajeVacio="Este cliente todavía no tiene pedidos." onReintentar={cliente.reload} />
            {c.pedidos.map((p) => <div key={p.folio} className="rounded-xl border p-4"><div className="flex items-start justify-between gap-3"><div><p className="font-mono text-xs font-semibold">{p.folio}</p><p className="mt-1 text-sm font-medium">{p.plan_nombre} · {formatRangoFechas(p.fecha_inicio, p.fecha_fin)}</p></div><Pill tono={tonoPedido[p.estado as EstadoPedido]}>{etiquetasEstadoPedido[p.estado as EstadoPedido]}</Pill></div><div className="mt-3 flex items-center justify-between text-sm text-muted-foreground"><span>{p.entregas_completadas}/{p.entregas_totales} entregas</span><strong className="text-foreground">{formatMXN(p.total)}</strong></div></div>)}
          </div>
        </section>
        <section className="mt-7"><h3 className="font-display text-lg font-bold">Soporte</h3>
          <div className="mt-3 flex flex-col gap-3">
            <BloqueEstado cargando={false} error={null} vacio={!c.tickets.length} mensajeVacio="Sin tickets de soporte." onReintentar={cliente.reload} />
            {c.tickets.map((t) => <div key={t.id} className="flex items-center justify-between gap-3 rounded-xl border p-4"><div className="min-w-0"><p className="truncate text-sm font-semibold">{t.asunto}</p><p className="font-mono text-xs text-muted-foreground">{t.id} · {t.pedido_folio}</p></div><Pill tono={tonoTicket[t.estado as EstadoTicket]}>{etiquetasEstadoTicket[t.estado as EstadoTicket]}</Pill></div>)}
          </div>
        </section>
      </>}
    </aside>
  </div>
}
