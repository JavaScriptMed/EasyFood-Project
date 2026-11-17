"use client"

import { useState } from "react"
import { toast } from "sonner"
import { Ban, Plus } from "lucide-react"
import { AdminShell } from "@/components/admin-shell"
import { FilasEstado } from "@/components/estados"
import { PageTitle, Pill } from "@/components/panel"
import { Button } from "@/components/ui/button"
import { CampoLista, CampoTexto, VentanaConfirmar, VentanaFormulario } from "@/components/ventanas"
import { anularCostoOperativo, listarCostosOperativos, registrarCostoOperativo } from "@/lib/api"
import { FECHA_HOY } from "@/lib/config"
import { formatDate, formatMXN } from "@/lib/format"
import type { CategoriaCosto, CostoOperativo } from "@/lib/types"
import { useAsync } from "@/lib/use-async"

const CATEGORIAS: Record<CategoriaCosto, string> = { reparto: "Reparto", empaque: "Empaque", nomina: "Nómina", tecnologia: "Tecnología", otro: "Otro" }

/** Otros gastos de la operación que entran al balance de utilidad del dashboard. Se anulan, no se borran. */
export default function CostosOperativosPage() {
  const costos = useAsync(() => listarCostosOperativos())
  const [registrando, setRegistrando] = useState(false)
  const [anulando, setAnulando] = useState<CostoOperativo | null>(null)
  const [trabajando, setTrabajando] = useState(false)
  const lista = costos.data ?? []
  const total = lista.filter((c) => c.activo).reduce((suma, c) => suma + c.monto, 0)

  async function anular(costo: CostoOperativo) {
    setTrabajando(true)
    try {
      await anularCostoOperativo(costo.id)
      toast.success("Costo anulado", { description: "Queda en el historial, pero ya no cuenta en el balance." })
      setAnulando(null)
      costos.reload({ silent: true })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo anular el costo")
    } finally {
      setTrabajando(false)
    }
  }

  return <AdminShell active="Costos operativos"><div className="min-h-screen bg-background p-5 md:p-8"><div className="mx-auto max-w-5xl">
    <div className="mb-7 flex flex-wrap items-end justify-between gap-4 border-b pb-6">
      <div><PageTitle eyebrow="Finanzas" title="Costos operativos" description="Reparto, empaque, nómina y otros gastos que entran al balance semanal de utilidad del dashboard (junto con el pago a las cocinas, que se calcula solo)." /></div>
      <Button onClick={() => setRegistrando(true)}><Plus /> Registrar costo</Button>
    </div>
    <p className="mb-4 text-sm text-muted-foreground">Últimas 6 semanas · <strong className="text-foreground tabular-nums">{formatMXN(total)}</strong> en costos activos</p>
    <div className="overflow-hidden rounded-xl border bg-card"><div className="overflow-x-auto">
      <table className="w-full min-w-[720px] text-left text-sm">
        <thead className="border-b bg-muted/40 text-xs uppercase text-muted-foreground"><tr><th className="px-5 py-3">Fecha</th><th className="px-5 py-3">Categoría</th><th className="px-5 py-3">Descripción</th><th className="px-5 py-3 text-right">Monto</th><th className="px-5 py-3">Registró</th><th className="px-5 py-3"><span className="sr-only">Acciones</span></th></tr></thead>
        <tbody>
          <FilasEstado columnas={6} cargando={costos.loading} error={costos.error} vacio={!lista.length} mensajeVacio="Todavía no hay costos registrados." onReintentar={costos.reload} />
          {lista.map((c) => (
            <tr key={c.id} className={`border-b last:border-0 ${c.activo ? "" : "text-muted-foreground"}`}>
              <td className="whitespace-nowrap px-5 py-3.5">{formatDate(c.fecha)}</td>
              <td className="px-5 py-3.5"><Pill tono={c.activo ? "info" : "neutral"}>{CATEGORIAS[c.categoria]}</Pill></td>
              <td className="px-5 py-3.5">{c.descripcion}{!c.activo && <span className="ml-2 text-xs font-semibold">Anulado</span>}</td>
              <td className={`px-5 py-3.5 text-right font-semibold tabular-nums ${c.activo ? "" : "line-through"}`}>{formatMXN(c.monto)}</td>
              <td className="px-5 py-3.5">{c.registrado_por ?? "—"}</td>
              <td className="px-5 py-3.5 text-right">{c.activo && <Button variant="ghost" size="sm" onClick={() => setAnulando(c)} aria-label={`Anular el costo ${c.descripcion}`}><Ban /> Anular</Button>}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div></div>
  </div></div>
    {registrando && <RegistrarCosto onCerrar={() => setRegistrando(false)} onRegistrado={() => { setRegistrando(false); costos.reload({ silent: true }) }} />}
    {anulando && <VentanaConfirmar titulo="¿Anular este costo?" descripcion={<>Se anulará <strong className="text-foreground">{anulando.descripcion}</strong> ({formatMXN(anulando.monto)}). No se borra: queda en el historial, pero deja de contar en el balance de utilidad.</>} textoConfirmar="Anular costo" trabajando={trabajando} onCerrar={() => setAnulando(null)} onConfirmar={() => anular(anulando)} />}
  </AdminShell>
}

function RegistrarCosto({ onCerrar, onRegistrado }: { onCerrar: () => void; onRegistrado: () => void }) {
  const [form, setForm] = useState({ fecha: FECHA_HOY, categoria: "reparto" as CategoriaCosto, descripcion: "", monto: "" })
  const [guardando, setGuardando] = useState(false)
  const monto = Number(form.monto)
  const invalido = !form.fecha || form.fecha > FECHA_HOY || form.descripcion.trim().length < 3 || !(monto > 0)

  async function guardar() {
    setGuardando(true)
    try {
      await registrarCostoOperativo({ fecha: form.fecha, categoria: form.categoria, descripcion: form.descripcion.trim(), monto })
      toast.success("Costo registrado")
      onRegistrado()
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo registrar el costo")
    } finally {
      setGuardando(false)
    }
  }

  return <VentanaFormulario titulo="Registrar un costo" descripcion="Entra al balance de utilidad de la semana en que ocurrió el gasto." textoEnviar="Registrar" trabajando={guardando} deshabilitarEnvio={invalido} onCerrar={onCerrar} onEnviar={guardar}>
    <CampoLista etiqueta="Categoría" value={form.categoria} onChange={(e) => setForm((f) => ({ ...f, categoria: e.target.value as CategoriaCosto }))} opciones={(Object.keys(CATEGORIAS) as CategoriaCosto[]).map((c) => ({ valor: c, texto: CATEGORIAS[c] }))} />
    <CampoTexto etiqueta="Descripción" required autoFocus placeholder="Ej. Combustible de la semana" maxLength={255} value={form.descripcion} onChange={(e) => setForm((f) => ({ ...f, descripcion: e.target.value }))} />
    <div className="grid gap-4 sm:grid-cols-2">
      <CampoTexto etiqueta="Monto (MXN)" type="number" min={0.01} step="0.01" required value={form.monto} onChange={(e) => setForm((f) => ({ ...f, monto: e.target.value }))} />
      <CampoTexto etiqueta="Fecha del gasto" type="date" max={FECHA_HOY} required value={form.fecha} onChange={(e) => setForm((f) => ({ ...f, fecha: e.target.value }))} />
    </div>
  </VentanaFormulario>
}
