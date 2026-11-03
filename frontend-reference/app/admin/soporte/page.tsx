"use client"

import { PageTitle } from "@/components/panel"
import { AdminShell } from "@/components/admin-shell"
import { useEffect, useState } from "react"
import { CheckCheck, ChevronRight, Clock3, Paperclip, Search, Send, UserRound } from "lucide-react"

import { toast } from "sonner"
import { BloqueEstado, ErrorEstado } from "@/components/estados"
import { Skeleton } from "@/components/ui/skeleton"
import { enviarMensajeTicket, listarTickets, obtenerTicket } from "@/lib/api"
import { AHORA_MOCK } from "@/lib/config"
import { etiquetasEstadoTicket } from "@/lib/etiquetas"
import { formatMXN, horaDe, iniciales, tiempoRelativo } from "@/lib/format"
import type { EstadoTicket, MensajeTicket } from "@/lib/types"
import { useAsync } from "@/lib/use-async"

const statusStyles: Record<EstadoTicket, string> = { abierto: "bg-red-500/10 text-red-600", en_proceso: "bg-amber-500/10 text-amber-700", resuelto: "bg-emerald-500/10 text-emerald-700", cerrado: "bg-muted text-muted-foreground" }

export default function SoportePage() {
  const tickets = useAsync(listarTickets)
  const [activeId, setActiveId] = useState<string | null>(null)
  const detalle = useAsync(async () => (activeId ? obtenerTicket(activeId) : undefined), [activeId])
  const [messages, setMessages] = useState<MensajeTicket[]>([])
  const [draft, setDraft] = useState("")
  const [typing, setTyping] = useState(false)
  const active = tickets.data?.find((t) => t.id === activeId) ?? null
  const resumen = detalle.data?.resumen_pedido

  useEffect(() => { if (!activeId && tickets.data?.length) setActiveId(tickets.data[0].id) }, [activeId, tickets.data])
  useEffect(() => setMessages(detalle.data?.mensajes ?? []), [detalle.data])

  async function sendMessage() {
    if (!draft.trim() || !activeId) return
    const texto = draft
    setDraft("")
    try {
      const mensaje = await enviarMensajeTicket(activeId, texto)
      setMessages((current) => [...current, mensaje])
      setTyping(true)
      window.setTimeout(() => setTyping(false), 1800)
    } catch (error) {
      setDraft(texto)
      toast.error(error instanceof Error ? error.message : "No se pudo enviar el mensaje")
    }
  }

  function quickReply(text: string) {
    setDraft(text)
  }

  return (
    <AdminShell><div className="min-h-screen bg-background p-4 lg:p-7">
      <div className="mx-auto max-w-[1500px]">
        <header className="mb-6 flex flex-wrap items-end justify-between gap-4 border-b pb-6">
          <div><PageTitle eyebrow="Atención operativa" title="Soporte" description="Resuelve dudas de clientes sin perder el contexto del pedido." /></div>
          <div className="flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm"><span className="size-2 rounded-full bg-emerald-500" /> 3 agentes conectados</div>
        </header>

        <section className="grid min-h-[680px] overflow-hidden rounded-2xl border bg-card shadow-sm lg:grid-cols-[300px_minmax(420px,1fr)_300px]">
          <aside className="border-b lg:border-b-0 lg:border-r">
            <div className="border-b p-4"><div className="mb-3 flex items-center justify-between"><h2 className="font-semibold">Tickets</h2><span className="rounded-full bg-red-500/10 px-2 py-0.5 text-xs font-bold text-red-600">{(tickets.data ?? []).reduce((suma, t) => suma + t.mensajes_sin_leer, 0)} nuevos</span></div><div className="flex items-center gap-2 rounded-lg border bg-background px-3 py-2"><Search size={15} className="text-muted-foreground" /><input placeholder="Buscar ticket..." className="w-full bg-transparent text-sm outline-none" /></div></div>
            <div className="max-h-[590px] overflow-y-auto"><BloqueEstado cargando={tickets.loading} error={tickets.error} vacio={!tickets.data?.length} mensajeVacio="No hay tickets por atender." onReintentar={tickets.reload} cantidad={5} className="m-3" />{tickets.data?.map((ticket) => <button key={ticket.id} onClick={() => setActiveId(ticket.id)} className={`w-full border-b p-4 text-left transition hover:bg-muted/60 ${activeId === ticket.id ? "border-l-2 border-l-primary bg-primary/5" : "border-l-2 border-l-transparent"}`}><div className="flex gap-3"><div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-secondary text-xs font-bold">{iniciales(ticket.cliente_nombre)}</div><div className="min-w-0 flex-1"><div className="flex items-center justify-between gap-2"><span className="truncate text-sm font-semibold">{ticket.cliente_nombre}</span>{ticket.mensajes_sin_leer > 0 && <span className="rounded-full bg-primary px-1.5 text-[10px] font-bold">{ticket.mensajes_sin_leer}</span>}</div><p className="mt-1 truncate text-sm">{ticket.asunto}</p><div className="mt-2 flex items-center justify-between gap-2 text-[11px] text-muted-foreground"><span>{ticket.pedido_folio}</span><span>{tiempoRelativo(ticket.actualizado_en, AHORA_MOCK)}</span></div><span className={`mt-2 inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${statusStyles[ticket.estado]}`}>{etiquetasEstadoTicket[ticket.estado]}</span></div></div></button>)}</div>
          </aside>

          <section className="flex min-h-[680px] flex-col">
            <div className="flex items-center justify-between border-b px-5 py-4">{active ? <><div className="flex items-center gap-3"><div className="flex size-10 items-center justify-center rounded-full bg-primary/20 text-sm font-bold">{iniciales(active.cliente_nombre)}</div><div><h2 className="font-semibold">{active.cliente_nombre}</h2><p className="text-xs text-muted-foreground">{active.id} · {active.asunto}</p></div></div><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusStyles[active.estado]}`}>{etiquetasEstadoTicket[active.estado]}</span></> : <Skeleton className="h-10 w-64" />}</div>
            <div className="flex-1 space-y-5 overflow-y-auto bg-muted/20 p-5">{detalle.loading && <div className="space-y-4" aria-hidden="true"><Skeleton className="h-12 w-3/5" /><Skeleton className="ml-auto h-12 w-1/2" /><Skeleton className="h-12 w-2/3" /></div>}{detalle.error && <ErrorEstado error={detalle.error} onReintentar={detalle.reload} />}{!detalle.loading && !detalle.error && !messages.length && <p className="py-10 text-center text-sm text-muted-foreground">Este ticket todavía no tiene mensajes.</p>}{messages.map((message) => <div key={message.id} className={`flex ${message.autor === "agente" ? "justify-end" : "justify-start"}`}><div className={`max-w-[78%] ${message.autor === "agente" ? "items-end" : "items-start"}`}><div className={`rounded-2xl px-4 py-3 text-sm leading-relaxed ${message.autor === "agente" ? "rounded-br-sm bg-primary text-primary-foreground" : "rounded-bl-sm border bg-card"}`}>{message.texto}</div><div className={`mt-1 flex items-center gap-1 text-[11px] text-muted-foreground ${message.autor === "agente" ? "justify-end" : ""}`}>{horaDe(message.enviado_en)} {message.autor === "agente" && <CheckCheck size={13} />}</div></div></div>)}{typing && <div className="flex items-center gap-2 text-xs text-muted-foreground"><span className="flex gap-1"><i className="size-1.5 animate-bounce rounded-full bg-current" /><i className="size-1.5 animate-bounce rounded-full bg-current [animation-delay:120ms]" /><i className="size-1.5 animate-bounce rounded-full bg-current [animation-delay:240ms]" /></span> {active?.cliente_nombre.split(" ")[0]} está escribiendo...</div>}</div>
            <div className="border-t p-4"><div className="mb-3 flex flex-wrap gap-2">{["Estoy revisando tu pedido.", "Te confirmo en un momento.", "Podemos reprogramar tu entrega."].map((reply) => <button key={reply} onClick={() => quickReply(reply)} className="rounded-full border px-3 py-1.5 text-xs text-muted-foreground transition hover:border-primary hover:text-foreground">{reply}</button>)}</div><div className="flex items-end gap-2 rounded-xl border bg-background p-2"><button className="rounded-lg p-2 text-muted-foreground hover:bg-muted"><Paperclip size={17} /></button><textarea value={draft} onChange={(event) => setDraft(event.target.value)} onKeyDown={(event) => { if (event.key === "Enter" && !event.shiftKey && !event.nativeEvent.isComposing && event.keyCode !== 229) { event.preventDefault(); sendMessage() } }} placeholder="Escribe una respuesta..." rows={2} className="min-h-10 flex-1 resize-none bg-transparent px-1 py-1 text-sm outline-none" /><button onClick={sendMessage} className="rounded-lg bg-primary p-2.5 text-primary-foreground transition hover:brightness-95"><Send size={17} /></button></div><p className="mt-2 text-[11px] text-muted-foreground">Enter para enviar · Shift + Enter para salto de línea</p></div>
          </section>

          <aside className="border-t bg-muted/20 p-5 lg:border-l lg:border-t-0"><div className="mb-5 flex items-center justify-between"><h2 className="font-semibold">Resumen del pedido</h2><button className="text-xs font-medium text-primary">Ver pedido <ChevronRight className="inline" size={13} /></button></div><div className="rounded-xl border bg-card p-4"><div className="mb-4 flex items-center justify-between"><span className="text-xs text-muted-foreground">Folio</span><span className="text-sm font-bold">{active?.pedido_folio ?? "—"}</span></div>{detalle.loading && <div className="space-y-3 border-t pt-4" aria-hidden="true"><Skeleton className="h-4 w-full" /><Skeleton className="h-4 w-full" /><Skeleton className="h-4 w-full" /><Skeleton className="h-4 w-full" /></div>}{resumen && <div className="space-y-3 border-t pt-4 text-sm"><div className="flex justify-between"><span className="text-muted-foreground">Plan</span><span>{resumen.plan}</span></div><div className="flex justify-between"><span className="text-muted-foreground">Próxima entrega</span><span>{resumen.proxima_entrega}</span></div><div className="flex justify-between"><span className="text-muted-foreground">Zona</span><span>{resumen.zona}</span></div><div className="flex justify-between"><span className="text-muted-foreground">Total</span><strong>{formatMXN(resumen.total)} MXN</strong></div></div>}</div><div className="mt-5 rounded-xl border bg-card p-4"><div className="mb-3 flex items-center gap-2"><UserRound size={15} /><span className="text-sm font-semibold">Dirección de entrega</span></div>{resumen ? <><p className="text-sm leading-relaxed">{resumen.direccion.split("\n").map((linea, i) => <span key={i}>{i > 0 && <br />}{linea}</span>)}</p><p className="mt-2 text-xs text-muted-foreground">{resumen.referencias}</p></> : <Skeleton className="h-12 w-full" />}</div><div className="mt-5 space-y-2"><button className="w-full rounded-lg border bg-card px-3 py-2.5 text-sm font-semibold hover:bg-muted">Reprogramar entrega</button><button className="w-full rounded-lg border bg-card px-3 py-2.5 text-sm font-semibold hover:bg-muted">Ver evidencia</button><button className="w-full rounded-lg bg-foreground px-3 py-2.5 text-sm font-semibold text-background hover:opacity-90">Cerrar ticket</button></div><div className="mt-5 flex items-center gap-2 text-xs text-muted-foreground"><Clock3 size={14} /> Tiempo de primera respuesta: 2 min</div></aside>
        </section>
      </div>
    </div></AdminShell>
  )
}
