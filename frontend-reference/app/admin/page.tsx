"use client"

import { useMemo, useState } from "react"
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts"
import { Bell, CheckCircle2, ChevronRight, CircleAlert, Clock3, MapPin, Menu, PackageCheck, Search, Truck, Users, X } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { mockDeliveries } from "@/lib/mock-data"
import { deliveryStatusLabels, deliveryStatusClasses } from "@/lib/types"

const weekly = [
  { day: "Lun", pedidos: 112 }, { day: "Mar", pedidos: 138 }, { day: "Mié", pedidos: 126 },
  { day: "Jue", pedidos: 154 }, { day: "Vie", pedidos: 178 }, { day: "Sáb", pedidos: 194 }, { day: "Dom", pedidos: 146 },
]
const statusData = [
  { label: "Entregadas", value: 142, fill: "#43a86b" }, { label: "En ruta", value: 38, fill: "#3d7fe8" },
  { label: "Consolidación", value: 29, fill: "#e9b51f" }, { label: "Programadas", value: 39, fill: "#8c9aa7" },
]
const zonePoints = [
  { left: "28%", top: "30%", label: "GDL Centro" }, { left: "51%", top: "23%", label: "Providencia" },
  { left: "65%", top: "54%", label: "Chapalita" }, { left: "38%", top: "67%", label: "Americana" },
  { left: "77%", top: "73%", label: "Colinas" },
]

function KpiCard({ icon: Icon, label, value, helper, progress, accent }: { icon: typeof Truck; label: string; value: string; helper: string; progress?: number; accent: string }) {
  return <Card className="overflow-hidden border-border/70 shadow-sm transition-transform duration-300 hover:-translate-y-1">
    <CardContent className="relative p-5">
      <div className="flex items-start justify-between"><div className="grid size-10 place-items-center rounded-xl" style={{ backgroundColor: `${accent}18`, color: accent }}><Icon /></div><span className="text-xs font-semibold text-success">+12.5%</span></div>
      <p className="mt-5 text-sm text-muted-foreground">{label}</p><p className="font-display mt-1 text-3xl font-bold tracking-tight">{value}</p>
      {progress !== undefined && <div className="mt-3"><div className="h-2 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-primary" style={{ width: `${progress}%` }} /></div><p className="mt-2 text-xs text-muted-foreground">{helper}</p></div>}
      {progress === undefined && <p className="mt-2 text-xs text-muted-foreground">{helper}</p>}
    </CardContent>
  </Card>
}

function DeliveryMap() {
  return <div className="relative min-h-[330px] overflow-hidden rounded-2xl border border-border/70 bg-[#d9e1dc] dark:bg-[#1b2a2e]">
    <div className="absolute inset-0 opacity-60 [background-image:linear-gradient(28deg,transparent_47%,#ffffff_48%,#ffffff_50%,transparent_51%),linear-gradient(112deg,transparent_47%,#ffffff_48%,#ffffff_50%,transparent_51%),linear-gradient(#ffffff80_1px,transparent_1px),linear-gradient(90deg,#ffffff80_1px,transparent_1px)] [background-size:100% 100%,100% 100%,44px 44px,44px 44px]" />
    <div className="absolute left-[12%] top-[18%] h-[58%] w-[36%] rotate-12 rounded-[45%] border-2 border-[#55a57a]/60 bg-[#74c28b]/25" /><div className="absolute right-[12%] top-[25%] h-[44%] w-[35%] -rotate-12 rounded-[42%] border-2 border-[#d99a42]/60 bg-[#efbe5b]/25" />
    {zonePoints.map((point, i) => <div key={point.label} className="absolute" style={{ left: point.left, top: point.top }}><span className="block size-4 animate-pulse rounded-full border-2 border-white bg-[#2877dc] shadow-lg shadow-[#2877dc]/40" /><span className="absolute left-5 top-0 whitespace-nowrap rounded-md bg-background/90 px-2 py-1 text-[10px] font-medium shadow-sm">{point.label}</span></div>)}
    <div className="absolute bottom-4 left-4 rounded-lg bg-background/90 px-3 py-2 text-xs shadow-sm"><span className="font-semibold">Guadalajara</span><span className="ml-2 text-muted-foreground">20.6597, -103.3496</span></div>
    <div className="absolute right-4 top-4 rounded-lg bg-background/90 px-3 py-2 text-xs shadow-sm"><span className="mr-2 inline-block size-2 rounded-full bg-[#2877dc]" />Repartidores en ruta</div>
  </div>
}

export default function AdminDashboard() {
  const [query, setQuery] = useState("")
  const [notice, setNotice] = useState<string | null>(null)
  const filtered = useMemo(() => mockDeliveries.filter((item) => `${item.customer} ${item.zone}`.toLowerCase().includes(query.toLowerCase())), [query])
  return <main className="min-h-screen bg-background p-4 md:p-7 lg:p-9"><div className="mx-auto max-w-[1500px]">
    <header className="mb-7 flex flex-col justify-between gap-4 sm:flex-row sm:items-end"><div><p className="text-sm font-medium text-primary">Lunes, 16 de noviembre de 2026</p><h1 className="font-display mt-1 text-3xl font-bold tracking-tight md:text-4xl">Buenos días, Admin</h1><p className="mt-2 text-muted-foreground">Este es el resumen de tu operación para hoy.</p></div><div className="flex items-center gap-2"><div className="relative hidden md:block"><Search className="absolute left-3 top-2.5 text-muted-foreground" /><Input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Buscar operación..." className="w-64 pl-9" /></div><Button variant="outline" size="icon" aria-label="Notificaciones"><Bell /><span className="absolute ml-5 mt-[-18px] grid size-4 place-items-center rounded-full bg-destructive text-[10px] text-white">3</span></Button></div></header>
    <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><KpiCard icon={PackageCheck} label="Entregas de hoy" value="248" helper="186 entregadas de 248" progress={75} accent="#e9b51f" /><KpiCard icon={Truck} label="Pedidos activos" value="36" helper="12 en consolidación · 24 en ruta" accent="#3d7fe8" /><KpiCard icon={CheckCircle2} label="Ingresos de la semana" value="$84,620" helper="MXN · +8.2% vs. semana pasada" accent="#43a86b" /><KpiCard icon={CircleAlert} label="Incidencias del día" value="7" helper="2 requieren atención inmediata" accent="#d9822b" /></section>
    <section className="mt-4 grid gap-4 xl:grid-cols-[1.55fr_1fr]"><Card className="overflow-hidden"><CardHeader className="flex flex-row items-center justify-between"><div><CardTitle>Operación en Guadalajara</CardTitle><p className="mt-1 text-sm text-muted-foreground">Zonas activas y repartidores en tiempo real</p></div><Badge variant="secondary" className="gap-1"><span className="size-2 animate-pulse rounded-full bg-success" />En vivo</Badge></CardHeader><CardContent><DeliveryMap /></CardContent></Card><Card><CardHeader><CardTitle>Entregas por estado</CardTitle><p className="text-sm text-muted-foreground">Distribución de las entregas de hoy</p></CardHeader><CardContent><div className="flex h-[280px] flex-col justify-center gap-5">{statusData.map((item) => <div key={item.label}><div className="mb-2 flex items-center justify-between text-xs"><span>{item.label}</span><strong>{item.value}</strong></div><div className="h-3 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full transition-all duration-700" style={{ width: `${(item.value / 248) * 100}%`, backgroundColor: item.fill }} /></div></div>)}</div></CardContent></Card></section>
    <section className="mt-4 grid gap-4 lg:grid-cols-[1.2fr_.8fr_.8fr]"><Card><CardHeader><CardTitle>Pedidos últimos 7 días</CardTitle><p className="text-sm text-muted-foreground">Pedidos confirmados por día</p></CardHeader><CardContent><ResponsiveContainer width="100%" height={220}><AreaChart data={weekly}><defs><linearGradient id="orders" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="#e9b51f" stopOpacity={.45} /><stop offset="100%" stopColor="#e9b51f" stopOpacity={0} /></linearGradient></defs><CartesianGrid vertical={false} strokeDasharray="3 3" opacity={.35} /><XAxis dataKey="day" axisLine={false} tickLine={false} /><YAxis axisLine={false} tickLine={false} width={30} /><Tooltip /><Area type="monotone" dataKey="pedidos" stroke="#d69f0d" strokeWidth={3} fill="url(#orders)" /></AreaChart></ResponsiveContainer></CardContent></Card><Card><CardHeader><CardTitle>Alertas</CardTitle></CardHeader><CardContent className="flex flex-col gap-3">{["Faltan repartidores: 4 entregas de Guadalajara Centro", "3 pedidos tienen pago pendiente", "Ruta de Chapalita excede el bloque"].map((alert, i) => <div key={alert} className="rounded-xl border border-warning/25 bg-warning/10 p-3"><div className="flex gap-2"><CircleAlert className="mt-0.5 size-4 shrink-0 text-warning" /><p className="text-xs leading-5">{alert}</p></div><Button variant="link" className="h-auto px-0 pt-2 text-xs text-warning" onClick={() => setNotice("Alerta marcada para resolver")}>Resolver <ChevronRight /></Button></div>)}</CardContent></Card><Card><CardHeader><CardTitle>Actividad reciente</CardTitle></CardHeader><CardContent className="flex flex-col gap-4">{filtered.slice(0, 4).map((item, i) => <div key={item.id} className="flex gap-3"><div className="grid size-8 shrink-0 place-items-center rounded-full bg-secondary text-xs font-bold">{item.customer.split(" ").map((x) => x[0]).join("").slice(0, 2)}</div><div className="min-w-0"><p className="text-xs leading-5"><strong>{i % 2 ? "Ana" : "Carlos"}</strong> marcó como {deliveryStatusLabels[item.status].toLowerCase()} <strong>{item.id}</strong></p><p className="mt-1 flex items-center gap-1 text-[11px] text-muted-foreground"><Clock3 /> hace {i + 2} min</p></div></div>)}</CardContent></Card></section>
    {notice && <div role="status" className="fixed bottom-5 right-5 flex items-center gap-3 rounded-xl border bg-background px-4 py-3 text-sm shadow-xl"><CheckCircle2 className="text-success" />{notice}<Button variant="ghost" size="icon" onClick={() => setNotice(null)} aria-label="Cerrar"><X /></Button></div>}
  </div></main>
}
