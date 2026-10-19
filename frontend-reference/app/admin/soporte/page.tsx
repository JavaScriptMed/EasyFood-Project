"use client"

import { useState } from "react"
import { CheckCheck, ChevronRight, Clock3, Paperclip, Search, Send, UserRound } from "lucide-react"

const tickets = [
  { id: "TKT-4821", client: "Mariana López", subject: "No llegó mi comida", order: "GDL-20261116-0042", status: "abierto", unread: 2, time: "hace 4 min", initials: "ML" },
  { id: "TKT-4818", client: "Roberto Sánchez", subject: "Cambiar dirección de entrega", order: "GDL-20261116-0037", status: "en_proceso", unread: 0, time: "hace 18 min", initials: "RS" },
  { id: "TKT-4812", client: "Ana Paula Torres", subject: "¿Puedo cambiar mi platillo?", order: "GDL-20261116-0029", status: "abierto", unread: 1, time: "hace 31 min", initials: "AT" },
  { id: "TKT-4804", client: "Jorge Ramírez", subject: "Confirmación de suscripción", order: "GDL-20261116-0018", status: "resuelto", unread: 0, time: "ayer, 18:42", initials: "JR" },
  { id: "TKT-4799", client: "Lucía Méndez", subject: "Cobro duplicado", order: "GDL-20261115-0091", status: "cerrado", unread: 0, time: "ayer, 15:10", initials: "LM" },
]

const statusStyles: Record<string, string> = { abierto: "bg-red-500/10 text-red-600", en_proceso: "bg-amber-500/10 text-amber-700", resuelto: "bg-emerald-500/10 text-emerald-700", cerrado: "bg-muted text-muted-foreground" }

export default function SoportePage() {
  const [active, setActive] = useState(tickets[0])
  const [messages, setMessages] = useState([
    { from: "client", text: "Hola, mi entrega estaba programada para las 12:30 y todavía no llega. ¿Me pueden ayudar?", time: "12:44" },
    { from: "client", text: "El pedido es el GDL-20261116-0042.", time: "12:45" },
    { from: "agent", text: "Hola Mariana, ya estoy revisando tu pedido. Dame un momento, por favor.", time: "12:46" },
  ])
  const [draft, setDraft] = useState("")
  const [typing, setTyping] = useState(false)

  function sendMessage() {
    if (!draft.trim()) return
    setMessages((current) => [...current, { from: "agent", text: draft.trim(), time: "12:49" }])
    setDraft("")
    setTyping(true)
    window.setTimeout(() => setTyping(false), 1800)
  }

  function quickReply(text: string) {
    setDraft(text)
  }

  return (
    <main className="min-h-screen bg-background p-4 lg:p-7">
      <div className="mx-auto max-w-[1500px]">
        <header className="mb-5 flex flex-wrap items-end justify-between gap-4">
          <div><p className="mb-1 text-xs font-semibold uppercase tracking-[0.18em] text-primary">Atención operativa</p><h1 className="font-display text-3xl font-bold tracking-tight">Soporte</h1><p className="mt-1 text-sm text-muted-foreground">Resuelve dudas de clientes sin perder el contexto del pedido.</p></div>
          <div className="flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm"><span className="size-2 rounded-full bg-emerald-500" /> 3 agentes conectados</div>
        </header>

        <section className="grid min-h-[680px] overflow-hidden rounded-2xl border bg-card shadow-sm lg:grid-cols-[300px_minmax(420px,1fr)_300px]">
          <aside className="border-b lg:border-b-0 lg:border-r">
            <div className="border-b p-4"><div className="mb-3 flex items-center justify-between"><h2 className="font-semibold">Tickets</h2><span className="rounded-full bg-red-500/10 px-2 py-0.5 text-xs font-bold text-red-600">3 nuevos</span></div><div className="flex items-center gap-2 rounded-lg border bg-background px-3 py-2"><Search size={15} className="text-muted-foreground" /><input placeholder="Buscar ticket..." className="w-full bg-transparent text-sm outline-none" /></div></div>
            <div className="max-h-[590px] overflow-y-auto">{tickets.map((ticket) => <button key={ticket.id} onClick={() => setActive(ticket)} className={`w-full border-b p-4 text-left transition hover:bg-muted/60 ${active.id === ticket.id ? "border-l-2 border-l-primary bg-primary/5" : "border-l-2 border-l-transparent"}`}><div className="flex gap-3"><div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-secondary text-xs font-bold">{ticket.initials}</div><div className="min-w-0 flex-1"><div className="flex items-center justify-between gap-2"><span className="truncate text-sm font-semibold">{ticket.client}</span>{ticket.unread > 0 && <span className="rounded-full bg-primary px-1.5 text-[10px] font-bold">{ticket.unread}</span>}</div><p className="mt-1 truncate text-sm">{ticket.subject}</p><div className="mt-2 flex items-center justify-between gap-2 text-[11px] text-muted-foreground"><span>{ticket.order}</span><span>{ticket.time}</span></div><span className={`mt-2 inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${statusStyles[ticket.status]}`}>{ticket.status.replace("_", " ")}</span></div></div></button>)}</div>
          </aside>

          <section className="flex min-h-[680px] flex-col">
            <div className="flex items-center justify-between border-b px-5 py-4"><div className="flex items-center gap-3"><div className="flex size-10 items-center justify-center rounded-full bg-primary/20 text-sm font-bold">{active.initials}</div><div><h2 className="font-semibold">{active.client}</h2><p className="text-xs text-muted-foreground">{active.id} · {active.subject}</p></div></div><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusStyles[active.status]}`}>{active.status.replace("_", " ")}</span></div>
            <div className="flex-1 space-y-5 overflow-y-auto bg-muted/20 p-5">{messages.map((message, index) => <div key={`${message.time}-${index}`} className={`flex ${message.from === "agent" ? "justify-end" : "justify-start"}`}><div className={`max-w-[78%] ${message.from === "agent" ? "items-end" : "items-start"}`}><div className={`rounded-2xl px-4 py-3 text-sm leading-relaxed ${message.from === "agent" ? "rounded-br-sm bg-primary text-primary-foreground" : "rounded-bl-sm border bg-card"}`}>{message.text}</div><div className={`mt-1 flex items-center gap-1 text-[11px] text-muted-foreground ${message.from === "agent" ? "justify-end" : ""}`}>{message.time} {message.from === "agent" && <CheckCheck size={13} />}</div></div></div>)}{typing && <div className="flex items-center gap-2 text-xs text-muted-foreground"><span className="flex gap-1"><i className="size-1.5 animate-bounce rounded-full bg-current" /><i className="size-1.5 animate-bounce rounded-full bg-current [animation-delay:120ms]" /><i className="size-1.5 animate-bounce rounded-full bg-current [animation-delay:240ms]" /></span> Mariana está escribiendo...</div>}</div>
            <div className="border-t p-4"><div className="mb-3 flex flex-wrap gap-2">{["Estoy revisando tu pedido.", "Te confirmo en un momento.", "Podemos reprogramar tu entrega."].map((reply) => <button key={reply} onClick={() => quickReply(reply)} className="rounded-full border px-3 py-1.5 text-xs text-muted-foreground transition hover:border-primary hover:text-foreground">{reply}</button>)}</div><div className="flex items-end gap-2 rounded-xl border bg-background p-2"><button className="rounded-lg p-2 text-muted-foreground hover:bg-muted"><Paperclip size={17} /></button><textarea value={draft} onChange={(event) => setDraft(event.target.value)} onKeyDown={(event) => { if (event.key === "Enter" && !event.shiftKey && !event.nativeEvent.isComposing && event.keyCode !== 229) { event.preventDefault(); sendMessage() } }} placeholder="Escribe una respuesta..." rows={2} className="min-h-10 flex-1 resize-none bg-transparent px-1 py-1 text-sm outline-none" /><button onClick={sendMessage} className="rounded-lg bg-primary p-2.5 text-primary-foreground transition hover:brightness-95"><Send size={17} /></button></div><p className="mt-2 text-[11px] text-muted-foreground">Enter para enviar · Shift + Enter para salto de línea</p></div>
          </section>

          <aside className="border-t bg-muted/20 p-5 lg:border-l lg:border-t-0"><div className="mb-5 flex items-center justify-between"><h2 className="font-semibold">Resumen del pedido</h2><button className="text-xs font-medium text-primary">Ver pedido <ChevronRight className="inline" size={13} /></button></div><div className="rounded-xl border bg-card p-4"><div className="mb-4 flex items-center justify-between"><span className="text-xs text-muted-foreground">Folio</span><span className="text-sm font-bold">{active.order}</span></div><div className="space-y-3 border-t pt-4 text-sm"><div className="flex justify-between"><span className="text-muted-foreground">Plan</span><span>Plan semanal</span></div><div className="flex justify-between"><span className="text-muted-foreground">Próxima entrega</span><span>Hoy, 12:30–14:00</span></div><div className="flex justify-between"><span className="text-muted-foreground">Zona</span><span>Guadalajara Centro</span></div><div className="flex justify-between"><span className="text-muted-foreground">Total</span><strong>$1,248 MXN</strong></div></div></div><div className="mt-5 rounded-xl border bg-card p-4"><div className="mb-3 flex items-center gap-2"><UserRound size={15} /><span className="text-sm font-semibold">Dirección de entrega</span></div><p className="text-sm leading-relaxed">Av. Vallarta 1842, Col. Americana<br />Guadalajara, Jalisco</p><p className="mt-2 text-xs text-muted-foreground">Portón negro, casa verde</p></div><div className="mt-5 space-y-2"><button className="w-full rounded-lg border bg-card px-3 py-2.5 text-sm font-semibold hover:bg-muted">Reprogramar entrega</button><button className="w-full rounded-lg border bg-card px-3 py-2.5 text-sm font-semibold hover:bg-muted">Ver evidencia</button><button className="w-full rounded-lg bg-foreground px-3 py-2.5 text-sm font-semibold text-background hover:opacity-90">Cerrar ticket</button></div><div className="mt-5 flex items-center gap-2 text-xs text-muted-foreground"><Clock3 size={14} /> Tiempo de primera respuesta: 2 min</div></aside>
        </section>
      </div>
    </main>
  )
}
