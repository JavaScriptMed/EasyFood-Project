"use client"

import { PageTitle } from "@/components/panel"
import { useState } from "react"
import { Check, ChevronRight, Pencil, Power, X } from "lucide-react"
import { toast } from "sonner"
import { AdminShell } from "@/components/admin-shell"
import { BadgeVencimiento, DIAS_POR_VENCER, FiltroPorVencer, RenovarContrato } from "@/components/contrato-vencimiento"
import { BloqueEstado, FilasEstado } from "@/components/estados"
import { UsuariosAcceso } from "@/components/usuarios-acceso"
import { CampoTexto, VentanaConfirmar, VentanaFormulario } from "@/components/ventanas"
import { cambiarEstadoComedor, editarComedor, listarComedores, listarPlatillos, renovarContratoComedor, revisarPlatillo } from "@/lib/api"
import { mesesEntre } from "@/lib/format"
import type { ComedorDetalle } from "@/lib/types"
import { useAsync } from "@/lib/use-async"

export default function ComedoresPage() {
  const comedores = useAsync(listarComedores)
  const platillos = useAsync(() => listarPlatillos())
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [rechazando, setRechazando] = useState<string | null>(null)
  const [motivo, setMotivo] = useState("")
  const [reasonOpen, setReasonOpen] = useState(false)
  const [soloPorVencer, setSoloPorVencer] = useState(false)
  const [editando, setEditando] = useState(false)
  const [desactivando, setDesactivando] = useState(false)
  const [cambiandoEstado, setCambiandoEstado] = useState(false)
  const porVencer = comedores.data?.filter((c) => c.dias_para_vencer <= DIAS_POR_VENCER) ?? []
  const visibles = soloPorVencer ? porVencer : comedores.data ?? []
  const selected = comedores.data?.find((c) => c.id === selectedId) ?? null
  const setSelected = (comedor: { id: string } | null) => setSelectedId(comedor?.id ?? null)

  async function revisar(id: string, decision: "aprobar" | "rechazar", razon?: string) {
    try {
      await revisarPlatillo(id, decision, razon)
      toast.success(decision === "aprobar" ? "Platillo aprobado" : "Platillo rechazado")
      setReasonOpen(false)
      setMotivo("")
      comedores.reload({ silent: true })
      platillos.reload({ silent: true })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo guardar la revisión")
    }
  }
  async function cambiarEstado(comedor: ComedorDetalle, activo: boolean) {
    setCambiandoEstado(true)
    try {
      await cambiarEstadoComedor(comedor.id, activo)
      toast.success(activo ? `${comedor.nombre} quedó activo` : `${comedor.nombre} quedó inactivo`, { description: activo ? undefined : "Conserva su historial y puedes reactivarlo cuando quieras." })
      setDesactivando(false)
      comedores.reload({ silent: true })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo cambiar el estado del comedor")
    } finally {
      setCambiandoEstado(false)
    }
  }
  const pendientes = platillos.data?.filter((d) => d.comedor_id === selected?.id && d.estado_revision === "en_revision") ?? []
  return <AdminShell active="Comedores"><div className="min-h-screen bg-background p-5 md:p-8"><div className="mx-auto max-w-7xl"><div className="mb-7 border-b pb-6"><PageTitle eyebrow="Operación · Proveedores" title="Comedores" description="Contratos, catálogo publicado y cumplimiento de cada cocina." /></div><div className="mb-4 flex flex-wrap items-center gap-2"><FiltroPorVencer activo={soloPorVencer} onChange={setSoloPorVencer} cantidad={comedores.data ? porVencer.length : undefined} /></div><div className="overflow-hidden rounded-xl border bg-card"><div className="overflow-x-auto"><table className="w-full min-w-[850px] text-left text-sm"><thead className="border-b bg-muted/40 text-xs uppercase text-muted-foreground"><tr>{["Comedor", "Zona", "Estado", "Platillos publicados", "Mínimo mensual", "Contrato"].map((x) => <th key={x} className="px-5 py-3">{x}</th>)}</tr></thead><tbody><FilasEstado columnas={6} cargando={comedores.loading} error={comedores.error} vacio={!visibles.length} mensajeVacio={soloPorVencer ? "Ningún contrato vence en los próximos 30 días." : "Todavía no hay comedores registrados."} onReintentar={comedores.reload} />{visibles.map((kitchen) => <tr key={kitchen.id} onClick={() => setSelected(kitchen)} className="cursor-pointer border-b last:border-0 hover:bg-muted/30"><td className="px-5 py-4"><strong>{kitchen.nombre}</strong><p className="text-xs text-muted-foreground">{kitchen.contacto.nombre}</p></td><td className="px-5 py-4">{kitchen.zona}</td><td className="px-5 py-4"><span className="rounded-full bg-emerald-500/10 px-2 py-1 text-xs font-semibold text-emerald-600">{kitchen.activo ? "Activo" : "Inactivo"}</span></td><td className="px-5 py-4">{kitchen.platillos_publicados}</td><td className="min-w-[190px] px-5 py-4"><div className="mb-1 flex justify-between text-xs"><span>{kitchen.platillos_entregados_mes} / {kitchen.contrato.compra_minima_mensual}</span><span>{kitchen.avance_minimo_porcentaje}%</span></div><div className="h-2 rounded-full bg-muted"><div className="h-full rounded-full bg-primary" style={{ width: `${kitchen.avance_minimo_porcentaje}%` }} /></div></td><td className="px-5 py-4">{mesesEntre(kitchen.contrato.vigencia_inicio, kitchen.contrato.vigencia_fin)} meses<br /><span className="text-xs text-muted-foreground">Vence {kitchen.contrato.vigencia_fin}</span><div className="mt-1"><BadgeVencimiento dias={kitchen.dias_para_vencer} /></div></td></tr>)}</tbody></table></div></div></div></div>{selected && <div className="fixed inset-0 z-50 flex justify-end bg-black/35" onClick={() => setSelected(null)}><aside className="h-full w-full max-w-xl overflow-y-auto bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}><div className="flex items-start justify-between"><div><p className="text-sm text-primary">{selected.id} · {selected.zona}{!selected.activo && <span className="ml-2 rounded-full bg-destructive/10 px-2 py-0.5 text-xs font-semibold text-destructive">Inactivo</span>}</p><h2 className="font-display text-2xl font-bold">{selected.nombre}</h2></div><button onClick={() => setSelected(null)} aria-label="Cerrar"><X /></button></div><div className="mt-4 flex flex-wrap gap-2"><button onClick={() => setEditando(true)} className="inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-sm font-semibold hover:bg-muted"><Pencil className="size-4" /> Editar comedor</button>{selected.activo ? <button onClick={() => setDesactivando(true)} className="inline-flex h-9 items-center gap-2 rounded-lg border border-destructive/40 px-3 text-sm font-semibold text-destructive hover:bg-destructive/10"><Power className="size-4" /> Desactivar</button> : <button onClick={() => cambiarEstado(selected, true)} disabled={cambiandoEstado} className="inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-sm font-semibold hover:bg-muted disabled:opacity-50"><Power className="size-4" /> Reactivar</button>}</div><div className="mt-6 grid gap-4 sm:grid-cols-2"><div className="rounded-lg border p-4"><p className="text-xs uppercase text-muted-foreground">Contacto</p><p className="mt-2 font-semibold">{selected.contacto.nombre}</p><p className="text-sm text-muted-foreground">{selected.contacto.telefono}</p></div><div className="rounded-lg border p-4"><p className="text-xs uppercase text-muted-foreground">Contrato</p><p className="mt-2 font-semibold">{mesesEntre(selected.contrato.vigencia_inicio, selected.contrato.vigencia_fin)} meses · ${selected.contrato.precio_por_platillo} por platillo</p><p className="text-sm text-muted-foreground">Mínimo: {selected.contrato.compra_minima_mensual} al mes</p></div></div><div className="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4"><div><p className="text-xs uppercase text-muted-foreground">Vigencia del contrato</p><div className="mt-1.5"><BadgeVencimiento dias={selected.dias_para_vencer} />{selected.dias_para_vencer > DIAS_POR_VENCER && <span className="text-sm text-muted-foreground">Vigente hasta {selected.contrato.vigencia_fin}</span>}</div></div><RenovarContrato nombre={selected.nombre} vigenciaFin={selected.contrato.vigencia_fin} onRenovar={async () => { await renovarContratoComedor(selected.id); comedores.reload({ silent: true }) }} /></div><section className="mt-7"><h3 className="mb-3 font-display text-lg font-bold">Usuarios con acceso</h3><UsuariosAcceso organizacion={{ tipo: "comedor", id: selected.id }} roles={["comedor"]} /></section><section className="mt-7"><h3 className="font-display text-lg font-bold">Platillos pendientes de revisión</h3><div className="mt-3 flex flex-col gap-3"><BloqueEstado cargando={platillos.loading} error={platillos.error} vacio={!pendientes.length} mensajeVacio="No hay platillos pendientes de revisión." onReintentar={platillos.reload} cantidad={2} />{pendientes.map((dish) => <div key={dish.id} className="rounded-lg border p-4"><p className="font-semibold">{dish.nombre}</p><p className="text-sm text-muted-foreground">${selected.contrato.precio_por_platillo} · {dish.alergenos.length ? `Alergénos: ${dish.alergenos.join(", ")}` : "Sin alérgenos registrados"}</p><div className="mt-3 flex gap-2"><button onClick={() => revisar(dish.id, "aprobar")} className="flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white"><Check /> Aprobar</button><button onClick={() => { setRechazando(dish.id); setReasonOpen(true) }} className="rounded-lg border px-3 py-2 text-sm font-semibold">Rechazar</button></div></div>)}</div></section></aside></div>}{reasonOpen && <div className="fixed inset-0 z-[60] grid place-items-center bg-black/40 p-4"><div className="w-full max-w-md rounded-xl bg-card p-6"><h3 className="font-display text-xl font-bold">Motivo del rechazo</h3><textarea value={motivo} onChange={(e) => setMotivo(e.target.value)} className="mt-4 min-h-28 w-full rounded-lg border bg-background p-3 text-sm" placeholder="Explica qué debe corregir el comedor" /><div className="mt-4 flex justify-end gap-2"><button onClick={() => setReasonOpen(false)} className="rounded-lg border px-4 py-2 text-sm">Cancelar</button><button onClick={() => rechazando && revisar(rechazando, "rechazar", motivo)} className="rounded-lg bg-destructive px-4 py-2 text-sm font-semibold text-white">Confirmar rechazo</button></div></div></div>}{selected && editando && <EditarComedor comedor={selected} onCerrar={() => setEditando(false)} onGuardado={() => { setEditando(false); comedores.reload({ silent: true }) }} />}{selected && desactivando && <VentanaConfirmar titulo={`¿Desactivar ${selected.nombre}?`} descripcion={<>Sus platillos dejarán de ofrecerse y no entrará en las siguientes rutas. Conserva su historial y liquidaciones, y puedes reactivarlo cuando quieras.</>} textoConfirmar="Desactivar" trabajando={cambiandoEstado} onCerrar={() => setDesactivando(false)} onConfirmar={() => cambiarEstado(selected, false)} />}</AdminShell>
}

/** Editar los datos del comedor y las condiciones de su contrato (precio por platillo y mínimo mensual). */
function EditarComedor({ comedor, onCerrar, onGuardado }: { comedor: ComedorDetalle; onCerrar: () => void; onGuardado: () => void }) {
  const [form, setForm] = useState({
    nombre: comedor.nombre, zona: comedor.zona, contacto_nombre: comedor.contacto.nombre, contacto_telefono: comedor.contacto.telefono ?? "",
    precio_por_platillo: String(comedor.contrato.precio_por_platillo), compra_minima_mensual: String(comedor.contrato.compra_minima_mensual),
  })
  const [guardando, setGuardando] = useState(false)
  const campo = (clave: keyof typeof form) => ({ value: form[clave], onChange: (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [clave]: e.target.value })) })
  const invalido = !form.nombre.trim() || !(Number(form.precio_por_platillo) > 0) || Number(form.compra_minima_mensual) < 0
  async function guardar() {
    setGuardando(true)
    try {
      await editarComedor(comedor.id, { ...form, precio_por_platillo: Number(form.precio_por_platillo), compra_minima_mensual: Number(form.compra_minima_mensual) || 0 })
      toast.success("Cambios guardados")
      onGuardado()
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo guardar el comedor")
    } finally {
      setGuardando(false)
    }
  }
  return <VentanaFormulario titulo={`Editar ${comedor.nombre}`} descripcion="El precio por platillo aplica a lo que se entregue desde ahora." textoEnviar="Guardar cambios" trabajando={guardando} deshabilitarEnvio={invalido} onCerrar={onCerrar} onEnviar={guardar} ancho="max-w-lg">
    <CampoTexto etiqueta="Nombre del comedor" required autoFocus {...campo("nombre")} />
    <div className="grid gap-4 sm:grid-cols-2"><CampoTexto etiqueta="Zona" {...campo("zona")} /><CampoTexto etiqueta="Contacto" {...campo("contacto_nombre")} /></div>
    <CampoTexto etiqueta="Teléfono de contacto" type="tel" {...campo("contacto_telefono")} />
    <div className="grid gap-4 sm:grid-cols-2"><CampoTexto etiqueta="Precio por platillo (MXN)" type="number" min={1} step="0.01" {...campo("precio_por_platillo")} /><CampoTexto etiqueta="Mínimo mensual (platillos)" type="number" min={0} {...campo("compra_minima_mensual")} /></div>
  </VentanaFormulario>
}
