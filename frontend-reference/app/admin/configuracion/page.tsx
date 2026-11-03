"use client"

import { PageTitle } from "@/components/panel"
import { useEffect, useState } from "react"
import { toast } from "sonner"
import { ChevronLeft, ChevronRight, Download, Lock } from "lucide-react"
import { AdminShell } from "@/components/admin-shell"
import { ErrorEstado } from "@/components/estados"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Skeleton } from "@/components/ui/skeleton"
import { FilasEstado } from "@/components/estados"
import { UsuariosAcceso } from "@/components/usuarios-acceso"
import { cerrarEmpresaDia, guardarConfiguracionOperacion, listarActoresBitacora, listarBitacora, listarEmpresas, marcarDiaSinServicio, obtenerCalendarioServicio, obtenerConfiguracionOperacion, quitarDiaSinServicio } from "@/lib/api"
import { FECHA_HOY, MES_ACTUAL } from "@/lib/config"
import { downloadCSV } from "@/lib/export"
import { etiquetasEntidad, etiquetasRol } from "@/lib/etiquetas"
import { etiquetaDia, etiquetaMes, formatDate, formatHora12, horaDe } from "@/lib/format"
import type { ConfiguracionOperacion, DiaSinServicioDetalle, DiasEntrega, ReglaSinEleccion, Rol } from "@/lib/types"
import { useAsync } from "@/lib/use-async"

const zonasHorarias = ["America/Mexico_City", "America/Cancun", "America/Monterrey", "America/Tijuana"]
const opcionesDias: { valor: DiasEntrega; etiqueta: string }[] = [{ valor: "lunes_viernes", etiqueta: "Lunes a viernes" }, { valor: "lunes_sabado", etiqueta: "Lunes a sábado" }]
const opcionesRegla: { valor: ReglaSinEleccion; etiqueta: string; detalle: string }[] = [
  { valor: "ultimo_platillo", etiqueta: "Asignar su último platillo", detalle: "Si ya no hay cupo o no tiene historial, recibe el platillo del día." },
  { valor: "platillo_del_dia", etiqueta: "Asignar el platillo del día", detalle: "Todos los que no eligieron reciben el primer platillo con cupo." },
  { valor: "no_entregar", etiqueta: "No entregar", detalle: "Quien no eligió antes del corte se queda sin comida ese día." },
]

const aFormulario = (c: ConfiguracionOperacion) => ({ ...c, capacidad_auto_entregas: String(c.capacidad_auto_entregas), capacidad_auto_platillos: String(c.capacidad_auto_platillos) })

export default function ConfiguracionPage() {
  const [tab, setTab] = useState<"operacion" | "usuarios" | "bitacora">("operacion")
  return <AdminShell active="Configuración">
    <div className="min-h-screen bg-background p-5 md:p-8"><div className="mx-auto max-w-5xl">
      <div className="mb-6 border-b pb-6"><PageTitle eyebrow="Panel" title="Configuración" description="Reglas generales con las que opera EasyLunch." /></div>
      <div className="mb-6 flex gap-2 border-b text-sm" role="tablist">
        {([["operacion", "Operación"], ["usuarios", "Usuarios"], ["bitacora", "Bitácora"]] as const).map(([id, etiqueta]) => <button key={id} role="tab" aria-selected={tab === id} onClick={() => setTab(id)} className={`px-4 py-3 font-semibold ${tab === id ? "border-b-2 border-primary text-foreground" : "text-muted-foreground hover:text-foreground"}`}>{etiqueta}</button>)}
      </div>
      {tab === "operacion" && <OperacionTab />}
      {tab === "usuarios" && <Card><CardHeader><CardTitle>Usuarios del panel</CardTitle><CardDescription>Administradores y personal de soporte, con el estado de su invitación.</CardDescription></CardHeader><CardContent><UsuariosAcceso organizacion={{ tipo: "plataforma", id: null }} roles={["administrador", "soporte"]} /></CardContent></Card>}
      {tab === "bitacora" && <BitacoraTab />}
    </div></div>
  </AdminShell>
}

function OperacionTab() {
  const config = useAsync(obtenerConfiguracionOperacion)
  const [form, setForm] = useState<ReturnType<typeof aFormulario> | null>(null)
  const [saving, setSaving] = useState(false)

  useEffect(() => { if (config.data) setForm(aFormulario(config.data)) }, [config.data])

  if (config.error) return <ErrorEstado error={config.error} onReintentar={config.reload} className="rounded-xl border bg-card" />
  if (!form || !config.data) return <div className="flex flex-col gap-5" aria-hidden="true"><Skeleton className="h-96 rounded-xl" /><Skeleton className="h-96 rounded-xl" /></div>

  const dirty = JSON.stringify(form) !== JSON.stringify(aFormulario(config.data))
  const cambiar = <K extends keyof typeof form>(clave: K, valor: (typeof form)[K]) => setForm((f) => (f ? { ...f, [clave]: valor } : f))

  async function guardar() {
    if (!form) return
    setSaving(true)
    try {
      const guardada = await guardarConfiguracionOperacion({ ...form, capacidad_auto_entregas: Number(form.capacidad_auto_entregas), capacidad_auto_platillos: Number(form.capacidad_auto_platillos) })
      config.setData(guardada)
      toast.success("Configuración guardada")
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo guardar la configuración")
    } finally {
      setSaving(false)
    }
  }

  return <div className="flex flex-col gap-5">
    <Card>
      <CardHeader><CardTitle>Corte y entregas</CardTitle><CardDescription>Cuándo se cierran los pedidos y cómo se reparten.</CardDescription></CardHeader>
      <CardContent className="flex flex-col gap-7">
        <div className="grid gap-5 sm:grid-cols-2">
          <label className="text-sm font-medium">Hora de corte<Input type="time" value={form.hora_corte} onChange={(e) => cambiar("hora_corte", e.target.value)} className="mt-1" /><span className="mt-1 block text-xs font-normal text-muted-foreground">{formatHora12(form.hora_corte)} · por defecto 6:00 p. m.</span></label>
          <label className="text-sm font-medium">Zona horaria<select value={form.zona_horaria} onChange={(e) => cambiar("zona_horaria", e.target.value)} className="mt-1 h-8 w-full rounded-lg border bg-background px-2.5 text-sm">{zonasHorarias.map((z) => <option key={z}>{z}</option>)}</select></label>
        </div>

        <fieldset><legend className="mb-2 text-sm font-medium">Días de entrega</legend>
          <div className="flex flex-wrap gap-2" role="radiogroup">{opcionesDias.map((o) => <button key={o.valor} role="radio" aria-checked={form.dias_entrega === o.valor} onClick={() => cambiar("dias_entrega", o.valor)} className={`rounded-lg border px-4 py-2 text-sm font-semibold transition ${form.dias_entrega === o.valor ? "border-primary bg-primary/10" : "text-muted-foreground hover:bg-muted"}`}>{o.etiqueta}</button>)}</div>
        </fieldset>

        <fieldset><legend className="mb-2 text-sm font-medium">Cuando el empleado no elige</legend>
          <div className="grid gap-3 md:grid-cols-3" role="radiogroup">{opcionesRegla.map((o) => <button key={o.valor} role="radio" aria-checked={form.regla_sin_eleccion === o.valor} onClick={() => cambiar("regla_sin_eleccion", o.valor)} className={`rounded-xl border p-4 text-left transition ${form.regla_sin_eleccion === o.valor ? "border-primary bg-primary/10" : "hover:bg-muted"}`}><p className="text-sm font-semibold">{o.etiqueta}</p><p className="mt-1 text-xs text-muted-foreground">{o.detalle}</p></button>)}</div>
        </fieldset>

        <fieldset><legend className="mb-2 text-sm font-medium">Capacidad del auto</legend>
          <div className="grid gap-5 sm:grid-cols-2">
            <label className="text-sm">Entregas por ruta<Input type="number" min={1} value={form.capacidad_auto_entregas} onChange={(e) => cambiar("capacidad_auto_entregas", e.target.value)} className="mt-1" /></label>
            <label className="text-sm">Platillos por ruta<Input type="number" min={1} value={form.capacidad_auto_platillos} onChange={(e) => cambiar("capacidad_auto_platillos", e.target.value)} className="mt-1" /></label>
          </div>
          <p className="mt-2 text-xs text-muted-foreground">Al crear rutas, cada auto se llena hasta el primero de los dos límites.</p>
        </fieldset>

        <div className="flex items-center justify-end gap-3 border-t pt-5">
          {dirty && <Button variant="ghost" onClick={() => setForm(aFormulario(config.data!))}>Descartar</Button>}
          <Button onClick={guardar} disabled={!dirty || saving}>{saving ? "Guardando…" : "Guardar cambios"}</Button>
        </div>
      </CardContent>
    </Card>
    <CalendarioServicio diasEntrega={config.data.dias_entrega} />
  </div>
}

const desplazarMes = (mes: string, delta: number) => {
  const [y, m] = mes.split("-").map(Number)
  return new Date(Date.UTC(y, m - 1 + delta, 1)).toISOString().slice(0, 7)
}

const estilosDia = { festivo: "border-amber-500/40 bg-amber-500/10", sin_servicio: "border-destructive/40 bg-destructive/10" } as const

function CalendarioServicio({ diasEntrega }: { diasEntrega: DiasEntrega }) {
  const [mes, setMes] = useState(MES_ACTUAL)
  const [seleccion, setSeleccion] = useState<string | null>(null)
  const calendario = useAsync(() => obtenerCalendarioServicio(mes), [mes])

  const [y, m] = mes.split("-").map(Number)
  const primero = (new Date(Date.UTC(y, m - 1, 1)).getUTCDay() + 6) % 7
  const total = new Date(Date.UTC(y, m, 0)).getUTCDate()
  const celdas = [...Array(primero).fill(null), ...Array.from({ length: total }, (_, i) => `${mes}-${String(i + 1).padStart(2, "0")}`)]
  const delDia = (fecha: string) => calendario.data?.dias.filter((d) => d.fecha === fecha) ?? []

  return <Card>
    <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
      <div><CardTitle>Días de servicio</CardTitle><CardDescription>Los festivos y días sin servicio no generan pedidos. También puedes cerrar una empresa un día específico.</CardDescription></div>
      <div className="flex items-center gap-2">
        <Button variant="outline" size="icon" aria-label="Mes anterior" onClick={() => { setSeleccion(null); setMes(desplazarMes(mes, -1)) }}><ChevronLeft /></Button>
        <span className="min-w-36 text-center text-sm font-semibold">{etiquetaMes(mes)}</span>
        <Button variant="outline" size="icon" aria-label="Mes siguiente" onClick={() => { setSeleccion(null); setMes(desplazarMes(mes, 1)) }}><ChevronRight /></Button>
      </div>
    </CardHeader>
    <CardContent>
      {calendario.error ? <ErrorEstado error={calendario.error} onReintentar={calendario.reload} /> : (
        <div className="overflow-x-auto"><div className="min-w-[640px]">
          <div className="mb-2 grid grid-cols-7 gap-2 text-center text-xs font-semibold uppercase text-muted-foreground">{["Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom"].map((d) => <span key={d}>{d}</span>)}</div>
          <div className="grid grid-cols-7 gap-2">
            {calendario.loading ? Array.from({ length: 35 }, (_, i) => <Skeleton key={i} className="h-20 rounded-lg" />) : celdas.map((fecha, i) => {
              if (!fecha) return <div key={`vacio-${i}`} />
              const dia = Number(fecha.slice(8))
              const marcas = delDia(fecha)
              const global = marcas.find((d) => d.tipo !== "cierre_empresa")
              const cierres = marcas.filter((d) => d.tipo === "cierre_empresa")
              const dow = new Date(`${fecha}T00:00:00Z`).getUTCDay()
              const noEntrega = dow === 0 || (dow === 6 && diasEntrega === "lunes_viernes")
              const cerrada = calendario.data?.fechas_cerradas.includes(fecha)
              return <button key={fecha} onClick={() => setSeleccion(fecha)} aria-label={etiquetaDia(fecha)} aria-pressed={seleccion === fecha} className={`flex min-h-20 flex-col gap-1 rounded-lg border p-2 text-left text-xs transition hover:border-primary ${global ? estilosDia[global.tipo as "festivo" | "sin_servicio"] : noEntrega ? "bg-muted/50 text-muted-foreground" : "bg-card"} ${seleccion === fecha ? "ring-2 ring-primary" : ""}`}>
                <span className="flex items-center justify-between"><span className={`font-bold ${fecha === FECHA_HOY ? "grid size-5 place-items-center rounded-full bg-primary text-primary-foreground" : ""}`}>{dia}</span>{cerrada && <Lock className="size-3 text-muted-foreground" aria-label="Corte ejecutado" />}</span>
                {global && <span className="line-clamp-2 font-medium leading-tight">{global.motivo}</span>}
                {cierres.length > 0 && <span className="rounded bg-foreground/10 px-1.5 py-0.5 text-[10px] font-semibold">{cierres.length} {cierres.length === 1 ? "empresa cerrada" : "empresas cerradas"}</span>}
              </button>
            })}
          </div>
        </div></div>
      )}
      <div className="mt-4 flex flex-wrap gap-4 text-xs text-muted-foreground">
        <span className="flex items-center gap-1.5"><span className="size-3 rounded border border-amber-500/40 bg-amber-500/10" /> Festivo</span>
        <span className="flex items-center gap-1.5"><span className="size-3 rounded border border-destructive/40 bg-destructive/10" /> Sin servicio</span>
        <span className="flex items-center gap-1.5"><span className="size-3 rounded border bg-muted/50" /> No es día de entrega</span>
        <span className="flex items-center gap-1.5"><Lock className="size-3" /> Corte ya ejecutado</span>
      </div>
      {seleccion && calendario.data && <DetalleDia key={seleccion} fecha={seleccion} marcas={delDia(seleccion)} cerrada={calendario.data.fechas_cerradas.includes(seleccion)} onCambio={() => calendario.reload({ silent: true })} onCerrar={() => setSeleccion(null)} />}
    </CardContent>
  </Card>
}

function DetalleDia({ fecha, marcas, cerrada, onCambio, onCerrar }: { fecha: string; marcas: DiaSinServicioDetalle[]; cerrada: boolean; onCambio: () => void; onCerrar: () => void }) {
  const global = marcas.find((d) => d.tipo !== "cierre_empresa")
  const cierres = marcas.filter((d) => d.tipo === "cierre_empresa")
  const editable = fecha > FECHA_HOY && !cerrada
  const [tipo, setTipo] = useState<"normal" | "festivo" | "sin_servicio">(global ? (global.tipo as "festivo" | "sin_servicio") : "normal")
  const [motivo, setMotivo] = useState(global?.motivo ?? "")
  const [empresaId, setEmpresaId] = useState("")
  const [motivoEmpresa, setMotivoEmpresa] = useState("")
  const [trabajando, setTrabajando] = useState(false)
  const empresas = useAsync(listarEmpresas)
  const cerradasIds = new Set(cierres.map((c) => c.empresa_id))
  const disponibles = (empresas.data ?? []).filter((e) => !cerradasIds.has(e.id))

  async function ejecutar(accion: () => Promise<unknown>, exito: string) {
    setTrabajando(true)
    try {
      await accion()
      toast.success(exito)
      onCambio()
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo guardar el cambio")
    } finally {
      setTrabajando(false)
    }
  }

  const aplicar = () => ejecutar(
    () => (tipo === "normal" ? (global ? quitarDiaSinServicio(global.id) : Promise.resolve()) : marcarDiaSinServicio({ fecha, tipo, motivo })),
    tipo === "normal" ? "Día restablecido a servicio normal" : "Día actualizado",
  )

  return <div className="mt-5 rounded-xl border bg-muted/30 p-5">
    <div className="flex items-start justify-between gap-3"><div><h3 className="font-display text-lg font-bold">{etiquetaDia(fecha)}</h3>{!editable && <p className="mt-1 text-xs text-muted-foreground">{cerrada ? "El corte de este día ya se ejecutó." : fecha <= FECHA_HOY ? "Este día ya pasó." : ""} Solo se puede consultar.</p>}</div><Button variant="ghost" size="sm" onClick={onCerrar}>Cerrar</Button></div>

    <div className="mt-4 grid gap-6 lg:grid-cols-2">
      <div className="flex flex-col gap-3">
        <p className="text-sm font-semibold">Servicio de este día</p>
        <div className="flex flex-wrap gap-2" role="radiogroup">{([["normal", "Servicio normal"], ["festivo", "Festivo"], ["sin_servicio", "Sin servicio"]] as const).map(([valor, etiqueta]) => <button key={valor} role="radio" aria-checked={tipo === valor} disabled={!editable} onClick={() => setTipo(valor)} className={`rounded-lg border px-3 py-1.5 text-sm font-semibold transition disabled:opacity-60 ${tipo === valor ? "border-primary bg-primary/10" : "bg-card hover:bg-muted"}`}>{etiqueta}</button>)}</div>
        {tipo !== "normal" && <Input value={motivo} disabled={!editable} onChange={(e) => setMotivo(e.target.value)} placeholder="Motivo (ej. Día del Trabajo)" aria-label="Motivo" />}
        <div><Button onClick={aplicar} disabled={!editable || trabajando || (tipo === "normal" && !global) || (tipo !== "normal" && !motivo.trim())}>Aplicar</Button></div>
      </div>

      <div className="flex flex-col gap-3">
        <p className="text-sm font-semibold">Empresas cerradas este día</p>
        {cierres.length === 0 && <p className="text-sm text-muted-foreground">Ninguna empresa cerrada.</p>}
        {cierres.map((c) => <div key={c.id} className="flex items-center justify-between gap-3 rounded-lg border bg-card px-3 py-2 text-sm"><div><p className="font-semibold">{c.empresa_nombre}</p><p className="text-xs text-muted-foreground">{c.motivo}</p></div>{editable && <Button variant="outline" size="sm" disabled={trabajando} onClick={() => ejecutar(() => quitarDiaSinServicio(c.id), "Empresa reabierta")}>Reabrir</Button>}</div>)}
        {editable && <div className="flex flex-col gap-2 border-t pt-3">
          <select value={empresaId} onChange={(e) => setEmpresaId(e.target.value)} disabled={empresas.loading} aria-label="Empresa a cerrar" className="h-8 rounded-lg border bg-background px-2.5 text-sm"><option value="">{empresas.loading ? "Cargando empresas…" : "Elige una empresa"}</option>{disponibles.map((e) => <option key={e.id} value={e.id}>{e.nombre}</option>)}</select>
          {empresas.error && <p role="alert" className="text-xs text-destructive">No se pudieron cargar las empresas. <button type="button" onClick={() => empresas.reload()} className="font-semibold underline">Reintentar</button></p>}
          <Input value={motivoEmpresa} onChange={(e) => setMotivoEmpresa(e.target.value)} placeholder="Motivo (ej. Inventario anual)" aria-label="Motivo del cierre" />
          <div><Button variant="outline" disabled={!empresaId || !motivoEmpresa.trim() || trabajando} onClick={() => ejecutar(async () => { await cerrarEmpresaDia({ fecha, empresa_id: empresaId, motivo: motivoEmpresa }); setEmpresaId(""); setMotivoEmpresa("") }, "Empresa cerrada ese día")}>Cerrar empresa este día</Button></div>
        </div>}
      </div>
    </div>
  </div>
}

const roles = Object.keys(etiquetasRol) as Rol[]
const fechaHora = (iso: string) => `${formatDate(iso.slice(0, 10))} · ${horaDe(iso)} h`

function BitacoraTab() {
  const [usuarioId, setUsuarioId] = useState("")
  const [rol, setRol] = useState<Rol | "">("")
  const [desde, setDesde] = useState("")
  const [hasta, setHasta] = useState("")
  const actores = useAsync(listarActoresBitacora)
  const registros = useAsync(() => listarBitacora({ usuario_id: usuarioId || undefined, rol: rol || undefined, desde: desde || undefined, hasta: hasta || undefined }), [usuarioId, rol, desde, hasta])
  const filas = registros.data ?? []
  const hayFiltros = !!(usuarioId || rol || desde || hasta)

  function exportar() {
    downloadCSV(`bitacora-${desde || "inicio"}-${hasta || "hoy"}.csv`, [
      ["Quién", "Rol", "Acción", "Entidad", "Fecha"],
      ...filas.map((r) => [r.usuario_nombre ?? "Sistema", r.usuario_rol ? etiquetasRol[r.usuario_rol] : "—", r.detalle, `${etiquetasEntidad[r.entidad] ?? r.entidad} ${r.entidad_id}`, `${r.creado_en.slice(0, 10)} ${horaDe(r.creado_en)}`]),
    ])
  }

  const campo = "mt-1 h-9 w-full rounded-lg border bg-background px-2.5 text-sm"
  return <Card>
    <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
      <div><CardTitle>Bitácora</CardTitle><CardDescription>Quién hizo qué en el panel, con su fecha y hora.</CardDescription></div>
      <Button variant="outline" className="h-10 px-4" onClick={exportar} disabled={!filas.length}><Download /> Exportar CSV</Button>
    </CardHeader>
    <CardContent className="flex flex-col gap-5">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <label className="text-xs font-medium text-muted-foreground">Usuario<select value={usuarioId} onChange={(e) => setUsuarioId(e.target.value)} className={campo}><option value="">Todos</option>{actores.data?.map((a) => <option key={a.id} value={a.id}>{a.nombre}</option>)}</select></label>
        <label className="text-xs font-medium text-muted-foreground">Rol<select value={rol} onChange={(e) => setRol(e.target.value as Rol | "")} className={campo}><option value="">Todos</option>{roles.map((r) => <option key={r} value={r}>{etiquetasRol[r]}</option>)}</select></label>
        <label className="text-xs font-medium text-muted-foreground">Desde<input type="date" value={desde} max={hasta || undefined} onChange={(e) => setDesde(e.target.value)} className={campo} /></label>
        <label className="text-xs font-medium text-muted-foreground">Hasta<input type="date" value={hasta} min={desde || undefined} onChange={(e) => setHasta(e.target.value)} className={campo} /></label>
        <div className="flex items-end"><Button variant="ghost" className="h-9 px-3" disabled={!hayFiltros} onClick={() => { setUsuarioId(""); setRol(""); setDesde(""); setHasta("") }}>Limpiar filtros</Button></div>
      </div>
      <div className="overflow-hidden rounded-xl border"><div className="max-h-[560px] overflow-auto">
        <table className="w-full min-w-[820px] text-left text-sm">
          <thead className="sticky top-0 border-b bg-muted text-xs uppercase text-muted-foreground"><tr><th className="px-5 py-3.5">Quién</th><th className="px-5 py-3.5">Rol</th><th className="px-5 py-3.5">Acción</th><th className="px-5 py-3.5">Entidad</th><th className="px-5 py-3.5">Fecha</th></tr></thead>
          <tbody>
            <FilasEstado columnas={5} filas={6} cargando={registros.loading} error={registros.error} vacio={!filas.length} mensajeVacio={hayFiltros ? "No hay acciones que coincidan con los filtros." : "Todavía no hay acciones registradas."} onReintentar={registros.reload} />
            {filas.map((r) => (
              <tr key={r.id} className="border-b last:border-0">
                <td className="px-5 py-3.5 font-semibold">{r.usuario_nombre ?? "Sistema"}</td>
                <td className="px-5 py-3.5 text-muted-foreground">{r.usuario_rol ? etiquetasRol[r.usuario_rol] : "—"}</td>
                <td className="px-5 py-3.5">{r.detalle}</td>
                <td className="px-5 py-3.5"><span className="rounded-md bg-muted px-2 py-1 text-xs font-medium">{etiquetasEntidad[r.entidad] ?? r.entidad}</span> <span className="font-mono text-xs text-muted-foreground">{r.entidad_id}</span></td>
                <td className="whitespace-nowrap px-5 py-3.5 tabular-nums text-muted-foreground">{fechaHora(r.creado_en)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div></div>
      {!registros.loading && !registros.error && <p className="text-xs text-muted-foreground">{filas.length} {filas.length === 1 ? "registro" : "registros"}</p>}
    </CardContent>
  </Card>
}
