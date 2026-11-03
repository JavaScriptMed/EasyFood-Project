"use client"

import { FECHA_OPERACION } from "@/lib/config"
import { formatFechaLarga } from "@/lib/format"
import { PageTitle, StatCard } from "@/components/panel"
import { AdminShell } from "@/components/admin-shell"
import { useMemo, useState } from "react"
import { Bike, Car, Check, ChevronDown, MapPin, Plus, Search, UserRound, Users, X } from "lucide-react"

import { toast } from "sonner"
import { BloqueEstado, FilasEstado } from "@/components/estados"
import { cambiarDisponibilidadRepartidor, crearRepartidor, listarRepartidores, obtenerCapacidadesVehiculo } from "@/lib/api"
import { etiquetasBloque, etiquetasEstadoRepartidor, etiquetasVehiculoCorto } from "@/lib/etiquetas"
import { AHORA_MOCK } from "@/lib/config"
import { iniciales, tiempoRelativo } from "@/lib/format"
import type { EstadoRepartidor, TipoVehiculo } from "@/lib/types"
import { useAsync } from "@/lib/use-async"

const statusStyles: Record<EstadoRepartidor, string> = { libre: "bg-emerald-500/10 text-emerald-700 dark:text-emerald-300", asignado: "bg-amber-500/10 text-amber-700 dark:text-amber-300", en_ruta: "bg-blue-500/10 text-blue-700 dark:text-blue-300", no_disponible: "bg-slate-500/10 text-slate-500" }
const statusDot: Record<EstadoRepartidor, string> = { libre: "bg-emerald-500", asignado: "bg-amber-500", en_ruta: "bg-blue-500 animate-pulse", no_disponible: "bg-slate-400" }
const borradorInicial = { nombre: "", correo: "", telefono: "", numero_empleado: "", tipo_vehiculo: "" as TipoVehiculo | "", placas: "", zona_base: "" }

function VehicleIcon({ vehicle }: { vehicle: TipoVehiculo }) {
  const Icon = vehicle === "bicicleta" ? Bike : vehicle === "automovil" ? Car : Bike
  return <Icon className="size-4" />
}

export default function RepartidoresPage() {
  const repartidores = useAsync(listarRepartidores)
  const capacidades = useAsync(obtenerCapacidadesVehiculo)
  const couriers = repartidores.data ?? []
  const limits = capacidades.data
  const [status, setStatus] = useState("Todos")
  const [vehicle, setVehicle] = useState("Todos")
  const [zone, setZone] = useState("Todas")
  const [view, setView] = useState<"cards" | "table">("cards")
  const [search, setSearch] = useState("")
  const [newOpen, setNewOpen] = useState(false)
  const [step, setStep] = useState(1)
  const [draft, setDraft] = useState(borradorInicial)
  const [saving, setSaving] = useState(false)
  const campo = (clave: keyof typeof borradorInicial) => ({ value: draft[clave], onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setDraft((d) => ({ ...d, [clave]: e.target.value })) })
  const filtered = useMemo(() => couriers.filter((courier) => (status === "Todos" || etiquetasEstadoRepartidor[courier.estado] === status) && (vehicle === "Todos" || etiquetasVehiculoCorto[courier.tipo_vehiculo] === vehicle) && (zone === "Todas" || courier.zona_base === zone) && courier.nombre.toLowerCase().includes(search.toLowerCase())), [couriers, status, vehicle, zone, search])
  const counts = { Libres: couriers.filter((x) => x.estado === "libre").length, Asignados: couriers.filter((x) => x.estado === "asignado").length, "En ruta": couriers.filter((x) => x.estado === "en_ruta").length, "No disponibles": couriers.filter((x) => x.estado === "no_disponible").length }
  const toggleAvailable = async (id: string) => {
    try {
      await cambiarDisponibilidadRepartidor(id)
      repartidores.reload({ silent: true })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo cambiar la disponibilidad")
    }
  }
  async function guardar() {
    if (!draft.tipo_vehiculo) { toast.error("Selecciona el tipo de vehículo"); return }
    setSaving(true)
    try {
      const nuevo = await crearRepartidor({ ...draft, tipo_vehiculo: draft.tipo_vehiculo })
      toast.success(`${nuevo.nombre} agregado al equipo`)
      setNewOpen(false)
      setDraft(borradorInicial)
      repartidores.reload({ silent: true })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo guardar el repartidor")
    } finally {
      setSaving(false)
    }
  }

  return <AdminShell><div className="min-h-screen bg-muted/30 p-5 lg:p-8"><div className="mx-auto max-w-[1480px]">
    <div className="mb-7 flex flex-wrap items-end justify-between gap-4 border-b pb-6"><div><PageTitle eyebrow="Operación / Equipo" title="Repartidores" description={`Disponibilidad y capacidad del equipo para hoy, ${formatFechaLarga(FECHA_OPERACION).replace(/^\S+ /, "")}.`} /></div><button onClick={() => { setStep(1); setNewOpen(true) }} className="flex items-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-bold shadow-sm hover:brightness-95"><Plus className="size-4" /> Nuevo repartidor</button></div>
    <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">{[["Libres", counts.Libres, "Puedes asignarlos ahora"], ["Asignados", counts.Asignados, "Con bloque reservado"], ["En ruta", counts["En ruta"], "Moviéndose actualmente"], ["No disponibles", counts["No disponibles"], "En descanso o fuera"]].map(([label, value, note]) => <StatCard key={String(label)} label={String(label)} value={repartidores.loading ? "–" : value} helper={String(note)} />)}</div>
    <div className="mb-5 flex flex-wrap items-center gap-2 rounded-xl border bg-card p-3 shadow-sm"><div className="relative min-w-[220px] flex-1"><Search className="absolute left-3 top-2.5 size-4 text-muted-foreground" /><input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Buscar repartidor" className="h-9 w-full rounded-md border bg-background pl-9 pr-3 text-sm outline-none focus:ring-2 focus:ring-primary" /></div>{[["Estado", status, ["Todos", "Libre", "Asignado", "En ruta", "No disponible"]], ["Vehículo", vehicle, ["Todos", "Bici", "Moto", "Carro"]], ["Zona", zone, ["Todas", "Centro", "Providencia", "Zapopan", "Tlaquepaque", "Valle Real"]]].map(([label, value, options]) => <label key={String(label)} className="flex h-9 items-center gap-2 rounded-md border px-2 text-xs text-muted-foreground"><span>{label}</span><select value={String(value)} onChange={(e) => label === "Estado" ? setStatus(e.target.value) : label === "Vehículo" ? setVehicle(e.target.value) : setZone(e.target.value)} className="bg-transparent font-semibold text-foreground outline-none">{(options as string[]).map((option) => <option key={option}>{option}</option>)}</select><ChevronDown className="size-3" /></label>)}<div className="ml-auto flex rounded-md border p-0.5"><button onClick={() => setView("cards")} className={`rounded px-3 py-1.5 text-xs font-semibold ${view === "cards" ? "bg-primary" : ""}`}>Tarjetas</button><button onClick={() => setView("table")} className={`rounded px-3 py-1.5 text-xs font-semibold ${view === "table" ? "bg-primary" : ""}`}>Tabla</button></div></div>
    {view === "cards" ? <><BloqueEstado variante="tarjetas" cantidad={3} cargando={repartidores.loading} error={repartidores.error} vacio={!filtered.length} mensajeVacio="No hay repartidores que coincidan con los filtros." onReintentar={repartidores.reload} /><div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{filtered.map((courier) => <article key={courier.id} className="rounded-xl border bg-card p-5 shadow-sm"><div className="flex items-start justify-between"><div className="flex items-center gap-3"><div className="flex size-11 items-center justify-center rounded-full bg-primary/20 font-display font-bold text-primary-foreground">{iniciales(courier.nombre, 9)}</div><div><h2 className="font-semibold">{courier.nombre}</h2><p className="text-xs text-muted-foreground">{courier.numero_empleado}</p></div></div><span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${statusStyles[courier.estado]}`}><span className={`size-1.5 rounded-full ${statusDot[courier.estado]}`} />{etiquetasEstadoRepartidor[courier.estado]}</span></div><div className="mt-5 grid grid-cols-2 gap-y-3 text-sm"><div><p className="text-xs text-muted-foreground">Vehículo</p><p className="mt-1 flex items-center gap-2 font-medium"><VehicleIcon vehicle={courier.tipo_vehiculo} />{etiquetasVehiculoCorto[courier.tipo_vehiculo]}{courier.vehiculo_personalizado && <span className="rounded bg-muted px-1.5 py-0.5 text-[10px]">Personalizado</span>}</p></div><div><p className="text-xs text-muted-foreground">Zona base</p><p className="mt-1 flex items-center gap-1 font-medium"><MapPin className="size-3.5 text-muted-foreground" />{courier.zona_base}</p></div><div><p className="text-xs text-muted-foreground">Capacidad</p><p className="mt-1 font-medium">{limits?.[courier.tipo_vehiculo] ?? "–"} entregas / ruta</p></div><div><p className="text-xs text-muted-foreground">Última ubicación</p><p className="mt-1 font-medium">{tiempoRelativo(courier.ultima_ubicacion_en, AHORA_MOCK)}</p></div></div><div className="mt-5 border-t pt-4"><div className="mb-2 flex items-center justify-between text-xs"><span className="text-muted-foreground">Bloques ocupados</span><span>{courier.entregas_asignadas} asignadas · {courier.entregas_completadas} completadas</span></div><div className="flex min-h-6 gap-1.5">{courier.bloques_reservados.length ? courier.bloques_reservados.map((block) => <span key={block} className="rounded bg-muted px-2 py-1 text-xs font-medium">{etiquetasBloque[block]}</span>) : <span className="text-xs text-muted-foreground">Sin bloques reservados</span>}</div></div><div className="mt-4 flex items-center justify-between"><span className="text-xs text-muted-foreground">Disponible para asignación</span><button onClick={() => toggleAvailable(courier.id)} aria-label={`Cambiar disponibilidad de ${courier.nombre}`} className={`relative h-6 w-11 rounded-full transition ${courier.estado !== "no_disponible" ? "bg-primary" : "bg-muted"}`}><span className={`absolute top-1 size-4 rounded-full bg-white shadow transition ${courier.estado !== "no_disponible" ? "left-6" : "left-1"}`} /></button></div></article>)}</div></> : <div className="overflow-x-auto rounded-xl border bg-card shadow-sm"><table className="w-full min-w-[850px] text-left text-sm"><thead className="border-b bg-muted/40 text-xs uppercase text-muted-foreground"><tr>{["Repartidor", "Estado", "Vehículo", "Zona", "Capacidad", "Bloques", "Entregas", "Disponible"].map((heading) => <th key={heading} className="px-4 py-3">{heading}</th>)}</tr></thead><tbody><FilasEstado columnas={8} cargando={repartidores.loading} error={repartidores.error} vacio={!filtered.length} mensajeVacio="No hay repartidores que coincidan con los filtros." onReintentar={repartidores.reload} />{filtered.map((courier) => <tr key={courier.id} className="border-b last:border-0"><td className="px-4 py-4"><strong>{courier.nombre}</strong><p className="text-xs text-muted-foreground">{courier.numero_empleado}</p></td><td className="px-4"><span className={`rounded-full px-2 py-1 text-xs font-semibold ${statusStyles[courier.estado]}`}>{etiquetasEstadoRepartidor[courier.estado]}</span></td><td className="px-4">{etiquetasVehiculoCorto[courier.tipo_vehiculo]}</td><td className="px-4">{courier.zona_base}</td><td className="px-4">{limits?.[courier.tipo_vehiculo] ?? "–"}</td><td className="px-4">{courier.bloques_reservados.map((b) => etiquetasBloque[b]).join(", ") || "—"}</td><td className="px-4">{courier.entregas_completadas}/{courier.entregas_asignadas || 0}</td><td className="px-4"><button onClick={() => toggleAvailable(courier.id)} className="text-primary underline">Cambiar</button></td></tr>)}</tbody></table></div>}
    <section className="mt-8 rounded-xl border bg-card p-5 shadow-sm"><div className="mb-4 flex items-center justify-between"><div><h2 className="font-display text-lg font-bold">Capacidades por vehículo</h2><p className="text-sm text-muted-foreground">Límites generales usados al generar rutas.</p></div><button className="text-sm font-semibold text-primary">Editar límites</button></div><BloqueEstado cargando={capacidades.loading} error={capacidades.error} vacio={!limits} mensajeVacio="No hay capacidades configuradas." onReintentar={capacidades.reload} cantidad={1} /><div className="grid gap-3 md:grid-cols-3">{limits && Object.entries(limits).map(([type, limit]) => <div key={type} className="flex items-center justify-between rounded-lg border p-3"><span className="flex items-center gap-2 text-sm font-medium"><VehicleIcon vehicle={type as TipoVehiculo} />{etiquetasVehiculoCorto[type as TipoVehiculo]}</span><span className="font-display font-bold">{limit} <span className="text-xs font-normal text-muted-foreground">entregas</span></span></div>)}</div></section>
    {newOpen && <div className="fixed inset-0 z-50 flex items-center justify-center bg-foreground/40 p-4"><div className="w-full max-w-lg rounded-2xl border bg-card p-6 shadow-xl"><div className="mb-6 flex items-start justify-between"><div><p className="text-xs font-bold uppercase tracking-wider text-primary">Paso {step} de 3</p><h2 className="mt-1 font-display text-xl font-bold">Nuevo repartidor</h2></div><button onClick={() => setNewOpen(false)} aria-label="Cerrar"><X className="size-5" /></button></div><div className="mb-6 flex gap-1">{[1, 2, 3].map((item) => <div key={item} className={`h-1.5 flex-1 rounded-full ${item <= step ? "bg-primary" : "bg-muted"}`} />)}</div>{step === 1 && <div className="grid gap-4"><input className="rounded-lg border bg-background p-3 text-sm" placeholder="Nombre completo" {...campo("nombre")} /><input className="rounded-lg border bg-background p-3 text-sm" placeholder="Correo de acceso" type="email" {...campo("correo")} /><input className="rounded-lg border bg-background p-3 text-sm" placeholder="Teléfono" {...campo("telefono")} /></div>}{step === 2 && <div className="grid gap-4"><input className="rounded-lg border bg-background p-3 text-sm" placeholder="Número de empleado · EMP-0000" {...campo("numero_empleado")} /><input className="rounded-lg border bg-background p-3 text-sm" placeholder="Fecha de ingreso" type="date" /><input className="rounded-lg border bg-background p-3 text-sm" placeholder="Contacto de emergencia" /></div>}{step === 3 && <div className="grid gap-4"><select className="rounded-lg border bg-background p-3 text-sm" {...campo("tipo_vehiculo")}><option value="">Tipo de vehículo</option>{(Object.keys(etiquetasVehiculoCorto) as TipoVehiculo[]).map((t) => <option key={t} value={t}>{etiquetasVehiculoCorto[t]}</option>)}</select><input className="rounded-lg border bg-background p-3 text-sm" placeholder="Placas (obligatorias para moto y carro)" {...campo("placas")} /><input className="rounded-lg border bg-background p-3 text-sm" placeholder="Zona base" {...campo("zona_base")} /></div>}<div className="mt-7 flex justify-between"><button onClick={() => step === 1 ? setNewOpen(false) : setStep(step - 1)} className="rounded-lg border px-4 py-2 text-sm font-semibold">{step === 1 ? "Cancelar" : "Atrás"}</button><button onClick={() => step === 3 ? guardar() : setStep(step + 1)} disabled={saving} className="rounded-lg bg-primary px-4 py-2 text-sm font-bold disabled:opacity-60">{step === 3 ? (saving ? "Guardando…" : "Guardar repartidor") : "Continuar"}</button></div></div></div>}
  </div></div></AdminShell>
}
