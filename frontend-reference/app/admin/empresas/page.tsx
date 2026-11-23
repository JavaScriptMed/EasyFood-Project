"use client"

import { PageTitle } from "@/components/panel"
import { useEffect, useState } from "react"
import { ChevronLeft, ChevronRight, Pencil, Power, X } from "lucide-react"
import { toast } from "sonner"
import { AdminShell } from "@/components/admin-shell"
import { BloqueEstado, FilasEstado } from "@/components/estados"
import { componerDireccion, datosDeDireccionGuardada, PUNTO_VACIO, SelectorDePunto, type DatosDePunto } from "@/components/selector-de-punto"
import { UsuariosAcceso } from "@/components/usuarios-acceso"
import { CampoTexto, VentanaConfirmar, VentanaFormulario } from "@/components/ventanas"
import { BadgeVencimiento, DIAS_POR_VENCER, FiltroPorVencer, RenovarContrato } from "@/components/contrato-vencimiento"
import { agregarEmpleados, cambiarEstadoEmpresa, consumoDiarioEmpresa, crearEmpresa, editarEmpresa, listarEmpleados, listarEmpresas, renovarContratoEmpresa } from "@/lib/api"
import { MES_ACTUAL } from "@/lib/config"
import { etiquetaDiaCorta, formatDate, mesesEntre } from "@/lib/format"
import { cargarZonasConPoligonos, type ZonaConPoligono } from "@/lib/geocodificar-mapbox"
import { ErrorApi } from "@/lib/http"
import type { EdicionEmpresa, EmpresaDetalle } from "@/lib/types"
import { useAsync } from "@/lib/use-async"
import { ARCHIVOS_DE_EMPLEADOS, correoValido, leerArchivoDeEmpleados, MOTIVOS_DE_OMISION } from "@/lib/empleados-archivo"

const borradorInicial = { nombre: "", contacto_rrhh: "", vigencia_inicio: "", compra_minima_mensual: "", porcentaje_subsidio: "", horario_entrega: "" }

export default function EmpresasPage() {
  const empresas = useAsync(listarEmpresas)
  const zonasMapa = useAsync<ZonaConPoligono[]>(() => cargarZonasConPoligonos())
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [tab, setTab] = useState<PestanaEmpresa>("Resumen")
  useEffect(() => setTab("Resumen"), [selectedId])
  const [soloPorVencer, setSoloPorVencer] = useState(false)
  const selected: EmpresaDetalle | null = empresas.data?.find((c) => c.id === selectedId) ?? null
  const setSelected = (empresa: { id: string } | null) => setSelectedId(empresa?.id ?? null)
  const porVencer = empresas.data?.filter((c) => c.dias_para_vencer <= DIAS_POR_VENCER) ?? []
  const visibles = soloPorVencer ? porVencer : empresas.data ?? []
  const [newOpen, setNewOpen] = useState(false)
  const [editando, setEditando] = useState(false)
  const [desactivando, setDesactivando] = useState(false)
  const [cambiandoEstado, setCambiandoEstado] = useState(false)
  const [step, setStep] = useState(1)
  const [subsidy, setSubsidy] = useState(60)
  const [draft, setDraft] = useState(borradorInicial)
  const [errorCobertura, setErrorCobertura] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)
  // Paso 3: el punto de entrega (las coordenadas solo viven aquí; nunca se muestran) y el motivo por el que todavía no se puede continuar.
  const [punto, setPunto] = useState<DatosDePunto>(PUNTO_VACIO)
  const [bloqueoPunto, setBloqueoPunto] = useState<string | null>(null)
  const cambiarPunto = (parche: Partial<DatosDePunto>) => {
    if ("latitud" in parche) setErrorCobertura(null)
    setPunto((p) => ({ ...p, ...parche }))
  }
  // Paso 4: los empleados se arman aquí y se mandan al crear la empresa.
  const [personas, setPersonas] = useState<{ nombre: string; correo: string }[]>([])
  const [nuevoEmpleado, setNuevoEmpleado] = useState<{ nombre: string; correo: string } | null>(null)
  async function cargarArchivoDeEmpleados(input: HTMLInputElement) {
    const archivo = input.files?.[0]
    input.value = ""
    if (!archivo) return
    let leidos: Awaited<ReturnType<typeof leerArchivoDeEmpleados>>
    try {
      leidos = await leerArchivoDeEmpleados(archivo)
    } catch (error) {
      return void toast.error(error instanceof Error ? error.message : "No se pudo leer el archivo", { duration: 12000 })
    }
    const buenos = leidos.filter((p) => !p.correo || correoValido(p.correo))
    const malos = leidos.filter((p) => p.correo && !correoValido(p.correo))
    if (!leidos.length) return void toast.error("El archivo no tiene empleados. Usa la columna A para el nombre y la B para el correo (el correo es opcional).")
    setPersonas((actuales) => [...actuales, ...buenos])
    if (malos.length) toast.warning(`${malos.length} fila(s) tienen un correo mal escrito y no se cargaron`, { description: malos.slice(0, 5).map((p) => `${p.nombre}: ${p.correo}`).join(" · ") + (malos.length > 5 ? " …" : ""), duration: 12000 })
    else toast.success(`${buenos.length} empleado(s) listos para agregar`, { description: "Se agregarán al crear la empresa." })
  }
  function añadirEmpleado() {
    if (!nuevoEmpleado) return
    const nombre = nuevoEmpleado.nombre.trim()
    const correo = nuevoEmpleado.correo.trim()
    if (nombre.length < 2) return void toast.error("Escribe el nombre del empleado.")
    if (correo && !correoValido(correo)) return void toast.error("Ese correo no parece válido.")
    setPersonas((actuales) => [...actuales, { nombre, correo }])
    setNuevoEmpleado(null)
  }
  const campo = (clave: keyof typeof borradorInicial) => ({
    value: draft[clave],
    onChange: (e: React.ChangeEvent<HTMLInputElement>) => setDraft((d) => ({ ...d, [clave]: e.target.value })),
  })
  async function crear() {
    if (punto.latitud === null || punto.longitud === null) {
      const msg = "Elige el punto de entrega: busca la dirección o haz clic en el mapa."
      setErrorCobertura(msg)
      setStep(3)
      toast.error(msg)
      return
    }
    setSaving(true)
    setErrorCobertura(null)
    try {
      const empresa = await crearEmpresa({
        nombre: draft.nombre,
        contacto_rrhh: draft.contacto_rrhh,
        vigencia_inicio: draft.vigencia_inicio,
        compra_minima_mensual: Number(draft.compra_minima_mensual) || 0,
        porcentaje_subsidio: Number(draft.porcentaje_subsidio) || 0,
        direccion_entrega: componerDireccion(punto),
        latitud: punto.latitud,
        longitud: punto.longitud,
        horario_entrega: draft.horario_entrega,
      })
      toast.success(`${empresa.nombre} creada (zona ${empresa.zona})`)
      if (personas.length) {
        try {
          const { agregados, omitidos, correos_omitidos = [] } = await agregarEmpleados(empresa.id, personas)
          if (omitidos === 0) toast.success(`${agregados} empleado(s) agregados a ${empresa.nombre}`, { description: "Cada uno recibe una invitación para crear su contraseña." })
          else toast.warning(agregados ? `${agregados} agregados, pero ${omitidos} se omitieron` : `No se agregó a nadie: se omitieron ${omitidos}`, {
            duration: 20000,
            description: <ul className="mt-1 space-y-0.5">{correos_omitidos.slice(0, 8).map((o, i) => <li key={`${o.correo}-${i}`}><strong className="break-all">{o.correo}</strong> — {MOTIVOS_DE_OMISION[o.motivo]}</li>)}{correos_omitidos.length > 8 && <li>y {correos_omitidos.length - 8} más…</li>}</ul>,
          })
        } catch (error) {
          toast.warning(`${empresa.nombre} se creó, pero los empleados no se pudieron agregar`, { description: (error instanceof Error ? error.message : "Error desconocido") + " Pídele a su contacto de RR. HH. que los suba desde su portal.", duration: 15000 })
        }
      }
      setNewOpen(false)
      setPersonas([])
      setNuevoEmpleado(null)
      setStep(1)
      setDraft(borradorInicial)
      setPunto(PUNTO_VACIO)
      setErrorCobertura(null)
      empresas.reload({ silent: true })
    } catch (error) {
      const mensaje = error instanceof Error ? error.message : "No se pudo crear la empresa"
      const esErrorDeCobertura =
        error instanceof ErrorApi &&
        error.estado === 422 &&
        (error.codigo === "fuera_de_cobertura" || Boolean(error.errores?.latitud || error.errores?.longitud))
      if (esErrorDeCobertura) {
        setErrorCobertura(mensaje)
        setStep(3)
      }
      toast.error(mensaje)
    } finally {
      setSaving(false)
    }
  }
  async function cambiarEstado(empresa: EmpresaDetalle, activo: boolean) {
    setCambiandoEstado(true)
    try {
      await cambiarEstadoEmpresa(empresa.id, activo)
      toast.success(activo ? `${empresa.nombre} quedó activa` : `${empresa.nombre} quedó inactiva`, { description: activo ? undefined : "Conserva su historial y puedes reactivarla cuando quieras." })
      setDesactivando(false)
      empresas.reload({ silent: true })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo cambiar el estado de la empresa")
    } finally {
      setCambiandoEstado(false)
    }
  }
  return <AdminShell active="Empresas y contratos"><div className="min-h-screen bg-background p-5 md:p-8"><div className="mx-auto max-w-7xl"><div className="mb-7 flex flex-wrap items-end justify-between gap-4 border-b pb-6"><div><PageTitle eyebrow="Clientes corporativos" title="Empresas y contratos" description="Subsidios, consumo y entregas de cada cuenta." /></div><button onClick={() => { setNewOpen(true); setStep(1) }} className="rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold">Nueva empresa</button></div><div className="mb-4 flex flex-wrap items-center gap-2"><FiltroPorVencer activo={soloPorVencer} onChange={setSoloPorVencer} cantidad={empresas.data ? porVencer.length : undefined} /></div><div className="overflow-hidden rounded-xl border bg-card"><div className="overflow-x-auto"><table className="w-full min-w-[1100px] text-left text-sm"><thead className="border-b bg-muted/40 text-xs uppercase text-muted-foreground"><tr>{["Empresa", "RR. HH.", "Empleados", "Subsidio", "Mínimo mensual", "Consumo del mes", "Vigencia", "Estado"].map((x) => <th key={x} className="px-5 py-3">{x}</th>)}</tr></thead><tbody><FilasEstado columnas={8} cargando={empresas.loading} error={empresas.error} vacio={!visibles.length} mensajeVacio={soloPorVencer ? "Ningún contrato vence en los próximos 30 días." : "Todavía no hay empresas registradas."} onReintentar={empresas.reload} />{visibles.map((company) => { const { contrato } = company; const count = company.total_empleados; const consumption = company.platillos_mes; const status = company.activo === false ? "inactiva" : company.estado_contrato.replace("_", " "); return <tr key={company.id} onClick={() => setSelected(company)} className="cursor-pointer border-b last:border-0 hover:bg-muted/30"><td className="px-5 py-4 font-semibold">{company.nombre}</td><td className="px-5 py-4"><p>{company.contacto.nombre}</p><p className="text-xs text-muted-foreground">{company.contacto.correo}</p></td><td className="px-5 py-4">{count}</td><td className="px-5 py-4">{contrato.porcentaje_subsidio}%</td><td className="px-5 py-4">{contrato.compra_minima_mensual}</td><td className="min-w-[190px] px-5 py-4"><div className="mb-1 flex justify-between text-xs"><span>{consumption} / {contrato.compra_minima_mensual} platillos</span></div><div className="h-2 rounded-full bg-muted"><div className={`h-full rounded-full ${consumption < contrato.compra_minima_mensual ? "bg-amber-500" : "bg-emerald-500"}`} style={{ width: `${Math.min(consumption / contrato.compra_minima_mensual * 100, 100)}%` }} /></div></td><td className="px-5 py-4">{mesesEntre(contrato.vigencia_inicio, contrato.vigencia_fin)} meses</td><td className="px-5 py-4"><div className="flex flex-col items-start gap-1"><span className={`rounded-full px-2 py-1 text-xs font-semibold ${status === "inactiva" ? "bg-muted text-muted-foreground" : status === "activo" ? "bg-emerald-500/10 text-emerald-600" : status === "por vencer" ? "bg-amber-500/10 text-amber-600" : "bg-destructive/10 text-destructive"}`}>{status}</span><BadgeVencimiento dias={company.dias_para_vencer} /></div></td></tr> })}</tbody></table></div></div></div></div>{selected && <div className="fixed inset-0 z-50 flex justify-end bg-black/35" onClick={() => setSelected(null)}><aside className="h-full w-full max-w-2xl overflow-y-auto bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}><div className="flex items-start justify-between"><div><p className="text-sm text-primary">{selected.id}{selected.activo === false && <span className="ml-2 rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-semibold text-destructive">Inactiva</span>}</p><h2 className="font-display text-2xl font-bold">{selected.nombre}</h2></div><button onClick={() => setSelected(null)} aria-label="Cerrar"><X /></button></div><div className="mt-4 flex flex-wrap gap-2"><button onClick={() => setEditando(true)} className="inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-sm font-semibold hover:bg-muted"><Pencil className="size-4" /> Editar empresa</button>{selected.activo === false ? <button onClick={() => cambiarEstado(selected, true)} disabled={cambiandoEstado} className="inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-sm font-semibold hover:bg-muted disabled:opacity-50"><Power className="size-4" /> Reactivar</button> : <button onClick={() => setDesactivando(true)} className="inline-flex h-9 items-center gap-2 rounded-lg border border-destructive/40 px-3 text-sm font-semibold text-destructive hover:bg-destructive/10"><Power className="size-4" /> Desactivar</button>}</div><div role="tablist" aria-label="Secciones de la empresa" className="mt-6 flex gap-2 border-b text-sm">{PESTANAS_EMPRESA.map((nombre) => <button key={nombre} type="button" role="tab" id={`pestana-${nombre}`} aria-selected={tab === nombre} aria-controls="panel-empresa" onClick={() => setTab(nombre)} className={tab === nombre ? "border-b-2 border-primary px-3 py-3 font-semibold" : "px-3 py-3 text-muted-foreground hover:text-foreground"}>{nombre}</button>)}</div><div role="tabpanel" id="panel-empresa" aria-labelledby={`pestana-${tab}`}>{tab === "Resumen" && <><div className="mt-6 grid gap-4 sm:grid-cols-2"><div className="rounded-lg border p-4"><p className="text-xs uppercase text-muted-foreground">Contacto de RR. HH.</p><p className="mt-2 font-semibold">{selected.contacto.nombre}</p><p className="text-sm text-muted-foreground">{selected.contacto.correo}</p></div><div className="rounded-lg border p-4"><p className="text-xs uppercase text-muted-foreground">Entrega</p><p className="mt-2 font-semibold">{selected.contrato.ventana_entrega_inicio}–{selected.contrato.ventana_entrega_fin}</p><p className="text-sm text-muted-foreground">{selected.direccion_entrega}</p></div></div><div className="mt-5 rounded-lg border p-4"><p className="text-xs uppercase text-muted-foreground">Consumo del mes</p><p className="mt-2 text-lg font-bold">{selected.platillos_mes} / {selected.contrato.compra_minima_mensual} platillos</p>{selected.contrato.compra_minima_mensual > selected.platillos_mes && <p className="mt-2 text-sm text-amber-600">Faltan {selected.contrato.compra_minima_mensual - selected.platillos_mes} platillos para el mínimo; se facturará la diferencia</p>}</div><div className="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4"><div><p className="text-xs uppercase text-muted-foreground">Vigencia del contrato</p><p className="mt-2 font-semibold">{formatDate(selected.contrato.vigencia_inicio)} – {formatDate(selected.contrato.vigencia_fin)}</p><div className="mt-1.5"><BadgeVencimiento dias={selected.dias_para_vencer} /></div></div><RenovarContrato nombre={selected.nombre} vigenciaFin={selected.contrato.vigencia_fin} onRenovar={async () => { await renovarContratoEmpresa(selected.id); empresas.reload({ silent: true }) }} /></div><section className="mt-5"><h3 className="mb-3 font-display text-lg font-bold">Usuarios con acceso</h3><UsuariosAcceso organizacion={{ tipo: "empresa", id: selected.id }} roles={["empresa"]} /></section></>}{tab === "Empleados" && <EmpleadosDeEmpresa empresaId={selected.id} />}{tab === "Consumo" && <ConsumoDeEmpresa empresaId={selected.id} minimo={selected.contrato.compra_minima_mensual} />}{tab === "Contrato" && <><div className="mt-6 grid gap-4 sm:grid-cols-2"><div className="rounded-lg border p-4"><p className="text-xs uppercase text-muted-foreground">Precio por platillo</p><p className="mt-2 text-lg font-bold">${selected.contrato.precio_por_platillo}</p></div><div className="rounded-lg border p-4"><p className="text-xs uppercase text-muted-foreground">Subsidio de la empresa</p><p className="mt-2 text-lg font-bold">{selected.contrato.porcentaje_subsidio}%</p></div><div className="rounded-lg border p-4"><p className="text-xs uppercase text-muted-foreground">Mínimo mensual</p><p className="mt-2 text-lg font-bold">{selected.contrato.compra_minima_mensual} platillos</p></div><div className="rounded-lg border p-4"><p className="text-xs uppercase text-muted-foreground">Ventana de entrega</p><p className="mt-2 text-lg font-bold">{selected.contrato.ventana_entrega_inicio}–{selected.contrato.ventana_entrega_fin}</p><p className="text-sm text-muted-foreground">Hora estimada: {selected.contrato.hora_entrega}</p></div></div><div className="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4"><div><p className="text-xs uppercase text-muted-foreground">Vigencia del contrato</p><p className="mt-2 font-semibold">{formatDate(selected.contrato.vigencia_inicio)} – {formatDate(selected.contrato.vigencia_fin)}</p><div className="mt-1.5"><BadgeVencimiento dias={selected.dias_para_vencer} /></div></div><RenovarContrato nombre={selected.nombre} vigenciaFin={selected.contrato.vigencia_fin} onRenovar={async () => { await renovarContratoEmpresa(selected.id); empresas.reload({ silent: true }) }} /></div><div className="mt-5 rounded-lg border p-4"><p className="text-xs uppercase text-muted-foreground">Calculadora de subsidio</p><div className="mt-3 flex items-center justify-between"><span>Subsidio empresa</span><strong>{subsidy}%</strong></div><input type="range" min="0" max="100" step="10" value={subsidy} onChange={(e) => setSubsidy(Number(e.target.value))} className="mt-3 w-full accent-primary" /><p className="mt-3 rounded-lg bg-muted p-3 text-sm">Platillo de ${selected.contrato.precio_por_platillo}: la empresa cubre <strong>${Math.round(selected.contrato.precio_por_platillo * subsidy / 100)}</strong> y al empleado le corresponden <strong>${selected.contrato.precio_por_platillo - Math.round(selected.contrato.precio_por_platillo * subsidy / 100)}</strong></p></div></>}</div></aside></div>}{newOpen && <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4"><div className="max-h-[calc(100dvh-2rem)] w-full max-w-2xl overflow-y-auto rounded-xl bg-card p-6"><div className="flex items-start justify-between"><div><p className="text-sm text-primary">Paso {step} de 4</p><h2 className="font-display text-2xl font-bold">Nueva empresa</h2></div><button onClick={() => setNewOpen(false)} aria-label="Cerrar"><X /></button></div><div className="mt-6 grid gap-4 sm:grid-cols-2">{step === 1 && <><label className="text-sm">Empresa<input className="mt-1 w-full rounded-lg border bg-background p-3" placeholder="Nombre legal" {...campo("nombre")} /></label><label className="text-sm">Contacto de RR. HH.<input className="mt-1 w-full rounded-lg border bg-background p-3" placeholder="Nombre y correo" {...campo("contacto_rrhh")} /></label></>}{step === 2 && <><label className="text-sm">Fecha de inicio<input type="date" className="mt-1 w-full rounded-lg border bg-background p-3" {...campo("vigencia_inicio")} /></label><label className="text-sm">Duración<input value="6 meses" readOnly className="mt-1 w-full rounded-lg border bg-muted p-3" /></label><label className="text-sm">Compra mínima mensual<input type="number" className="mt-1 w-full rounded-lg border bg-background p-3" placeholder="80" {...campo("compra_minima_mensual")} /></label><label className="text-sm">Subsidio<input type="number" min="0" max="100" className="mt-1 w-full rounded-lg border bg-background p-3" placeholder="60" {...campo("porcentaje_subsidio")} /></label></>}{step === 3 && <><label className="text-sm sm:col-span-2">Horario<input className="mt-1 w-full rounded-lg border bg-background p-3" placeholder="12:30–13:15" {...campo("horario_entrega")} /></label><SelectorDePunto modo="alta" valor={punto} onChange={cambiarPunto} zonas={zonasMapa.data ?? []} errorDelServidor={errorCobertura} puntoCambio onBloqueo={setBloqueoPunto} /></>}{step === 4 && <><div className="rounded-lg border p-4 sm:col-span-2"><p className="font-semibold">Carga de empleados</p><p className="mt-1 text-sm text-muted-foreground">Sube un archivo CSV o de Excel (.xlsx) con nombre y correo, o agrégalos uno por uno.</p><input type="file" accept={ARCHIVOS_DE_EMPLEADOS} className="mt-4 text-sm" onChange={(e) => void cargarArchivoDeEmpleados(e.target)} /></div>{nuevoEmpleado ? <div className="grid gap-3 rounded-lg border p-4 sm:col-span-2 sm:grid-cols-2"><label className="text-sm">Nombre<input autoFocus className="mt-1 w-full rounded-lg border bg-background p-3" placeholder="Ana Ruiz" value={nuevoEmpleado.nombre} onChange={(e) => setNuevoEmpleado({ ...nuevoEmpleado, nombre: e.target.value })} /></label><label className="text-sm">Correo (opcional)<input type="email" className="mt-1 w-full rounded-lg border bg-background p-3" placeholder="ana@empresa.mx" value={nuevoEmpleado.correo} onChange={(e) => setNuevoEmpleado({ ...nuevoEmpleado, correo: e.target.value })} onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); añadirEmpleado() } }} /></label><div className="flex gap-2 sm:col-span-2"><button type="button" onClick={añadirEmpleado} className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold">Añadir a la lista</button><button type="button" onClick={() => setNuevoEmpleado(null)} className="rounded-lg border px-4 py-2 text-sm">Cancelar</button></div></div> : <button type="button" onClick={() => setNuevoEmpleado({ nombre: "", correo: "" })} className="rounded-lg border p-3 text-left text-sm sm:col-span-2">+ Agregar empleado</button>}{personas.length > 0 && <div className="rounded-lg border p-4 sm:col-span-2"><div className="flex items-center justify-between"><p className="text-sm font-semibold">{personas.length} empleado(s) por agregar</p><button type="button" onClick={() => setPersonas([])} className="text-xs text-muted-foreground underline">Quitar todos</button></div><ul className="mt-2 max-h-44 space-y-1 overflow-y-auto text-sm">{personas.map((p, i) => <li key={`${p.correo}-${p.nombre}-${i}`} className="flex items-center justify-between gap-2"><span className="min-w-0 truncate"><strong>{p.nombre}</strong> <span className="text-muted-foreground">{p.correo || "(correo con el dominio de la empresa)"}</span></span><button type="button" aria-label={`Quitar a ${p.nombre}`} onClick={() => setPersonas((actuales) => actuales.filter((_, j) => j !== i))} className="shrink-0 rounded p-1 text-muted-foreground hover:text-foreground"><X className="size-4" /></button></li>)}</ul><p className="mt-2 text-xs text-muted-foreground">Se agregan al crear la empresa y cada uno recibe una invitación para crear su contraseña.</p></div>}</>}</div><div className="mt-7 flex justify-between"><button disabled={step === 1} onClick={() => setStep(step - 1)} className="flex items-center gap-1 rounded-lg border px-4 py-2 text-sm disabled:opacity-40"><ChevronLeft /> Atrás</button>{step < 4 ? <button onClick={() => setStep(step + 1)} disabled={step === 3 && Boolean(bloqueoPunto)} title={step === 3 && bloqueoPunto ? bloqueoPunto : undefined} className="flex items-center gap-1 rounded-lg bg-primary px-4 py-2 text-sm font-semibold disabled:cursor-not-allowed disabled:opacity-50">Continuar <ChevronRight /></button> : <button onClick={crear} disabled={saving} className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold disabled:opacity-60">{saving ? "Creando…" : "Crear empresa"}</button>}</div></div></div>}{selected && editando && <EditarEmpresa empresa={selected} zonas={zonasMapa.data ?? []} onCerrar={() => setEditando(false)} onGuardada={() => { setEditando(false); empresas.reload({ silent: true }) }} />}{selected && desactivando && <VentanaConfirmar titulo={`¿Desactivar ${selected.nombre}?`} descripcion={<>Sus empleados dejarán de poder elegir platillos y no entrará en el siguiente corte. Conserva su historial y facturas, y puedes reactivarla cuando quieras.</>} textoConfirmar="Desactivar" trabajando={cambiandoEstado} onCerrar={() => setDesactivando(false)} onConfirmar={() => cambiarEstado(selected, false)} />}</AdminShell>
}

function ConsumoDiarioGrafica({ empresaId, mes = MES_ACTUAL }: { empresaId: string; mes?: string }) {
  const consumo = useAsync(() => consumoDiarioEmpresa(empresaId, mes), [empresaId, mes])
  const dias = consumo.data ?? []
  const maximo = Math.max(1, ...dias.map((d) => d.platillos))
  return <div className="mt-4">
    <BloqueEstado cargando={consumo.loading} error={consumo.error} vacio={!dias.length} mensajeVacio="No hay consumo en este mes." onReintentar={consumo.reload} cantidad={1} />
    {dias.length > 0 && <>
      <div className="flex h-24 items-end gap-1.5" role="img" aria-label="Platillos consumidos por día este mes">{dias.map((d) => <div key={d.fecha} title={`${etiquetaDiaCorta(d.fecha)}: ${d.platillos} platillos`} className="flex-1 rounded-t bg-primary/70 transition-colors hover:bg-primary" style={{ height: `${Math.max(6, (d.platillos / maximo) * 100)}%` }} />)}</div>
      <div className="mt-1.5 flex justify-between text-[10px] text-muted-foreground"><span>{etiquetaDiaCorta(dias[0].fecha)}</span><span>Pico: {maximo} platillos</span><span>{etiquetaDiaCorta(dias[dias.length - 1].fecha)}</span></div>
    </>}
  </div>
}


/** Editar los datos de la empresa y las condiciones de su contrato (subsidio, mínimo y ventana de entrega). */
function EditarEmpresa({ empresa, zonas, onCerrar, onGuardada }: { empresa: EmpresaDetalle; zonas: ZonaConPoligono[]; onCerrar: () => void; onGuardada: () => void }) {
  const [form, setForm] = useState({
    nombre: empresa.nombre, contacto_nombre: empresa.contacto.nombre, contacto_correo: empresa.contacto.correo, contacto_telefono: empresa.contacto.telefono ?? "",
    porcentaje_subsidio: String(empresa.contrato.porcentaje_subsidio), compra_minima_mensual: String(empresa.contrato.compra_minima_mensual),
    ventana_entrega_inicio: empresa.contrato.ventana_entrega_inicio, ventana_entrega_fin: empresa.contrato.ventana_entrega_fin,
  })
  // Abre con el pin en el punto guardado; si la empresa no tiene punto, el mapa abre centrado en Guadalajara sin pin.
  const [punto, setPunto] = useState<DatosDePunto>(() => ({ ...PUNTO_VACIO, ...datosDeDireccionGuardada(empresa.direccion_entrega), latitud: empresa.latitud ?? null, longitud: empresa.longitud ?? null }))
  const [bloqueoPunto, setBloqueoPunto] = useState<string | null>(null)
  const [guardando, setGuardando] = useState(false)
  const [errorCobertura, setErrorCobertura] = useState<string | null>(null)
  const campo = (clave: keyof typeof form) => ({
    value: form[clave],
    onChange: (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [clave]: e.target.value })),
  })
  const cambiarPunto = (parche: Partial<DatosDePunto>) => {
    if ("latitud" in parche) setErrorCobertura(null)
    setPunto((p) => ({ ...p, ...parche }))
  }
  const subsidio = Number(form.porcentaje_subsidio)
  // Solo se manda el punto si cambió: reenviar el mismo punto recalcularía la zona sin que nadie lo haya pedido.
  const puntoCambio = punto.latitud !== null && punto.longitud !== null && (punto.latitud !== empresa.latitud || punto.longitud !== empresa.longitud)
  // Si la dirección no se tocó se manda tal como estaba guardada (no se reescribe con otro formato).
  const direccionComoEstaba = componerDireccion(punto) === componerDireccion(datosDeDireccionGuardada(empresa.direccion_entrega))
  const invalido = !form.nombre.trim() || form.porcentaje_subsidio === "" || subsidio < 0 || subsidio > 100 || Number(form.compra_minima_mensual) < 0 || Boolean(bloqueoPunto)
  async function guardar() {
    setGuardando(true)
    setErrorCobertura(null)
    try {
      const datos: EdicionEmpresa = { ...form, direccion_entrega: direccionComoEstaba ? empresa.direccion_entrega : componerDireccion(punto), porcentaje_subsidio: subsidio, compra_minima_mensual: Number(form.compra_minima_mensual) || 0, ...(puntoCambio ? { latitud: punto.latitud, longitud: punto.longitud } : {}) }
      const guardada = await editarEmpresa(empresa.id, datos)
      toast.success(puntoCambio ? `Cambios guardados · zona ${guardada.zona}` : "Cambios guardados")
      onGuardada()
    } catch (error) {
      const mensaje = error instanceof Error ? error.message : "No se pudo guardar la empresa"
      if (error instanceof ErrorApi && error.estado === 422 && (error.codigo === "fuera_de_cobertura" || Boolean(error.errores?.latitud || error.errores?.longitud))) setErrorCobertura(mensaje)
      toast.error(mensaje)
    } finally {
      setGuardando(false)
    }
  }
  return <VentanaFormulario titulo={`Editar ${empresa.nombre}`} descripcion="Los cambios de subsidio aplican a las elecciones nuevas; las ya hechas conservan el precio y el subsidio con que se congelaron." textoEnviar="Guardar cambios" trabajando={guardando} deshabilitarEnvio={invalido} onCerrar={onCerrar} onEnviar={guardar} ancho="max-w-2xl">
    <CampoTexto etiqueta="Nombre de la empresa" required autoFocus {...campo("nombre")} />
    <SelectorDePunto modo="edicion" valor={punto} onChange={cambiarPunto} zonas={zonas} errorDelServidor={errorCobertura} zonaGuardada={empresa.latitud != null ? empresa.zona : null} puntoCambio={puntoCambio} onBloqueo={setBloqueoPunto} />
    <div className="grid gap-4 sm:grid-cols-2"><CampoTexto etiqueta="Contacto de RR. HH." {...campo("contacto_nombre")} /><CampoTexto etiqueta="Teléfono" type="tel" {...campo("contacto_telefono")} /></div>
    <CampoTexto etiqueta="Correo del contacto" type="email" ayuda="Se puede corregir mientras el contacto no acepte su invitación; entonces le mandamos otra al correo nuevo." {...campo("contacto_correo")} />
    <div className="grid gap-4 sm:grid-cols-2"><CampoTexto etiqueta="Subsidio (%)" type="number" min={0} max={100} step={5} ayuda="Lo decide la empresa." {...campo("porcentaje_subsidio")} /><CampoTexto etiqueta="Mínimo mensual (platillos)" type="number" min={0} {...campo("compra_minima_mensual")} /></div>
    <div className="grid gap-4 sm:grid-cols-2"><CampoTexto etiqueta="Entrega desde" type="time" {...campo("ventana_entrega_inicio")} /><CampoTexto etiqueta="Entrega hasta" type="time" {...campo("ventana_entrega_fin")} /></div>
  </VentanaFormulario>
}

type PestanaEmpresa = "Resumen" | "Empleados" | "Consumo" | "Contrato"
const PESTANAS_EMPRESA: PestanaEmpresa[] = ["Resumen", "Empleados", "Consumo", "Contrato"]

/** Pestaña «Empleados»: quiénes son, si están activos y cuánto han pedido este mes. */
function EmpleadosDeEmpresa({ empresaId }: { empresaId: string }) {
  const empleados = useAsync(() => listarEmpleados(empresaId), [empresaId])
  const filas = empleados.data ?? []
  const activos = filas.filter((e) => e.activo).length
  return <div className="mt-6">
    <BloqueEstado cargando={empleados.loading} error={empleados.error} vacio={!filas.length} mensajeVacio="Esta empresa todavía no tiene empleados registrados." onReintentar={empleados.reload} cantidad={4} />
    {filas.length > 0 && <>
      <p className="mb-3 text-sm text-muted-foreground">{filas.length} empleados · {activos} activos</p>
      <div className="overflow-x-auto rounded-lg border"><table className="w-full min-w-[520px] text-left text-sm">
        <thead className="border-b bg-muted/40 text-xs uppercase text-muted-foreground"><tr>{["Empleado", "Departamento", "Estado", "Platillos este mes", "Para mañana"].map((x) => <th key={x} className="px-4 py-2.5">{x}</th>)}</tr></thead>
        <tbody>{filas.map((e) => <tr key={e.id} className="border-b last:border-0">
          <td className="px-4 py-3"><p className="font-semibold">{e.nombre}</p><p className="text-xs text-muted-foreground">{e.correo}</p></td>
          <td className="px-4 py-3 text-muted-foreground">{e.departamento ?? "—"}</td>
          <td className="px-4 py-3"><span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${e.activo ? "bg-emerald-500/10 text-emerald-600" : "bg-muted text-muted-foreground"}`}>{e.activo ? "Activo" : "Inactivo"}</span></td>
          <td className="px-4 py-3 font-semibold">{e.platillos_por_mes?.[MES_ACTUAL] ?? 0}</td>
          <td className="px-4 py-3 text-muted-foreground">{e.eleccion_manana?.platillo_nombre ?? "Sin elegir"}</td>
        </tr>)}</tbody>
      </table></div>
    </>}
  </div>
}

/** Pestaña «Consumo»: platillos por día de un mes (el actual o uno de los 5 anteriores). */
function ConsumoDeEmpresa({ empresaId, minimo }: { empresaId: string; minimo: number }) {
  const meses = Array.from({ length: 6 }, (_, i) => { const [a, m] = MES_ACTUAL.split("-").map(Number); const d = new Date(Date.UTC(a, m - 1 - i, 1)); return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, "0")}` })
  const [mes, setMes] = useState(MES_ACTUAL)
  const consumo = useAsync(() => consumoDiarioEmpresa(empresaId, mes), [empresaId, mes])
  const total = (consumo.data ?? []).reduce((suma, d) => suma + d.platillos, 0)
  return <div className="mt-6 rounded-lg border p-4">
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div><p className="text-xs uppercase text-muted-foreground">Platillos del mes</p><p className="mt-1 text-lg font-bold">{total} <span className="text-sm font-normal text-muted-foreground">de {minimo} de mínimo</span></p></div>
      <label className="text-sm font-medium">Mes <select value={mes} onChange={(e) => setMes(e.target.value)} className="ml-2 rounded-md border bg-background p-1.5 text-sm">{meses.map((m) => <option key={m} value={m}>{m}</option>)}</select></label>
    </div>
    <ConsumoDiarioGrafica empresaId={empresaId} mes={mes} />
  </div>
}
