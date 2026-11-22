"use client"

import { FECHA_OPERACION } from "@/lib/config"
import { formatFechaLarga } from "@/lib/format"
import { PageTitle } from "@/components/panel"
import { AdminShell } from "@/components/admin-shell"
import { useEffect, useMemo, useState } from "react"
import { AlertTriangle, Bike, Car, Clock3, Play, Users, Zap } from "lucide-react"

import { toast } from "sonner"
import { BloqueEstado } from "@/components/estados"
import { IncidenciasRutas } from "@/components/incidencias-rutas"
import { MapaOperacion } from "@/components/mapa-mapbox"
import { aprobarRutas, generarRutas, listarIncidencias, listarRepartidores, listarRutas } from "@/lib/api"
import { etiquetasBloque, etiquetasVehiculoCorto } from "@/lib/etiquetas"
import { iniciales } from "@/lib/format"
import { cargarZonasConPoligonos, resolverParadasMapa, type ParadaMapa, type ZonaConPoligono } from "@/lib/geocodificar-mapbox"
import type { EstadoParada, RutaDetalle } from "@/lib/types"
import { useAsync } from "@/lib/use-async"

const colores = ["#eab308", "#2563eb", "#16a34a", "#d97706", "#9333ea"]
const etiquetasParada: Record<EstadoParada, string> = { pendiente: "Pendiente", en_camino: "En camino", entregado: "Entregado", no_localizado: "No localizado" }

export default function RutasPage() {
  const [planned, setPlanned] = useState(false)
  const [generating, setGenerating] = useState(false)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [live, setLive] = useState(false)
  const [approved, setApproved] = useState(false)
  const [showFree, setShowFree] = useState(false)
  const [capacityError, setCapacityError] = useState(false)
  const [date, setDate] = useState(FECHA_OPERACION)
  const [vista, setVista] = useState<"planeacion" | "incidencias">("planeacion")
  const rutas = useAsync(() => listarRutas(date), [date])
  const faltantes = useAsync(() => listarIncidencias({ abiertas: true, fecha: date }), [date])
  const zonasMapa = useAsync<ZonaConPoligono[]>(() => cargarZonasConPoligonos())
  const [paradasMapa, setParadasMapa] = useState<ParadaMapa[]>([])
  const [sinUbicacion, setSinUbicacion] = useState(0)
  const routes = rutas.data ?? []
  const hayRutas = routes.length > 0
  const selected: RutaDetalle | null = routes.find((r) => r.id === selectedId) ?? routes[0] ?? null
  const routeLines = useMemo(() => routes.map((r, i) => ({ ...r, color: colores[i % colores.length] })), [routes])
  const faltaRepartidores = faltantes.data?.find((i) => i.tipo === "falta_repartidores")
  const abiertas = faltantes.data?.filter((i) => ["empresa_cerrada", "empleado_ausente", "repartidor_no_llego", "platillo_danado"].includes(i.tipo)).length ?? 0
  const totalEntregas = routes.reduce((suma, r) => suma + r.entregas_asignadas, 0)

  useEffect(() => {
    let cancelado = false
    if (!routes.length) {
      setParadasMapa([])
      setSinUbicacion(0)
      return
    }
    void resolverParadasMapa(routes, colores).then((r) => {
      if (!cancelado) {
        setParadasMapa(r.paradas)
        setSinUbicacion(r.sinUbicacion)
      }
    })
    return () => {
      cancelado = true
    }
  }, [routes])

  async function generateRoutes() {
    setGenerating(true)
    setPlanned(false)
    setApproved(false)
    try {
      await generarRutas(date)
      rutas.reload({ silent: true })
      faltantes.reload({ silent: true }) // las incidencias de falta de repartidor se cierran solas al asignarse
      setPlanned(true)
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudieron generar las rutas")
    } finally {
      setGenerating(false)
    }
  }

  async function approve() {
    try {
      await aprobarRutas(date)
      setApproved(true)
      toast.success("Rutas aprobadas")
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudieron aprobar las rutas")
    }
  }

  return <AdminShell><div className="min-h-screen bg-background p-4 text-foreground md:p-8"><header className="mb-6 flex flex-col gap-4 border-b pb-6 lg:flex-row lg:items-end lg:justify-between"><div><PageTitle eyebrow={`Operación · ${formatFechaLarga(FECHA_OPERACION)}`} title="Rutas" description="Planea, revisa y monitorea las entregas del día." /></div><div className="flex flex-wrap items-center gap-2"><label className="flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm"><span className="text-muted-foreground">Fecha</span><input type="date" value={date} onChange={(e) => setDate(e.target.value)} className="bg-transparent outline-none" /></label><button onClick={generateRoutes} disabled={generating} className="flex items-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-bold shadow-sm disabled:opacity-70">{generating ? <Zap className="animate-pulse" size={16} /> : <Play size={16} />} {generating ? "Generando rutas…" : "Generar rutas del día"}</button><button disabled={!planned || approved} onClick={approve} className="rounded-lg border bg-card px-4 py-2.5 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-40">{approved ? "Rutas aprobadas" : "Aprobar rutas"}</button></div></header>
    <div className="mb-5 flex gap-2 border-b text-sm" role="tablist"><button role="tab" aria-selected={vista === "planeacion"} onClick={() => setVista("planeacion")} className={`px-4 py-3 font-semibold ${vista === "planeacion" ? "border-b-2 border-primary text-foreground" : "text-muted-foreground hover:text-foreground"}`}>Planeación</button><button role="tab" aria-selected={vista === "incidencias"} onClick={() => setVista("incidencias")} className={`flex items-center gap-2 px-4 py-3 font-semibold ${vista === "incidencias" ? "border-b-2 border-primary text-foreground" : "text-muted-foreground hover:text-foreground"}`}>Incidencias{abiertas > 0 && <span className="rounded-full bg-destructive/10 px-2 py-0.5 text-[11px] font-bold text-destructive">{abiertas}</span>}</button></div>
    {vista === "incidencias" ? <IncidenciasRutas fecha={date} onCambio={() => faltantes.reload({ silent: true })} /> : <>
    {faltaRepartidores && <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-orange-300/40 bg-orange-500/10 px-4 py-3 text-sm"><div className="flex items-center gap-3"><AlertTriangle size={18} className="text-orange-500" /><span>{faltaRepartidores.descripcion.includes(":") ? <><strong>{faltaRepartidores.descripcion.split(":")[0]}:</strong>{faltaRepartidores.descripcion.split(":").slice(1).join(":")}</> : faltaRepartidores.descripcion}</span></div><div className="flex gap-2"><button onClick={() => setShowFree(!showFree)} className="font-semibold text-orange-700 underline underline-offset-2">Ver repartidores libres</button><button onClick={generateRoutes} className="rounded-md border border-orange-400/50 px-3 py-1.5 font-semibold">Volver a generar</button></div></div>}
    {showFree && <RepartidoresLibres />}
    <section className="grid min-h-[650px] overflow-hidden rounded-2xl border bg-card shadow-sm lg:grid-cols-[1fr_390px]"><div className="min-h-[520px] p-2"><MapaOperacion zonas={zonasMapa.data ?? []} paradas={paradasMapa} sinUbicacion={sinUbicacion} rutaSeleccionadaId={selected?.id ?? null} mostrarLineasRuta alturaMinima="min-h-[520px]" enVivo={live} subtitulo={`Rutas del día · ${hayRutas ? `${routes.length} planificada(s)` : "sin rutas"}`} /></div><aside className="flex flex-col bg-background"><div className="flex items-center justify-between border-b px-5 py-4"><div><h2 className="font-display font-bold">Rutas planificadas</h2><p className="text-xs text-muted-foreground">{hayRutas ? `${routes.length} rutas · ${totalEntregas} entregas` : "Genera las rutas para comenzar"}</p></div><label className="flex items-center gap-2 text-xs font-semibold"><span>En vivo</span><button onClick={() => setLive(!live)} className={`relative h-6 w-11 rounded-full transition-colors ${live ? "bg-emerald-500" : "bg-muted"}`}><span className={`absolute top-1 size-4 rounded-full bg-white transition-transform ${live ? "translate-x-6" : "translate-x-1"}`} /></button></label></div><div className="flex-1 overflow-y-auto p-3"><BloqueEstado cargando={rutas.loading} error={rutas.error} vacio={!routes.length} mensajeVacio="No hay rutas para esta fecha." onReintentar={rutas.reload} className="mb-3" />{routeLines.map((route) => { const Icon = route.tipo_vehiculo === "automovil" ? Car : Bike; const full = route.entregas_asignadas >= route.capacidad_maxima; return <button key={route.id} onClick={() => setSelectedId(route.id)} className={`mb-3 w-full rounded-xl border p-4 text-left transition hover:border-primary/60 ${selected?.id === route.id ? "border-primary bg-primary/5 shadow-sm" : "bg-card"}`}><div className="flex items-start justify-between"><div className="flex items-center gap-3"><span className="flex size-10 items-center justify-center rounded-full bg-muted font-bold">{iniciales(route.repartidor_nombre)}</span><div><p className="font-semibold">{route.repartidor_nombre}</p><p className="flex items-center gap-1 text-xs text-muted-foreground"><Icon size={13} /> {etiquetasVehiculoCorto[route.tipo_vehiculo]} · {route.zona}</p></div></div><span className="font-mono text-xs font-bold" style={{ color: route.color }}>{route.id}</span></div><div className="mt-4 grid grid-cols-2 gap-3 text-xs"><div><p className="text-muted-foreground">Bloque</p><p className="mt-1 font-medium">{etiquetasBloque[route.bloque]} {route.ventana_inicio}–{route.ventana_fin}</p></div><div><p className="text-muted-foreground">Distancia</p><p className="mt-1 font-medium">{route.distancia_km} km</p></div></div><div className="mt-4"><div className="mb-1 flex justify-between text-xs"><span>Capacidad</span><strong className={full ? "text-orange-500" : ""}>{route.entregas_asignadas}/{route.capacidad_maxima} entregas</strong></div><div className="h-2 overflow-hidden rounded-full bg-muted"><div className={`h-full rounded-full ${full ? "bg-orange-500" : "bg-emerald-500"}`} style={{ width: `${(route.entregas_asignadas / route.capacidad_maxima) * 100}%` }} /></div></div><div className="mt-3 flex items-center justify-between text-xs text-muted-foreground"><span className="flex items-center gap-1"><Clock3 size={13} /> Termina {route.hora_fin_estimada}</span>{route.excede_bloque && <span className="rounded-full bg-orange-500/15 px-2 py-1 font-semibold text-orange-600">Excede el bloque</span>}</div></button>})}<button onClick={() => setCapacityError(true)} className="w-full rounded-lg border border-dashed px-3 py-2 text-xs font-semibold text-muted-foreground hover:bg-muted">Mover entrega a una ruta</button>{capacityError && <p className="mt-2 rounded-lg bg-red-500/10 p-3 text-xs font-semibold text-red-600">La ruta ya tiene 8 de 8 entregas permitidas para su vehículo</p>}</div></aside></section>
    {(planned || hayRutas) && selected && <section className="mt-4 rounded-2xl border bg-card p-5"><div className="mb-4 flex items-center justify-between"><div><h2 className="font-display text-lg font-bold">Paradas de {selected.id}</h2><p className="text-sm text-muted-foreground">Orden de entrega de la ruta seleccionada</p></div><span className="rounded-full bg-primary/15 px-3 py-1 text-xs font-bold text-primary-foreground">{selected.zona}</span></div><div className="grid gap-3 md:grid-cols-3">{selected.paradas.map((stop, i) => <div key={stop.id} className="flex items-center gap-3 rounded-xl border p-3"><span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold">{i + 1}</span><div className="min-w-0 flex-1"><p className="truncate font-semibold">{stop.nombre_destinatario}</p><p className="truncate text-xs text-muted-foreground">{stop.direccion}</p></div><div className="text-right"><p className="text-xs font-bold">{stop.hora_estimada}</p><p className="text-[11px] text-muted-foreground">{etiquetasParada[stop.estado]}</p></div></div>)}{!selected.paradas.length && <p className="text-sm text-muted-foreground">Esta ruta todavía no tiene paradas.</p>}</div></section>}
  </>}
  </div></AdminShell>
}

function RepartidoresLibres() {
  const libres = useAsync(() => listarRepartidores({ estado: "libre" }))
  return <div className="mb-4 rounded-xl border bg-card p-4 text-sm"><div className="flex items-center gap-2 font-semibold"><Users size={16} /> Repartidores disponibles</div><div className="mt-3 flex flex-wrap gap-2"><BloqueEstado cargando={libres.loading} error={libres.error} vacio={!libres.data?.length} mensajeVacio="No hay repartidores libres en este momento." onReintentar={libres.reload} cantidad={1} className="w-full" />{libres.data?.map((r) => <span key={r.id} className="rounded-full bg-emerald-500/15 px-3 py-1.5 text-emerald-600">{r.nombre} · {etiquetasVehiculoCorto[r.tipo_vehiculo]}</span>)}</div></div>
}
