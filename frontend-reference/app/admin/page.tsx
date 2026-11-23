"use client"

import { FECHA_OPERACION } from "@/lib/config"
import { capitalizar } from "@/lib/etiquetas"
import { formatFechaLarga } from "@/lib/format"
import { PageTitle } from "@/components/panel"
import { AdminShell } from "@/components/admin-shell"
import { useEffect, useMemo, useState } from "react"
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts"
import { CheckCircle2, ChevronRight, CircleAlert, Clock3, PackageCheck, Search, Truck, X } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import Link from "next/link"
import { AvisoContratosPorVencer } from "@/components/contrato-vencimiento"
import { IndicadoresAdministrador } from "@/components/indicadores"
import { BloqueEstado, PaginaEstado } from "@/components/estados"
import { MapaOperacion } from "@/components/mapa-mapbox"
import { listarEntregas, listarRutas, obtenerDashboard, resolverIncidencia } from "@/lib/api"
import { etiquetasEstadoEntrega } from "@/lib/etiquetas"
import { formatMXN, iniciales } from "@/lib/format"
import { cargarZonasConPoligonos, COLORES_ZONAS, resolverParadasMapa, type ParadaMapa, type ZonaConPoligono } from "@/lib/geocodificar-mapbox"
import { useAsync } from "@/lib/use-async"

function KpiCard({ icon: Icon, label, value, helper, progress, accent }: { icon: typeof Truck; label: string; value: string; helper: string; progress?: number; accent: string }) {
  return <Card className="group overflow-hidden border-border/70 transition duration-300 hover:-translate-y-0.5">
    <CardContent className="relative p-5">
      <span aria-hidden="true" className="absolute inset-x-0 top-0 h-0.5" style={{ background: `linear-gradient(90deg, ${accent}, transparent)` }} />
      <div className="flex items-start justify-between gap-3"><p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">{label}</p><div className="grid size-9 place-items-center rounded-xl" style={{ backgroundColor: `${accent}1f`, color: accent }}><Icon className="size-[18px]" /></div></div>
      <p className="mt-3 font-display text-[2rem] font-bold leading-none tracking-tight tabular-nums">{value}</p>
      {progress !== undefined && <div className="mt-4 h-1.5 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full transition-all duration-700" style={{ width: `${progress}%`, backgroundColor: accent }} /></div>}
      <p className="mt-2 text-xs text-muted-foreground">{helper}</p>
    </CardContent>
  </Card>
}

export default function AdminDashboard() {
  const [query, setQuery] = useState("")
  const [notice, setNotice] = useState<string | null>(null)
  const panel = useAsync(obtenerDashboard)
  const entregas = useAsync(listarEntregas)
  const rutasHoy = useAsync(() => listarRutas(FECHA_OPERACION))
  const zonasMapa = useAsync<ZonaConPoligono[]>(() => cargarZonasConPoligonos())
  const [paradasMapa, setParadasMapa] = useState<ParadaMapa[]>([])
  const [sinUbicacion, setSinUbicacion] = useState(0)

  useEffect(() => {
    let cancelado = false
    const lista = rutasHoy.data ?? []
    if (!lista.length) {
      setParadasMapa([])
      setSinUbicacion(0)
      return
    }
    void resolverParadasMapa(lista, COLORES_ZONAS).then((r) => {
      if (!cancelado) {
        setParadasMapa(r.paradas)
        setSinUbicacion(r.sinUbicacion)
      }
    })
    return () => {
      cancelado = true
    }
  }, [rutasHoy.data])

  const filtered = useMemo(() => (entregas.data ?? []).filter((item) => `${item.empresa_nombre} ${item.zona}`.toLowerCase().includes(query.toLowerCase())), [entregas.data, query])
  const resumen = panel.data?.resumen

  async function resolver(id: string) {
    try {
      await resolverIncidencia(id)
      setNotice("Alerta marcada como resuelta")
      panel.reload({ silent: true })
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "No se pudo resolver la alerta")
    }
  }
  return <AdminShell><div className="min-h-screen bg-background p-4 md:p-7 lg:p-9"><div className="mx-auto max-w-[1500px]">
    <header className="mb-7 flex flex-col justify-between gap-4 border-b pb-6 sm:flex-row sm:items-end"><div><PageTitle eyebrow={`${capitalizar(formatFechaLarga(FECHA_OPERACION))} de 2026`} title="Buenos días, Admin" description="Este es el resumen de tu operación para hoy." /></div><div className="flex items-center gap-2"><div className="relative hidden md:block"><Search className="absolute left-3 top-2.5 text-muted-foreground" /><Input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Buscar operación..." className="w-64 pl-9" /></div></div></header>
    <AvisoContratosPorVencer />
    <PaginaEstado cargando={panel.loading} error={panel.error} onReintentar={panel.reload} />
    {panel.data && resumen && <>
    <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><KpiCard icon={PackageCheck} label="Entregas de hoy" value={String(resumen.entregas_hoy)} helper={`${resumen.entregas_completadas} entregadas de ${resumen.entregas_hoy}`} progress={resumen.entregas_hoy ? Math.round((resumen.entregas_completadas / resumen.entregas_hoy) * 100) : 0} accent="#e9b51f" /><KpiCard icon={Truck} label="Pedidos activos" value={String(resumen.pedidos_activos)} helper={`${resumen.en_consolidacion} en consolidación · ${resumen.en_ruta} en ruta`} accent="#3d7fe8" /><KpiCard icon={CheckCircle2} label="Ingresos de la semana" value={formatMXN(resumen.ingresos_semana)} helper={`MXN · ${resumen.variacion_ingresos_porcentaje > 0 ? "+" : ""}${resumen.variacion_ingresos_porcentaje}% vs. semana pasada`} accent="#43a86b" /><KpiCard icon={CircleAlert} label="Incidencias del día" value={String(panel.data.incidencias_del_dia)} helper={`${panel.data.requieren_atencion} requieren atención inmediata`} accent="#d9822b" /></section>
    <section className="mt-4 grid gap-4 xl:grid-cols-[1.55fr_1fr]"><Card className="overflow-hidden"><CardHeader className="flex flex-row items-center justify-between"><div><CardTitle>Operación en Guadalajara</CardTitle><p className="mt-1 text-sm text-muted-foreground">Polígonos de zonas activas y paradas del día</p></div><Badge variant="secondary" className="gap-1"><span className="size-2 animate-pulse rounded-full bg-success" />En vivo</Badge></CardHeader><CardContent><MapaOperacion zonas={zonasMapa.data ?? []} paradas={paradasMapa} sinUbicacion={sinUbicacion} alturaMinima="min-h-[340px]" /></CardContent></Card><Card><CardHeader><CardTitle>Entregas por estado</CardTitle><p className="text-sm text-muted-foreground">Distribución de las entregas de hoy</p></CardHeader><CardContent><div className="flex h-[280px] flex-col justify-center gap-5">{!resumen.entregas_por_estado.length && <p className="text-center text-sm text-muted-foreground">Todavía no hay entregas programadas para hoy.</p>}{resumen.entregas_por_estado.map((item) => <div key={item.estado}><div className="mb-2 flex items-center justify-between text-xs"><span>{item.estado}</span><strong>{item.cantidad}</strong></div><div className="h-3 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full transition-all duration-700" style={{ width: `${(item.cantidad / Math.max(1, resumen.entregas_hoy)) * 100}%`, backgroundColor: item.color }} /></div></div>)}</div></CardContent></Card></section>
    <section className="mt-4 grid gap-4 lg:grid-cols-[1.2fr_.8fr_.8fr]"><Card><CardHeader><CardTitle>Pedidos últimos 7 días</CardTitle><p className="text-sm text-muted-foreground">Pedidos confirmados por día</p></CardHeader><CardContent><ResponsiveContainer width="100%" height={220}><AreaChart data={resumen.pedidos_por_dia}><defs><linearGradient id="orders" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="#e9b51f" stopOpacity={.45} /><stop offset="100%" stopColor="#e9b51f" stopOpacity={0} /></linearGradient></defs><CartesianGrid vertical={false} strokeDasharray="3 3" opacity={.35} /><XAxis dataKey="dia" axisLine={false} tickLine={false} /><YAxis axisLine={false} tickLine={false} width={30} /><Tooltip /><Area type="monotone" dataKey="pedidos" stroke="#d69f0d" strokeWidth={3} fill="url(#orders)" /></AreaChart></ResponsiveContainer></CardContent></Card><Card><CardHeader><CardTitle>Alertas</CardTitle></CardHeader><CardContent className="flex flex-col gap-3">{panel.data.alertas.slice(0, 3).map((alerta) => <div key={alerta.id} className="rounded-xl border border-warning/25 bg-warning/10 p-3"><div className="flex gap-2"><CircleAlert className="mt-0.5 size-4 shrink-0 text-warning" /><p className="text-xs leading-5">{alerta.descripcion}</p></div><Button variant="link" className="h-auto px-0 pt-2 text-xs text-warning" onClick={() => resolver(alerta.id)}>Resolver <ChevronRight /></Button></div>)}{!panel.data.alertas.length && <p className="py-6 text-center text-sm text-muted-foreground">No hay alertas pendientes.</p>}{panel.data.alertas.length > 3 && <Link href="/admin/rutas" className="text-center text-xs font-semibold text-primary hover:underline">Ver las {panel.data.alertas.length - 3} restantes en Rutas</Link>}</CardContent></Card><Card><CardHeader><CardTitle>Actividad reciente</CardTitle></CardHeader><CardContent className="flex flex-col gap-4"><BloqueEstado cargando={entregas.loading} error={entregas.error} vacio={!filtered.length} mensajeVacio="No hay actividad que coincida con la búsqueda." onReintentar={entregas.reload} cantidad={4} />{filtered.slice(0, 4).map((item, i) => <div key={item.id} className="flex gap-3"><div className="grid size-8 shrink-0 place-items-center rounded-full bg-secondary text-xs font-bold">{iniciales(item.empresa_nombre)}</div><div className="min-w-0"><p className="text-xs leading-5"><strong>{i % 2 ? "Ana" : "Carlos"}</strong> marcó como {etiquetasEstadoEntrega[item.estado].toLowerCase()} <strong>{item.id}</strong></p><p className="mt-1 flex items-center gap-1 text-[11px] text-muted-foreground"><Clock3 /> hace {i + 2} min</p></div></div>)}</CardContent></Card></section>
    <IndicadoresAdministrador datos={panel.data.indicadores} />
    </>}
    {notice && <div role="status" className="fixed bottom-5 right-5 flex items-center gap-3 rounded-xl border bg-background px-4 py-3 text-sm shadow-xl"><CheckCircle2 className="text-success" />{notice}<Button variant="ghost" size="icon" onClick={() => setNotice(null)} aria-label="Cerrar"><X /></Button></div>}
  </div></div></AdminShell>
}
