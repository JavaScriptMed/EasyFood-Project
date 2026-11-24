"use client"

import { useMemo, useState } from "react"
import { Car, ChevronDown, MapPin, Package, Pencil, Plus, Power, Search, X } from "lucide-react"
import { toast } from "sonner"

import { AdminShell } from "@/components/admin-shell"
import { BloqueEstado } from "@/components/estados"
import { PageTitle, StatCard } from "@/components/panel"
import { CampoLista, CampoTexto, GrillaDatos, PanelDetalle, VentanaConfirmar, VentanaFormulario } from "@/components/ventanas"
import {
  cambiarDisponibilidadRepartidor, cambiarEstadoRepartidor, crearRepartidor, editarRepartidor, guardarConfiguracionOperacion, listarRepartidores, listarZonas,
  obtenerCapacidadesVehiculo, obtenerConfiguracionOperacion,
} from "@/lib/api"
import { FECHA_OPERACION } from "@/lib/config"
import { etiquetasEstadoRepartidor } from "@/lib/etiquetas"
import { formatFechaLarga, iniciales } from "@/lib/format"
import type { CapacidadDelAuto, EstadoRepartidor, RepartidorDetalle } from "@/lib/types"
import { useAsync } from "@/lib/use-async"

const estiloDeEstado: Record<EstadoRepartidor, string> = {
  libre: "bg-emerald-500/10 text-emerald-700 dark:text-emerald-300",
  asignado: "bg-amber-500/10 text-amber-700 dark:text-amber-300",
  en_ruta: "bg-blue-500/10 text-blue-700 dark:text-blue-300",
  no_disponible: "bg-slate-500/10 text-slate-500",
}
const puntoDeEstado: Record<EstadoRepartidor, string> = { libre: "bg-emerald-500", asignado: "bg-amber-500", en_ruta: "bg-blue-500 animate-pulse", no_disponible: "bg-slate-400" }
const borradorInicial = { nombre: "", correo: "", telefono: "", numero_empleado: "", placas: "", zona_base: "" }
const ESTADOS = ["Todos", "Libre", "Asignado", "En ruta", "No disponible"]

/** Todos los repartidores usan automóvil: cada uno trae sus placas y la capacidad por ruta es la misma. */
export default function RepartidoresPage() {
  const repartidores = useAsync(listarRepartidores)
  const capacidades = useAsync(obtenerCapacidadesVehiculo)
  const zonasActivas = useAsync(listarZonas)
  const nombresDeZonas = useMemo(() => (zonasActivas.data ?? []).filter((z) => z.activo).map((z) => z.nombre).sort((a, b) => a.localeCompare(b, "es")), [zonasActivas.data])
  const equipo = repartidores.data ?? []
  const limites = capacidades.data
  const [estado, setEstado] = useState("Todos")
  const [zona, setZona] = useState("Todas")
  const [vista, setVista] = useState<"tarjetas" | "tabla">("tarjetas")
  const [busqueda, setBusqueda] = useState("")
  const [nuevoAbierto, setNuevoAbierto] = useState(false)
  const [paso, setPaso] = useState(1)
  const [borrador, setBorrador] = useState(borradorInicial)
  const [guardando, setGuardando] = useState(false)
  const [detalleId, setDetalleId] = useState<string | null>(null)
  const [editando, setEditando] = useState(false)
  const [desactivando, setDesactivando] = useState(false)
  const [cambiandoEstado, setCambiandoEstado] = useState(false)
  const [editandoLimites, setEditandoLimites] = useState(false)
  const detalle = equipo.find((r) => r.id === detalleId) ?? null

  const filtrados = useMemo(
    () => equipo.filter((r) => (estado === "Todos" || etiquetasEstadoRepartidor[r.estado] === estado) && (zona === "Todas" || r.zona_base === zona) && r.nombre.toLowerCase().includes(busqueda.toLowerCase())),
    [equipo, estado, zona, busqueda],
  )
  const conteo = {
    libres: equipo.filter((r) => r.estado === "libre").length,
    asignados: equipo.filter((r) => r.estado === "asignado").length,
    enRuta: equipo.filter((r) => r.estado === "en_ruta").length,
    noDisponibles: equipo.filter((r) => r.estado === "no_disponible").length,
  }
  const campo = (clave: keyof typeof borradorInicial) => ({ value: borrador[clave], onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setBorrador((b) => ({ ...b, [clave]: e.target.value })) })

  async function cambiarEstado(repartidor: RepartidorDetalle, activo: boolean) {
    setCambiandoEstado(true)
    try {
      await cambiarEstadoRepartidor(repartidor.id, activo)
      toast.success(activo ? `${repartidor.nombre} quedó activo` : `${repartidor.nombre} quedó inactivo`, { description: activo ? undefined : "Conserva su historial y puedes reactivarlo cuando quieras." })
      setDesactivando(false)
      repartidores.reload({ silent: true })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo cambiar el estado del repartidor")
    } finally {
      setCambiandoEstado(false)
    }
  }

  async function cambiarDisponibilidad(id: string) {
    try {
      await cambiarDisponibilidadRepartidor(id)
      repartidores.reload({ silent: true })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo cambiar la disponibilidad")
    }
  }

  const pasoUnoCompleto = borrador.nombre.trim() && borrador.correo.trim()
  const pasoDosCompleto = borrador.placas.trim() && borrador.zona_base
  async function guardar() {
    setGuardando(true)
    try {
      const nuevo = await crearRepartidor({ ...borrador, tipo_vehiculo: "automovil" })
      toast.success(`${nuevo.nombre} agregado al equipo`)
      setNuevoAbierto(false)
      setBorrador(borradorInicial)
      repartidores.reload({ silent: true })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo guardar el repartidor")
    } finally {
      setGuardando(false)
    }
  }

  return (
    <AdminShell>
      <div className="min-h-screen bg-muted/30 p-5 lg:p-8">
        <div className="mx-auto max-w-[1480px]">
          <div className="mb-7 flex flex-wrap items-end justify-between gap-4 border-b pb-6">
            <PageTitle eyebrow="Operación / Equipo" title="Repartidores" description={`Disponibilidad del equipo para hoy, ${formatFechaLarga(FECHA_OPERACION).replace(/^\S+ /, "")}. Todas las entregas se hacen en automóvil.`} />
            <button onClick={() => { setPaso(1); setNuevoAbierto(true) }} className="flex items-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-bold shadow-sm hover:brightness-95"><Plus className="size-4" /> Nuevo repartidor</button>
          </div>

          <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <StatCard label="Libres" value={repartidores.loading ? "–" : conteo.libres} helper="Puedes asignarlos ahora" />
            <StatCard label="Asignados" value={repartidores.loading ? "–" : conteo.asignados} helper="Con una ruta por salir" />
            <StatCard label="En ruta" value={repartidores.loading ? "–" : conteo.enRuta} helper="Repartiendo en este momento" />
            <StatCard label="No disponibles" value={repartidores.loading ? "–" : conteo.noDisponibles} helper="En descanso o fuera" />
          </div>

          <section aria-label="Capacidad del auto" className="mb-6 flex flex-wrap items-center justify-between gap-4 rounded-xl border bg-card p-5 shadow-sm">
            <div className="flex items-center gap-3">
              <span className="flex size-11 items-center justify-center rounded-full bg-primary/15 text-primary-foreground"><Car className="size-5" /></span>
              <div>
                <h2 className="font-display text-lg font-bold">Capacidad por ruta</h2>
                <p className="text-sm text-muted-foreground">Lo que cabe en un auto. Se usa al generar las rutas y al mover entregas.</p>
              </div>
            </div>
            <BloqueEstado cargando={capacidades.loading} error={capacidades.error} vacio={!limites} mensajeVacio="No hay capacidad configurada." onReintentar={capacidades.reload} cantidad={1} className="min-w-[220px]" />
            {limites && (
              <div className="flex flex-wrap items-center gap-3">
                <div className="rounded-lg border px-4 py-2 text-center"><p className="font-display text-2xl font-bold">{limites.entregas}</p><p className="text-xs text-muted-foreground">entregas (paradas)</p></div>
                <div className="rounded-lg border px-4 py-2 text-center"><p className="font-display text-2xl font-bold">{limites.platillos}</p><p className="text-xs text-muted-foreground">platillos</p></div>
                <button onClick={() => setEditandoLimites(true)} className="text-sm font-semibold text-primary hover:underline">Editar límites</button>
              </div>
            )}
          </section>

          <div className="mb-5 flex flex-wrap items-center gap-2 rounded-xl border bg-card p-3 shadow-sm">
            <div className="relative min-w-[220px] flex-1">
              <Search className="absolute left-3 top-2.5 size-4 text-muted-foreground" />
              <input value={busqueda} onChange={(e) => setBusqueda(e.target.value)} placeholder="Buscar repartidor" aria-label="Buscar repartidor" className="h-9 w-full rounded-md border bg-background pl-9 pr-3 text-sm outline-none focus:ring-2 focus:ring-primary" />
            </div>
            <Filtro etiqueta="Estado" valor={estado} opciones={ESTADOS} onCambio={setEstado} />
            <Filtro etiqueta="Zona" valor={zona} opciones={["Todas", ...nombresDeZonas]} onCambio={setZona} />
            <div className="ml-auto flex rounded-md border p-0.5">
              {(["tarjetas", "tabla"] as const).map((v) => <button key={v} onClick={() => setVista(v)} className={`rounded px-3 py-1.5 text-xs font-semibold capitalize ${vista === v ? "bg-primary" : ""}`}>{v}</button>)}
            </div>
          </div>

          <BloqueEstado variante={vista === "tarjetas" ? "tarjetas" : "lista"} cantidad={3} cargando={repartidores.loading} error={repartidores.error} vacio={!filtrados.length} mensajeVacio="No hay repartidores que coincidan con los filtros." onReintentar={repartidores.reload} />

          {vista === "tarjetas" ? (
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
              {filtrados.map((r) => (
                <article key={r.id} className="rounded-xl border bg-card p-5 shadow-sm">
                  <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                      <div className="flex size-11 items-center justify-center rounded-full bg-primary/20 font-display font-bold text-primary-foreground">{iniciales(r.nombre, 9)}</div>
                      <div><h2 className="font-semibold">{r.nombre}</h2><p className="text-xs text-muted-foreground">{r.numero_empleado}</p></div>
                    </div>
                    <InsigniaEstado estado={r.estado} />
                  </div>
                  <dl className="mt-5 grid grid-cols-2 gap-y-3 text-sm">
                    <Dato titulo="Placas del auto" icono={<Car className="size-3.5 text-muted-foreground" />}>{r.placas ?? "Sin placas"}</Dato>
                    <Dato titulo="Zona base" icono={<MapPin className="size-3.5 text-muted-foreground" />}>{r.zona_base}</Dato>
                    <Dato titulo="Entregas de hoy" icono={<Package className="size-3.5 text-muted-foreground" />}>{r.entregas_asignadas} asignadas · {r.entregas_completadas} listas</Dato>
                    <Dato titulo="Capacidad por ruta">{limites ? `${limites.entregas} entregas · ${limites.platillos} platillos` : "–"}</Dato>
                  </dl>
                  <div className="mt-5 flex items-center justify-between border-t pt-4">
                    <button onClick={() => setDetalleId(r.id)} className="text-sm font-semibold text-primary hover:underline">Ver detalle</button>
                    <label className="flex items-center gap-2 text-xs text-muted-foreground">
                      Disponible
                      <Interruptor activo={r.estado !== "no_disponible"} etiqueta={`Cambiar disponibilidad de ${r.nombre}`} onCambio={() => cambiarDisponibilidad(r.id)} />
                    </label>
                  </div>
                </article>
              ))}
            </div>
          ) : (
            !!filtrados.length && (
              <div className="overflow-x-auto rounded-xl border bg-card shadow-sm">
                <table className="w-full min-w-[850px] text-left text-sm">
                  <thead className="border-b bg-muted/40 text-xs uppercase text-muted-foreground">
                    <tr><th className="px-4 py-3">Repartidor</th><th className="px-4 py-3">Zona base</th><th className="px-4 py-3">Placas</th><th className="px-4 py-3">Estado</th><th className="px-4 py-3">Entregas de hoy</th><th className="px-4 py-3">Disponible</th><th className="px-4 py-3" /></tr>
                  </thead>
                  <tbody>
                    {filtrados.map((r) => (
                      <tr key={r.id} className="border-b last:border-0">
                        <td className="px-4 py-3"><p className="font-semibold">{r.nombre}</p><p className="text-xs text-muted-foreground">{r.numero_empleado}</p></td>
                        <td className="px-4 py-3">{r.zona_base}</td>
                        <td className="px-4 py-3">{r.placas ?? "Sin placas"}</td>
                        <td className="px-4 py-3"><InsigniaEstado estado={r.estado} /></td>
                        <td className="px-4 py-3">{r.entregas_asignadas} asignadas · {r.entregas_completadas} listas</td>
                        <td className="px-4 py-3"><Interruptor activo={r.estado !== "no_disponible"} etiqueta={`Cambiar disponibilidad de ${r.nombre}`} onCambio={() => cambiarDisponibilidad(r.id)} /></td>
                        <td className="px-4 py-3 text-right"><button onClick={() => setDetalleId(r.id)} className="font-semibold text-primary hover:underline">Ver detalle</button></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )
          )}

          {nuevoAbierto && (
            <div className="fixed inset-0 z-50 flex items-center justify-center bg-foreground/40 p-4">
              <div className="w-full max-w-lg rounded-2xl border bg-card p-6 shadow-xl">
                <div className="mb-6 flex items-start justify-between">
                  <div>
                    <p className="text-xs font-bold uppercase tracking-wider text-primary">Paso {paso} de 2</p>
                    <h2 className="mt-1 font-display text-xl font-bold">Nuevo repartidor</h2>
                  </div>
                  <button onClick={() => setNuevoAbierto(false)} aria-label="Cerrar"><X className="size-5" /></button>
                </div>
                <div className="mb-6 flex gap-1">{[1, 2].map((n) => <div key={n} className={`h-1.5 flex-1 rounded-full ${n <= paso ? "bg-primary" : "bg-muted"}`} />)}</div>
                {paso === 1 && (
                  <div className="grid gap-4">
                    <CampoTexto etiqueta="Nombre completo" autoFocus {...campo("nombre")} />
                    <CampoTexto etiqueta="Correo de acceso" type="email" ayuda="Le llega la invitación para crear su contraseña." {...campo("correo")} />
                    <CampoTexto etiqueta="Teléfono" type="tel" {...campo("telefono")} />
                  </div>
                )}
                {paso === 2 && (
                  <div className="grid gap-4">
                    <CampoTexto etiqueta="Número de empleado" placeholder="EMP-0000" {...campo("numero_empleado")} />
                    <div className="flex items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2 text-sm"><Car className="size-4" /> Vehículo: automóvil</div>
                    <CampoTexto etiqueta="Placas del auto" placeholder="JAL-000-A" {...campo("placas")} />
                    <CampoLista etiqueta="Zona base" opciones={[{ valor: "", texto: "Elige una zona" }, ...nombresDeZonas.map((n) => ({ valor: n, texto: n }))]} {...campo("zona_base")} />
                  </div>
                )}
                <div className="mt-7 flex justify-between">
                  <button onClick={() => (paso === 1 ? setNuevoAbierto(false) : setPaso(1))} className="rounded-lg border px-4 py-2 text-sm font-semibold">{paso === 1 ? "Cancelar" : "Atrás"}</button>
                  <button onClick={() => (paso === 2 ? guardar() : setPaso(2))} disabled={guardando || (paso === 1 ? !pasoUnoCompleto : !pasoDosCompleto)} className="rounded-lg bg-primary px-4 py-2 text-sm font-bold disabled:opacity-60">
                    {paso === 2 ? (guardando ? "Guardando…" : "Guardar repartidor") : "Continuar"}
                  </button>
                </div>
              </div>
            </div>
          )}

          {detalle && (
            <PanelDetalle
              etiqueta={`${detalle.numero_empleado} · ${etiquetasEstadoRepartidor[detalle.estado]}`}
              titulo={detalle.nombre}
              onCerrar={() => setDetalleId(null)}
              acciones={
                <>
                  <button onClick={() => setEditando(true)} className="inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-sm font-semibold hover:bg-muted"><Pencil className="size-4" /> Editar repartidor</button>
                  {detalle.activo === false ? (
                    <button onClick={() => cambiarEstado(detalle, true)} disabled={cambiandoEstado} className="inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-sm font-semibold hover:bg-muted disabled:opacity-50"><Power className="size-4" /> Reactivar</button>
                  ) : (
                    <button onClick={() => setDesactivando(true)} className="inline-flex h-9 items-center gap-2 rounded-lg border border-destructive/40 px-3 text-sm font-semibold text-destructive hover:bg-destructive/10"><Power className="size-4" /> Desactivar</button>
                  )}
                </>
              }
            >
              {detalle.activo === false && <p className="mb-4 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">Este repartidor está inactivo: no recibe rutas nuevas.</p>}
              <GrillaDatos
                datos={[
                  { etiqueta: "Estado", valor: etiquetasEstadoRepartidor[detalle.estado] },
                  { etiqueta: "Zona base", valor: detalle.zona_base },
                  { etiqueta: "Vehículo", valor: "Automóvil" },
                  { etiqueta: "Placas", valor: detalle.placas ?? "Sin placas" },
                  { etiqueta: "Capacidad por ruta", valor: limites ? `${limites.entregas} entregas · ${limites.platillos} platillos` : "–" },
                  { etiqueta: "Entregas asignadas", valor: detalle.entregas_asignadas },
                  { etiqueta: "Entregas completadas", valor: detalle.entregas_completadas },
                ]}
              />
            </PanelDetalle>
          )}
          {detalle && editando && <EditarRepartidor repartidor={detalle} onCerrar={() => setEditando(false)} onGuardado={() => { setEditando(false); repartidores.reload({ silent: true }) }} />}
          {detalle && desactivando && (
            <VentanaConfirmar
              titulo={`¿Desactivar a ${detalle.nombre}?`}
              descripcion="Dejará de recibir rutas nuevas. Conserva su historial de entregas y puedes reactivarlo cuando quieras."
              textoConfirmar="Desactivar"
              trabajando={cambiandoEstado}
              onCerrar={() => setDesactivando(false)}
              onConfirmar={() => cambiarEstado(detalle, false)}
            />
          )}
          {editandoLimites && limites && <EditarLimites limites={limites} onCerrar={() => setEditandoLimites(false)} onGuardado={() => { setEditandoLimites(false); capacidades.reload({ silent: true }) }} />}
        </div>
      </div>
    </AdminShell>
  )
}

function Filtro({ etiqueta, valor, opciones, onCambio }: { etiqueta: string; valor: string; opciones: string[]; onCambio: (valor: string) => void }) {
  return (
    <label className="flex h-9 items-center gap-2 rounded-md border px-2 text-xs text-muted-foreground">
      <span>{etiqueta}</span>
      <select value={valor} onChange={(e) => onCambio(e.target.value)} className="bg-transparent font-semibold text-foreground outline-none">
        {opciones.map((o) => <option key={o}>{o}</option>)}
      </select>
      <ChevronDown className="size-3" />
    </label>
  )
}

function InsigniaEstado({ estado }: { estado: EstadoRepartidor }) {
  return (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${estiloDeEstado[estado]}`}>
      <span className={`size-1.5 rounded-full ${puntoDeEstado[estado]}`} />
      {etiquetasEstadoRepartidor[estado]}
    </span>
  )
}

function Dato({ titulo, icono, children }: { titulo: string; icono?: React.ReactNode; children: React.ReactNode }) {
  return <div><dt className="text-xs text-muted-foreground">{titulo}</dt><dd className="mt-1 flex items-center gap-1.5 font-medium">{icono}{children}</dd></div>
}

function Interruptor({ activo, etiqueta, onCambio }: { activo: boolean; etiqueta: string; onCambio: () => void }) {
  return (
    <button type="button" role="switch" aria-checked={activo} aria-label={etiqueta} onClick={onCambio} className={`relative h-6 w-11 rounded-full transition ${activo ? "bg-primary" : "bg-muted"}`}>
      <span className={`absolute top-1 size-4 rounded-full bg-white shadow transition ${activo ? "left-6" : "left-1"}`} />
    </button>
  )
}

/** Editar los datos de un repartidor (nombre, teléfono, número de empleado, placas y zona base). El vehículo siempre es automóvil. */
function EditarRepartidor({ repartidor, onCerrar, onGuardado }: { repartidor: RepartidorDetalle; onCerrar: () => void; onGuardado: () => void }) {
  const [form, setForm] = useState({ nombre: repartidor.nombre, telefono: "", numero_empleado: repartidor.numero_empleado, placas: repartidor.placas ?? "", zona_base: repartidor.zona_base })
  const [guardando, setGuardando] = useState(false)
  const campo = (clave: keyof typeof form) => ({ value: form[clave], onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setForm((f) => ({ ...f, [clave]: e.target.value })) })
  const zonas = useAsync(listarZonas)
  const nombres = (zonas.data ?? []).filter((z) => z.activo).map((z) => z.nombre)
  const opcionesDeZona = [...(nombres.includes(form.zona_base) || !form.zona_base ? [] : [form.zona_base]), ...nombres].map((nombre) => ({ valor: nombre, texto: nombre }))
  const invalido = !form.nombre.trim() || !form.placas.trim()
  async function guardar() {
    setGuardando(true)
    try {
      await editarRepartidor(repartidor.id, { ...form, tipo_vehiculo: "automovil" })
      toast.success("Cambios guardados")
      onGuardado()
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo guardar el repartidor")
    } finally {
      setGuardando(false)
    }
  }
  return (
    <VentanaFormulario titulo={`Editar a ${repartidor.nombre}`} textoEnviar="Guardar cambios" trabajando={guardando} deshabilitarEnvio={invalido} onCerrar={onCerrar} onEnviar={guardar}>
      <CampoTexto etiqueta="Nombre completo" required autoFocus {...campo("nombre")} />
      <div className="grid gap-4 sm:grid-cols-2">
        <CampoTexto etiqueta="Teléfono" type="tel" {...campo("telefono")} />
        <CampoTexto etiqueta="Número de empleado" {...campo("numero_empleado")} />
      </div>
      <CampoTexto etiqueta="Placas del auto" required {...campo("placas")} />
      <CampoLista etiqueta="Zona base" opciones={opcionesDeZona} {...campo("zona_base")} />
    </VentanaFormulario>
  )
}

/** Cuánto cabe en un auto por ruta (paradas y platillos). Las rutas que se generen desde ahora usan estos límites. */
function EditarLimites({ limites, onCerrar, onGuardado }: { limites: CapacidadDelAuto; onCerrar: () => void; onGuardado: () => void }) {
  const [entregas, setEntregas] = useState(String(limites.entregas))
  const [platillos, setPlatillos] = useState(String(limites.platillos))
  const [guardando, setGuardando] = useState(false)
  const esEntero = (v: string) => Number.isInteger(Number(v)) && Number(v) >= 1
  async function guardar() {
    setGuardando(true)
    try {
      // La capacidad en platillos vive en la configuración de operación: se guarda completa con los dos valores nuevos.
      const config = await obtenerConfiguracionOperacion()
      await guardarConfiguracionOperacion({ ...config, capacidad_auto_entregas: Number(entregas), capacidad_auto_platillos: Number(platillos) })
      toast.success("Límites actualizados")
      onGuardado()
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudieron guardar los límites")
    } finally {
      setGuardando(false)
    }
  }
  return (
    <VentanaFormulario titulo="Capacidad por ruta" descripcion="Lo máximo que lleva un auto en una ruta. Aplica a las rutas que se generen desde ahora." textoEnviar="Guardar límites" trabajando={guardando} deshabilitarEnvio={!esEntero(entregas) || !esEntero(platillos)} onCerrar={onCerrar} onEnviar={guardar}>
      <CampoTexto etiqueta="Entregas (paradas) por ruta" type="number" min={1} step={1} value={entregas} onChange={(e) => setEntregas(e.target.value)} />
      <CampoTexto etiqueta="Platillos por ruta" type="number" min={1} step={1} value={platillos} onChange={(e) => setPlatillos(e.target.value)} />
    </VentanaFormulario>
  )
}
