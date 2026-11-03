"use client"

import { PageTitle, Pill, StatCard } from "@/components/panel"
import { useEffect, useMemo, useRef, useState } from "react"
import { toast } from "sonner"
import { CheckCircle2, Download, FileSpreadsheet, FileText, Info, Loader2, Sparkles, X } from "lucide-react"
import { AdminShell } from "@/components/admin-shell"
import { FilasEstado } from "@/components/estados"
import { generarFacturacion, listarFacturas, listarLiquidaciones, marcarFacturaPagada, marcarLiquidacionPagada, obtenerFactura } from "@/lib/api"
import { AVISO_FACTURACION, MESES, MES_ACTUAL } from "@/lib/config"
import { etiquetasEstadoFactura, etiquetasEstadoLiquidacion } from "@/lib/etiquetas"
import { etiquetaMes, formatDate, formatMXN } from "@/lib/format"
import type { EmpresaDetalle, EstadoFactura, EstadoLiquidacion, FacturaConEmpresa, FacturaMensual, LiquidacionConComedor } from "@/lib/types"
import { useAsync } from "@/lib/use-async"
import { downloadCSV, printPDF } from "@/lib/export"

const tonoEstado = { borrador: "neutral", emitida: "info", pagada: "success", pendiente: "warning", pagado: "success" } as const

function StatusPill({ status }: { status: EstadoFactura | EstadoLiquidacion }) {
  const label = status in etiquetasEstadoFactura ? etiquetasEstadoFactura[status as EstadoFactura] : etiquetasEstadoLiquidacion[status as EstadoLiquidacion]
  return <Pill tono={tonoEstado[status]}>{label}</Pill>
}

export default function FacturacionPage() {
  const [tab, setTab] = useState<"empresas" | "comedores">("empresas")
  const [monthId, setMonthId] = useState(MES_ACTUAL)
  const facturas = useAsync(() => listarFacturas(monthId), [monthId])
  const liquidaciones = useAsync(() => listarLiquidaciones(monthId), [monthId])
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [generating, setGenerating] = useState(false)
  const [progress, setProgress] = useState(0)
  const [justIssued, setJustIssued] = useState<string[]>([])
  const timers = useRef<number[]>([])

  useEffect(() => () => timers.current.forEach((t) => window.clearTimeout(t)), [])

  const rows = facturas.data ?? []
  const settlements = liquidaciones.data ?? []
  const totals = rows.reduce(
    (t, f) => ({
      dishes: t.dishes + f.platillos,
      companyShare: t.companyShare + f.parte_empresa,
      employeeShare: t.employeeShare + f.parte_empleados,
      shortfall: t.shortfall + f.diferencia_minimo,
      total: t.total + f.total,
      paid: t.paid + (f.estado === "pagada" ? f.total : 0),
    }),
    { dishes: 0, companyShare: 0, employeeShare: 0, shortfall: 0, total: 0, paid: 0 },
  )

  const drafts = rows.filter((f) => f.estado === "borrador")

  const actualizarFactura = (nueva: FacturaMensual) => facturas.setData((actual) => actual?.map((f) => (f.id === nueva.id ? { ...f, ...nueva } : f)))

  async function generate() {
    if (!drafts.length || generating) return
    setGenerating(true)
    setProgress(0)
    setJustIssued([])
    try {
      const emitidas = await generarFacturacion(monthId)
      const step = 220
      emitidas.forEach((factura, i) => {
        timers.current.push(
          window.setTimeout(() => {
            actualizarFactura(factura)
            setJustIssued((list) => [...list, factura.empresa_id])
            setProgress(Math.round(((i + 1) / emitidas.length) * 100))
          }, step * (i + 1)),
        )
      })
      timers.current.push(
        window.setTimeout(() => {
          setGenerating(false)
          toast.success(`Facturación de ${etiquetaMes(monthId)} generada`, { description: `${emitidas.length} facturas emitidas.` })
          timers.current.push(window.setTimeout(() => setJustIssued([]), 1500))
        }, step * (emitidas.length + 1)),
      )
    } catch (error) {
      setGenerating(false)
      toast.error(error instanceof Error ? error.message : "No se pudo generar la facturación")
    }
  }

  async function markInvoicePaid(factura: FacturaConEmpresa) {
    try {
      actualizarFactura(await marcarFacturaPagada(factura.id))
      toast.success(`${factura.empresa.nombre} marcada como pagada`)
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo marcar la factura como pagada")
    }
  }

  async function markSettlementPaid(liquidacion: LiquidacionConComedor) {
    try {
      const pagada = await marcarLiquidacionPagada(liquidacion.id)
      liquidaciones.setData((actual) => actual?.map((l) => (l.id === pagada.id ? { ...l, ...pagada } : l)))
      toast.success(`Liquidación de ${liquidacion.comedor.nombre} marcada como pagada`)
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo marcar la liquidación como pagada")
    }
  }

  const selected = rows.find((f) => f.empresa_id === selectedId) ?? null

  return (
    <AdminShell active="Facturación">
      <div className="min-h-screen bg-background p-5 md:p-8">
        <div className="mx-auto max-w-7xl">
          <div className="mb-6 flex flex-wrap items-end justify-between gap-4 border-b pb-6">
            <PageTitle eyebrow="Finanzas" title="Facturación" description="Cobro mensual a empresas y liquidación a comedores." />
            <label className="text-sm font-medium">
              <span className="mb-1 block text-xs uppercase text-muted-foreground">Mes</span>
              <select value={monthId} disabled={generating} onChange={(e) => setMonthId(e.target.value)} className="h-10 min-w-48 rounded-lg border bg-card px-3 text-sm font-semibold disabled:opacity-60">
                {MESES.map((id) => <option key={id} value={id}>{etiquetaMes(id)}</option>)}
              </select>
            </label>
          </div>

          <div role="note" className="sticky top-0 z-10 mb-5 flex items-start gap-3 rounded-xl border border-primary/40 bg-accent px-4 py-3 text-sm font-medium">
            <Info className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
            <p>{AVISO_FACTURACION}</p>
          </div>

          <div className="mb-6 flex gap-2 border-b text-sm" role="tablist">
            {([["empresas", "Empresas"], ["comedores", "Comedores"]] as const).map(([id, label]) => (
              <button key={id} role="tab" aria-selected={tab === id} onClick={() => setTab(id)} className={`px-4 py-3 font-semibold ${tab === id ? "border-b-2 border-primary text-foreground" : "text-muted-foreground hover:text-foreground"}`}>{label}</button>
            ))}
          </div>

          {tab === "empresas" && (
            <>
              <div className="mb-5 grid gap-4 sm:grid-cols-3">
                <StatCard label="Total a facturar" value={formatMXN(totals.total)} helper={`${etiquetaMes(monthId)} · ${totals.dishes.toLocaleString("es-MX")} platillos`} />
                <StatCard label="Cobrado" tono="success" value={formatMXN(totals.paid)} helper={`${rows.filter((f) => f.estado === "pagada").length} de ${rows.length} empresas`} />
                <StatCard label="Por cobrar" tono="warning" value={formatMXN(totals.total - totals.paid)} helper={`${drafts.length} en borrador · ${rows.filter((f) => f.estado === "emitida").length} emitidas`} />
              </div>

              <div className="mb-4 flex flex-wrap items-center gap-3">
                <button onClick={generate} disabled={generating || !drafts.length || facturas.loading} className="flex items-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground transition hover:bg-primary/80 disabled:cursor-not-allowed disabled:opacity-50">
                  {generating ? <Loader2 className="size-4 animate-spin" /> : <Sparkles className="size-4" />}
                  {generating ? "Generando facturas…" : "Generar facturación del mes"}
                </button>
                {!drafts.length && !generating && !facturas.loading && !facturas.error && rows.length > 0 && <span className="text-sm text-muted-foreground">Las facturas de {etiquetaMes(monthId)} ya fueron generadas.</span>}
                {generating && (
                  <div className="h-2 w-48 overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuenow={progress} aria-valuemin={0} aria-valuemax={100}>
                    <div className="h-full rounded-full bg-primary transition-all duration-200" style={{ width: `${progress}%` }} />
                  </div>
                )}
              </div>

              <div className="overflow-hidden rounded-xl border bg-card">
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[1100px] text-left text-sm">
                    <thead className="border-b bg-muted/40 text-xs uppercase text-muted-foreground">
                      <tr>
                        <th className="px-5 py-3">Empresa</th>
                        <th className="px-5 py-3 text-right">Platillos del mes</th>
                        <th className="px-5 py-3 text-right">Parte de la empresa (subsidio)</th>
                        <th className="px-5 py-3 text-right">Parte de los empleados</th>
                        <th className="px-5 py-3 text-right">Diferencia por mínimo</th>
                        <th className="px-5 py-3 text-right">Total a facturar (MXN)</th>
                        <th className="px-5 py-3">Estado</th>
                        <th className="px-5 py-3"><span className="sr-only">Acciones</span></th>
                      </tr>
                    </thead>
                    <tbody>
                      <FilasEstado columnas={8} cargando={facturas.loading} error={facturas.error} vacio={!rows.length} mensajeVacio="No hay empresas con contrato para facturar este mes." onReintentar={facturas.reload} />
                      {rows.map((inv) => {
                        const { empresa } = inv
                        return (
                          <tr key={inv.id} onClick={() => setSelectedId(empresa.id)} className={`cursor-pointer border-b transition-colors duration-700 last:border-0 hover:bg-muted/30 ${justIssued.includes(empresa.id) ? "bg-primary/15" : ""}`}>
                            <td className="px-5 py-4"><p className="font-semibold">{empresa.nombre}</p><p className="text-xs text-muted-foreground">{empresa.id} · subsidio {empresa.contrato.porcentaje_subsidio}%</p></td>
                            <td className="px-5 py-4 text-right tabular-nums">{inv.platillos}<p className="text-xs text-muted-foreground">mín. {empresa.contrato.compra_minima_mensual}</p></td>
                            <td className="px-5 py-4 text-right tabular-nums">{formatMXN(inv.parte_empresa)}</td>
                            <td className="px-5 py-4 text-right tabular-nums">{formatMXN(inv.parte_empleados)}</td>
                            <td className={`px-5 py-4 text-right tabular-nums ${inv.diferencia_minimo ? "font-semibold text-amber-600" : "text-muted-foreground"}`}>{inv.diferencia_minimo ? formatMXN(inv.diferencia_minimo) : "—"}{inv.diferencia_minimo > 0 && <p className="text-xs font-normal">faltan {inv.platillos_faltantes} platillos</p>}</td>
                            <td className="px-5 py-4 text-right font-bold tabular-nums">{formatMXN(inv.total)}</td>
                            <td className="px-5 py-4"><StatusPill status={inv.estado} /></td>
                            <td className="px-5 py-4 text-right">
                              {inv.estado === "emitida" && <button onClick={(e) => { e.stopPropagation(); markInvoicePaid(inv) }} className="whitespace-nowrap rounded-lg border px-3 py-1.5 text-xs font-semibold hover:bg-muted">Marcar como pagada</button>}
                            </td>
                          </tr>
                        )
                      })}
                    </tbody>
                    {rows.length > 0 && <tfoot className="border-t bg-muted/40 text-sm font-bold">
                      <tr>
                        <td className="px-5 py-3">Total del mes</td>
                        <td className="px-5 py-3 text-right tabular-nums">{totals.dishes}</td>
                        <td className="px-5 py-3 text-right tabular-nums">{formatMXN(totals.companyShare)}</td>
                        <td className="px-5 py-3 text-right tabular-nums">{formatMXN(totals.employeeShare)}</td>
                        <td className="px-5 py-3 text-right tabular-nums">{formatMXN(totals.shortfall)}</td>
                        <td className="px-5 py-3 text-right tabular-nums">{formatMXN(totals.total)}</td>
                        <td colSpan={2} />
                      </tr>
                    </tfoot>}
                  </table>
                </div>
              </div>
            </>
          )}

          {tab === "comedores" && (
            <div className="overflow-hidden rounded-xl border bg-card">
              <div className="overflow-x-auto">
                <table className="w-full min-w-[960px] text-left text-sm">
                  <thead className="border-b bg-muted/40 text-xs uppercase text-muted-foreground">
                    <tr>
                      <th className="px-5 py-3">Comedor</th>
                      <th className="px-5 py-3 text-right">Platillos entregados</th>
                      <th className="px-5 py-3 text-right">Diferencia por mínimo no alcanzado</th>
                      <th className="px-5 py-3 text-right">Monto a pagar (MXN)</th>
                      <th className="px-5 py-3">Estado</th>
                      <th className="px-5 py-3"><span className="sr-only">Acciones</span></th>
                    </tr>
                  </thead>
                  <tbody>
                    <FilasEstado columnas={6} cargando={liquidaciones.loading} error={liquidaciones.error} vacio={!settlements.length} mensajeVacio="No hay liquidaciones a comedores para este mes." onReintentar={liquidaciones.reload} />
                    {settlements.map((liq) => (
                      <tr key={liq.id} className="border-b last:border-0">
                        <td className="px-5 py-4"><p className="font-semibold">{liq.comedor.nombre}</p><p className="text-xs text-muted-foreground">{liq.comedor.id} · {liq.comedor.zona}</p></td>
                        <td className="px-5 py-4 text-right tabular-nums">{liq.platillos_entregados}<p className="text-xs text-muted-foreground">mín. {liq.platillos_minimos}</p></td>
                        <td className={`px-5 py-4 text-right tabular-nums ${liq.diferencia_minimo ? "font-semibold text-amber-600" : "text-muted-foreground"}`}>{liq.diferencia_minimo ? formatMXN(liq.diferencia_minimo) : "—"}{liq.diferencia_minimo > 0 && <p className="text-xs font-normal">faltan {liq.platillos_faltantes} platillos</p>}</td>
                        <td className="px-5 py-4 text-right font-bold tabular-nums">{formatMXN(liq.monto)}<p className="text-xs font-normal text-muted-foreground">{formatMXN(liq.precio_por_platillo)} por platillo</p></td>
                        <td className="px-5 py-4"><StatusPill status={liq.estado} />{liq.pagada_en && <p className="mt-1 text-xs text-muted-foreground">el {formatDate(liq.pagada_en)}</p>}</td>
                        <td className="px-5 py-4 text-right">
                          {liq.estado === "pendiente" && <button onClick={() => markSettlementPaid(liq)} className="whitespace-nowrap rounded-lg border px-3 py-1.5 text-xs font-semibold hover:bg-muted">Marcar como pagado</button>}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                  {settlements.length > 0 && <tfoot className="border-t bg-muted/40 text-sm font-bold">
                    <tr>
                      <td className="px-5 py-3">Total del mes</td>
                      <td className="px-5 py-3 text-right tabular-nums">{settlements.reduce((s, l) => s + l.platillos_entregados, 0)}</td>
                      <td className="px-5 py-3 text-right tabular-nums">{formatMXN(settlements.reduce((s, l) => s + l.diferencia_minimo, 0))}</td>
                      <td className="px-5 py-3 text-right tabular-nums">{formatMXN(settlements.reduce((s, l) => s + l.monto, 0))}</td>
                      <td colSpan={2} />
                    </tr>
                  </tfoot>}
                </table>
              </div>
            </div>
          )}
        </div>
      </div>

      {selected && (
        <CompanyPanel
          company={selected.empresa}
          invoice={selected}
          monthId={monthId}
          onClose={() => setSelectedId(null)}
          onMarkPaid={() => markInvoicePaid(selected)}
        />
      )}
    </AdminShell>
  )
}

function CompanyPanel({ company, invoice, monthId, onClose, onMarkPaid }: { company: EmpresaDetalle; invoice: FacturaMensual; monthId: string; onClose: () => void; onMarkPaid: () => void }) {
  const month = etiquetaMes(monthId)
  const fileBase = `reporte-${company.id}-${monthId}`
  const status = invoice.estado
  const detalle = useAsync(() => obtenerFactura(company.id, monthId), [company.id, monthId])
  const lineas = detalle.data?.lineas ?? []
  const importe = invoice.parte_empresa + invoice.parte_empleados

  function csv() {
    downloadCSV(`${fileBase}.csv`, [
      ["Empleado", "Correo", "Platillos", "Importe total (MXN)", "Parte de la empresa (MXN)", "Descontable al empleado (MXN)"],
      ...lineas.map((l) => [l.nombre, l.correo, l.platillos, l.importe, l.parte_empresa, l.parte_empleado]),
      ["Total", "", invoice.platillos, importe, invoice.parte_empresa, invoice.parte_empleados],
    ])
  }

  function pdf() {
    printPDF({
      title: `Reporte de consumo · ${company.nombre}`,
      subtitle: `${month} · Folio ${invoice.id}`,
      summary: [
        ["Platillos", String(invoice.platillos)],
        ["Parte de la empresa", formatMXN(invoice.parte_empresa)],
        ["Parte de los empleados", formatMXN(invoice.parte_empleados)],
        ["Diferencia por mínimo", formatMXN(invoice.diferencia_minimo)],
        ["Total a facturar", formatMXN(invoice.total)],
      ],
      head: ["Empleado", "Platillos", "Importe total", "Parte de la empresa", "Descontable al empleado"],
      body: lineas.map((l) => [l.nombre, l.platillos, formatMXN(l.importe), formatMXN(l.parte_empresa), formatMXN(l.parte_empleado)]),
      foot: ["Total", invoice.platillos, formatMXN(importe), formatMXN(invoice.parte_empresa), formatMXN(invoice.parte_empleados)],
      note: AVISO_FACTURACION,
    })
  }

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/35" onClick={onClose}>
      <aside role="dialog" aria-label={`Facturación de ${company.nombre}`} className="h-full w-full max-w-3xl overflow-y-auto bg-card p-5 shadow-xl sm:p-6" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="text-sm text-primary">{company.id} · {month}</p>
            <h2 className="font-display text-2xl font-bold">{company.nombre}</h2>
            <div className="mt-2"><StatusPill status={status} /></div>
          </div>
          <button onClick={onClose} aria-label="Cerrar"><X /></button>
        </div>

        <div className="mt-6 grid gap-3 sm:grid-cols-2">
          <div className="rounded-lg border p-4"><p className="text-xs uppercase text-muted-foreground">Contrato</p><p className="mt-2 font-semibold">{formatDate(company.contrato.vigencia_inicio)} – {formatDate(company.contrato.vigencia_fin)}</p><p className="text-sm text-muted-foreground">Subsidio {company.contrato.porcentaje_subsidio}% · mínimo {company.contrato.compra_minima_mensual} platillos · {formatMXN(company.contrato.precio_por_platillo)} por platillo</p></div>
          <div className="rounded-lg border p-4"><p className="text-xs uppercase text-muted-foreground">Contacto de RR. HH.</p><p className="mt-2 font-semibold">{company.contacto.nombre}</p><p className="text-sm text-muted-foreground">{company.contacto.correo}</p></div>
        </div>

        <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
          {[["Platillos", `${invoice.platillos}`], ["Empresa", formatMXN(invoice.parte_empresa)], ["Empleados", formatMXN(invoice.parte_empleados)], ["Total a facturar", formatMXN(invoice.total)]].map(([label, value]) => (
            <div key={label} className="rounded-lg bg-muted/60 p-3"><p className="text-xs text-muted-foreground">{label}</p><p className="mt-1 font-bold tabular-nums">{value}</p></div>
          ))}
        </div>
        {invoice.diferencia_minimo > 0 && <p className="mt-3 rounded-lg bg-amber-500/10 p-3 text-sm text-amber-700">No se alcanzó el mínimo: faltaron {invoice.platillos_faltantes} platillos ({formatMXN(invoice.diferencia_minimo)}) y se suman al total a facturar.</p>}

        <div className="mt-5 flex flex-wrap gap-2">
          <button onClick={pdf} disabled={!detalle.data} className="flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:bg-primary/80 disabled:opacity-50"><FileText className="size-4" /> Descargar reporte (PDF)</button>
          <button onClick={csv} disabled={!detalle.data} className="flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold hover:bg-muted disabled:opacity-50"><FileSpreadsheet className="size-4" /> Descargar CSV</button>
          {status === "emitida" && <button onClick={onMarkPaid} className="flex items-center gap-2 rounded-lg border px-4 py-2 text-sm font-semibold hover:bg-muted"><CheckCircle2 className="size-4" /> Marcar como pagada</button>}
        </div>

        <h3 className="mt-7 font-display text-lg font-bold">Consumo por empleado</h3>
        <div className="mt-3 overflow-hidden rounded-lg border">
          <div className="max-h-[420px] overflow-auto">
            <table className="w-full min-w-[560px] text-left text-sm">
              <thead className="sticky top-0 border-b bg-muted text-xs uppercase text-muted-foreground">
                <tr>
                  <th className="px-4 py-3">Empleado</th>
                  <th className="px-4 py-3 text-right">Platillos</th>
                  <th className="px-4 py-3 text-right">Importe total</th>
                  <th className="px-4 py-3 text-right">Parte de la empresa</th>
                  <th className="px-4 py-3 text-right">Descontable al empleado</th>
                </tr>
              </thead>
              <tbody>
                <FilasEstado columnas={5} cargando={detalle.loading} error={detalle.error} vacio={!lineas.length} mensajeVacio={`Sin consumo registrado en ${month}.`} onReintentar={detalle.reload} />
                {lineas.map((l) => (
                  <tr key={l.empleado_id} className="border-b last:border-0">
                    <td className="px-4 py-2.5"><p className="font-medium">{l.nombre}</p><p className="text-xs text-muted-foreground">{l.correo}</p></td>
                    <td className="px-4 py-2.5 text-right tabular-nums">{l.platillos}</td>
                    <td className="px-4 py-2.5 text-right tabular-nums">{formatMXN(l.importe)}</td>
                    <td className="px-4 py-2.5 text-right tabular-nums">{formatMXN(l.parte_empresa)}</td>
                    <td className="px-4 py-2.5 text-right tabular-nums">{formatMXN(l.parte_empleado)}</td>
                  </tr>
                ))}
              </tbody>
              {lineas.length > 0 && <tfoot className="sticky bottom-0 border-t bg-muted text-sm font-bold">
                <tr>
                  <td className="px-4 py-3">Total</td>
                  <td className="px-4 py-3 text-right tabular-nums">{invoice.platillos}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMXN(importe)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMXN(invoice.parte_empresa)}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{formatMXN(invoice.parte_empleados)}</td>
                </tr>
              </tfoot>}
            </table>
          </div>
        </div>
        <p className="mt-3 text-xs text-muted-foreground">{AVISO_FACTURACION}</p>
      </aside>
    </div>
  )
}
