"use client"

import { PageTitle } from "@/components/panel"
import { useMemo, useState } from "react"
import { Search, Plus, SlidersHorizontal, X, Upload, CalendarDays, Check, ChevronLeft, ChevronRight, GripVertical, UtensilsCrossed } from "lucide-react"
import { AdminShell } from "@/components/admin-shell"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Separator } from "@/components/ui/separator"
import { Switch } from "@/components/ui/switch"
import { toast } from "sonner"
import { BloqueEstado } from "@/components/estados"
import { guardarPlatillo, listarComedores, listarHistorialPrecios, listarPlanes, listarPlatillos, obtenerMenuSemanal } from "@/lib/api"
import { etiquetasTiempoComida } from "@/lib/etiquetas"
import { etiquetaDiaCorta, formatDate } from "@/lib/format"
import type { PlatilloDetalle, TiempoComida } from "@/lib/types"
import { useAsync } from "@/lib/use-async"

export default function MenusPage() {
  const [tab, setTab] = useState("Platillos")
  const [query, setQuery] = useState("")
  const [meal, setMeal] = useState("Todos")
  const [activeOnly, setActiveOnly] = useState(false)
  const [selected, setSelected] = useState<PlatilloDetalle | null>(null)
  const [showNew, setShowNew] = useState(false)
  const platillos = useAsync(() => listarPlatillos())
  const filtered = useMemo(() => (platillos.data ?? []).filter((dish) => (!query || dish.nombre.toLowerCase().includes(query.toLowerCase())) && (meal === "Todos" || etiquetasTiempoComida[dish.tiempo_comida] === meal) && (!activeOnly || dish.activo)), [platillos.data, query, meal, activeOnly])

  return <AdminShell active="Menús y platillos">
    <div className="min-h-screen bg-background p-5 md:p-8">
      <header className="mb-7 flex flex-wrap items-end justify-between gap-4 border-b pb-6"><div><PageTitle eyebrow="Catálogo operativo" title="Menús y platillos" description="Administra tu oferta, precios y disponibilidad semanal." /></div><Button onClick={() => setShowNew(true)} className="gap-2"><Plus /> Nuevo platillo</Button></header>
      <div className="mb-6 flex gap-1 border-b">{["Platillos", "Menú semanal", "Planes"].map((item) => <button key={item} onClick={() => setTab(item)} className={`border-b-2 px-4 py-3 text-sm font-semibold transition-colors ${tab === item ? "border-primary text-foreground" : "border-transparent text-muted-foreground hover:text-foreground"}`}>{item}</button>)}</div>
      {tab === "Platillos" && <>
        <div className="mb-5 flex flex-wrap gap-3"><div className="relative min-w-[250px] flex-1"><Search className="absolute left-3 top-2.5 text-muted-foreground" /><Input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Buscar platillo..." className="pl-10" /></div><select value={meal} onChange={(e) => setMeal(e.target.value)} className="rounded-md border bg-background px-3 text-sm"><option>Todos</option><option>Desayuno</option><option>Comida</option><option>Cena</option></select><Button variant="outline" className="gap-2"><SlidersHorizontal /> Proveedor</Button><label className="flex items-center gap-2 rounded-md border px-3 text-sm"><Switch checked={activeOnly} onCheckedChange={setActiveOnly} /> Solo activos</label></div>
        <div className="mb-4 flex items-center justify-between text-sm text-muted-foreground"><span>{filtered.length} platillos en catálogo</span><span>Actualizado hace 8 min</span></div>
        <BloqueEstado variante="tarjetas" cantidad={6} cargando={platillos.loading} error={platillos.error} vacio={!filtered.length} mensajeVacio="No hay platillos que coincidan con la búsqueda." onReintentar={platillos.reload} />
        <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">{filtered.map((dish) => <Card key={dish.id} className="group overflow-hidden transition-shadow hover:shadow-lg"><div className="relative h-44 overflow-hidden">{dish.foto_url ? <img src={dish.foto_url} alt={dish.nombre} className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105" /> : <div className="h-full w-full bg-gradient-to-br from-primary/30 to-muted" />}<Badge className="absolute left-3 top-3 bg-background/90 text-foreground backdrop-blur">{etiquetasTiempoComida[dish.tiempo_comida]}</Badge><span className={`absolute right-3 top-3 size-2 rounded-full ${dish.activo ? "bg-emerald-500" : "bg-muted-foreground"}`} /></div><CardContent className="p-4"><div className="flex items-start justify-between gap-3"><div><h3 className="font-display font-bold">{dish.nombre}</h3><p className="mt-1 text-xs text-muted-foreground">{dish.comedor_nombre} · {dish.calorias} kcal</p></div><span className="font-display text-lg font-bold">${dish.precio_venta}<small className="text-xs font-normal"> MXN</small></span></div><div className="mt-3 flex flex-wrap gap-1.5">{dish.alergenos.map((allergen) => <Badge key={allergen} variant="outline" className="text-[11px]">{allergen}</Badge>)}{dish.etiquetas.map((tag) => <Badge key={tag} variant="secondary" className="text-[11px]">{tag}</Badge>)}</div><Button variant="ghost" className="mt-3 w-full justify-between" onClick={() => setSelected(dish)}>Ver detalle <ChevronRight /></Button></CardContent></Card>)}</div>
      </>}
      {tab === "Menú semanal" && <WeeklyMenu />}
      {tab === "Planes" && <Plans />}
    </div>
    {(selected || showNew) && <aside className="fixed inset-y-0 right-0 z-50 w-full max-w-md overflow-y-auto border-l bg-background p-6 shadow-2xl"><div className="mb-6 flex items-center justify-between"><div><p className="text-xs font-semibold uppercase tracking-wider text-primary">{showNew ? "Nuevo registro" : "Detalle del platillo"}</p><h2 className="font-display text-2xl font-bold">{showNew ? "Crear platillo" : selected?.nombre}</h2></div><Button variant="ghost" size="icon" onClick={() => { setSelected(null); setShowNew(false) }}><X /></Button></div>{showNew ? <NewDishForm onSaved={() => { setShowNew(false); platillos.reload({ silent: true }) }} /> : <>{selected?.foto_url ? <img src={selected.foto_url} alt="" className="mb-5 h-44 w-full rounded-xl object-cover" /> : <div className="mb-5 h-44 w-full rounded-xl bg-gradient-to-br from-primary/30 to-muted" />}<div className="grid grid-cols-2 gap-3 text-sm"><div className="rounded-lg bg-muted p-3"><p className="text-muted-foreground">Proveedor</p><strong>{selected?.comedor_nombre}</strong></div><div className="rounded-lg bg-muted p-3"><p className="text-muted-foreground">Calorías</p><strong>{selected?.calorias} kcal</strong></div></div><Separator className="my-6" /><div className="flex items-center justify-between"><h3 className="font-display text-lg font-bold">Historial de precios</h3><Button variant="outline" size="sm">Programar precio</Button></div>{selected && <HistorialPrecios platilloId={selected.id} />}</>}</aside>}
  </AdminShell>
}

function NewDishForm({ onSaved }: { onSaved: () => void }) {
  const comedores = useAsync(listarComedores)
  const [form, setForm] = useState({ nombre: "", descripcion: "", comedor_id: "", tiempo_comida: "comida" as TiempoComida, calorias: "", precio_venta: "", alergenos: "" })
  const [saving, setSaving] = useState(false)
  const campo = (clave: keyof typeof form) => ({ value: form[clave], onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => setForm((f) => ({ ...f, [clave]: e.target.value })) })
  async function guardar() {
    setSaving(true)
    try {
      const platillo = await guardarPlatillo({
        comedor_id: form.comedor_id || comedores.data?.[0]?.id || "", nombre: form.nombre, descripcion: form.descripcion, tiempo_comida: form.tiempo_comida, calorias: Number(form.calorias) || 0,
        alergenos: form.alergenos.split(",").map((a) => a.trim()).filter(Boolean), precio_venta: Number(form.precio_venta) || undefined, estado_revision: "publicado",
      })
      toast.success(`${platillo.nombre} guardado`)
      onSaved()
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo guardar el platillo")
    } finally {
      setSaving(false)
    }
  }
  return <div className="flex flex-col gap-4"><label className="text-sm font-medium">Nombre<Input className="mt-1" placeholder="Ej. Bowl de pollo teriyaki" {...campo("nombre")} /></label><label className="text-sm font-medium">Descripción<textarea className="mt-1 min-h-24 w-full rounded-md border bg-background p-3 text-sm" placeholder="Describe ingredientes y preparación" {...campo("descripcion")} /></label><div className="grid grid-cols-2 gap-3"><label className="text-sm font-medium">Proveedor<select className="mt-1 w-full rounded-md border bg-background p-2 text-sm" disabled={comedores.loading} {...campo("comedor_id")}>{comedores.loading && <option>Cargando…</option>}{comedores.data?.map((c) => <option key={c.id} value={c.id}>{c.nombre}</option>)}</select></label><label className="text-sm font-medium">Tiempo<select className="mt-1 w-full rounded-md border bg-background p-2 text-sm" {...campo("tiempo_comida")}>{(Object.keys(etiquetasTiempoComida) as TiempoComida[]).map((t) => <option key={t} value={t}>{etiquetasTiempoComida[t]}</option>)}</select></label></div>{comedores.error && <p role="alert" className="text-xs text-destructive">No se pudieron cargar los proveedores. <button type="button" onClick={() => comedores.reload()} className="font-semibold underline">Reintentar</button></p>}<div className="rounded-xl border-2 border-dashed p-7 text-center text-sm text-muted-foreground"><Upload className="mx-auto mb-2" />Arrastra una foto o haz clic para subir</div><div className="grid grid-cols-2 gap-3"><label className="text-sm font-medium">Calorías<Input type="number" className="mt-1" placeholder="450" {...campo("calorias")} /></label><label className="text-sm font-medium">Precio MXN<Input type="number" className="mt-1" placeholder="119" {...campo("precio_venta")} /></label></div><label className="text-sm font-medium">Alérgenos<Input className="mt-1" placeholder="Soya, gluten" {...campo("alergenos")} /></label><Button className="mt-3" onClick={guardar} disabled={saving || comedores.loading || !!comedores.error}>{saving ? "Guardando…" : "Guardar platillo"}</Button></div>
}

function HistorialPrecios({ platilloId }: { platilloId: string }) {
  const historial = useAsync(() => listarHistorialPrecios(platilloId), [platilloId])
  return <div className="mt-4 flex flex-col gap-4"><BloqueEstado cargando={historial.loading} error={historial.error} vacio={!historial.data?.length} mensajeVacio="Este platillo todavía no tiene historial de precios." onReintentar={historial.reload} cantidad={3} />{historial.data?.map((price, i) => <div key={price.id} className="relative border-l-2 border-primary pl-5"><span className="absolute -left-[7px] top-1 size-3 rounded-full bg-primary" /><div className="flex justify-between"><strong>${price.precio_venta} MXN</strong><Badge variant={i === 0 ? "default" : "outline"}>{price.vigente_hasta ? formatDate(price.vigente_hasta) : "Vigente"}</Badge></div><p className="text-xs text-muted-foreground">Desde {formatDate(price.vigente_desde)} · Costo ${price.costo} · Margen {(((price.precio_venta - price.costo) / price.precio_venta) * 100).toFixed(1)}%</p></div>)}</div>
}

function WeeklyMenu() {
  const semana = useAsync(() => obtenerMenuSemanal("2026-11-16"))
  return <div><div className="mb-5 flex items-center justify-between"><div><h2 className="font-display text-xl font-bold">Semana del 16 al 22 de noviembre</h2><p className="text-sm text-muted-foreground">Los cambios se guardan como borrador.</p></div><div className="flex gap-2"><Button variant="outline" size="icon"><ChevronLeft /></Button><Button variant="outline" size="icon"><ChevronRight /></Button><Button className="gap-2"><Check /> Publicar menú</Button></div></div><BloqueEstado variante="tarjetas" cantidad={3} cargando={semana.loading} error={semana.error} vacio={false} mensajeVacio="" onReintentar={semana.reload} />{semana.data && <div className="grid overflow-x-auto rounded-xl border md:grid-cols-6">{semana.data.map((dia) => <div key={dia.fecha} className="min-w-[170px] border-r last:border-0"><div className="border-b bg-muted p-3 text-center text-sm font-bold">{etiquetaDiaCorta(dia.fecha)}</div>{(Object.keys(etiquetasTiempoComida) as TiempoComida[]).map((tiempo) => { const celda = dia.celdas[tiempo]; return <div key={tiempo} className="min-h-36 border-b p-3 last:border-0"><p className="mb-2 text-[11px] font-semibold uppercase text-muted-foreground">{etiquetasTiempoComida[tiempo]}</p>{celda && <div className="rounded-lg border bg-card p-2 text-xs shadow-sm"><strong>{celda.platillo_nombre}</strong><p className="mt-1 text-muted-foreground">${celda.precio_venta} · {celda.porciones_maximas} porciones</p></div>}</div> })}</div>)}</div>}</div>
}

function Plans() {
  const planes = useAsync(listarPlanes)
  return <><BloqueEstado variante="tarjetas" cantidad={3} cargando={planes.loading} error={planes.error} vacio={!planes.data?.length} mensajeVacio="Todavía no hay planes configurados." onReintentar={planes.reload} /><div className="grid gap-5 md:grid-cols-3">{planes.data?.map((plan) => <Card key={plan.id} className={plan.destacado ? "border-primary shadow-lg" : ""}><CardHeader><Badge className="w-fit">{plan.destacado ? "Más elegido" : "Plan flexible"}</Badge><CardTitle className="font-display text-2xl">{plan.nombre}</CardTitle></CardHeader><CardContent><p className="mb-5 text-sm text-muted-foreground">{plan.detalle}</p><div className="mb-5 text-4xl font-bold text-primary">{plan.descuento_porcentaje}%<small className="text-sm text-muted-foreground"> descuento</small></div><Button variant="outline" className="w-full">Editar plan</Button></CardContent></Card>)}</div></>
}
