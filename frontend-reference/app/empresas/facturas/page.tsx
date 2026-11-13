'use client'

import { PageTitle } from '@/components/panel'
import { useState } from 'react'
import { Eye, FileSpreadsheet, FileText, Hourglass } from 'lucide-react'
import { GrillaDatos, PanelDetalle } from '@/components/ventanas'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { useEmpresa } from '@/components/empresa-shell'
import { BloqueEstado } from '@/components/estados'
import { listarFacturasEmpresa, obtenerFactura } from '@/lib/api'
import { AVISO_FACTURACION, MES_ACTUAL } from '@/lib/config'
import { etiquetaMes, formatMXN } from '@/lib/format'
import type { FacturaMensual } from '@/lib/types'
import { useAsync } from '@/lib/use-async'
import { downloadCSV, printPDF } from '@/lib/export'

const statusStyle = { emitida: 'bg-sky-500/10 text-sky-600', pagada: 'bg-emerald-500/10 text-emerald-600' } as const
const statusText = { emitida: 'Pendiente de pago', pagada: 'Pagada' } as const

export default function EmpresaFacturas() {
  const { empresa } = useEmpresa()
  const facturas = useAsync(() => listarFacturasEmpresa(empresa.id), [empresa.id])
  const [verMes, setVerMes] = useState<string | null>(null)

  function pdf(invoice: FacturaMensual) {
    printPDF({
      title: `Factura ${invoice.id}`,
      subtitle: `${empresa.nombre} · ${etiquetaMes(invoice.mes)}`,
      summary: [['Total a pagar', formatMXN(invoice.total)]],
      head: ['Concepto', 'Importe'],
      body: [
        [`Platillos de la empresa (${empresa.contrato.porcentaje_subsidio}% de subsidio)`, formatMXN(invoice.parte_empresa)],
        ['Platillos a cargo de los empleados', formatMXN(invoice.parte_empleados)],
        [`Diferencia por compra mínima (${invoice.platillos_faltantes} platillos)`, formatMXN(invoice.diferencia_minimo)],
      ],
      foot: ['Total (MXN)', formatMXN(invoice.total)],
      note: AVISO_FACTURACION,
    })
  }

  function csv(invoice: FacturaMensual) {
    downloadCSV(`factura-${invoice.id}.csv`, [
      ['Folio', 'Mes', 'Platillos', 'Parte de la empresa', 'Parte de los empleados', 'Diferencia por mínimo', 'Total (MXN)'],
      [invoice.id, etiquetaMes(invoice.mes), invoice.platillos, invoice.parte_empresa, invoice.parte_empleados, invoice.diferencia_minimo, invoice.total],
    ])
  }

  return (
    <div>
      <div className="mb-6 border-b pb-6">
        <PageTitle eyebrow="Cobro a la empresa" title="Facturas" description={<>{AVISO_FACTURACION}</>} />
      </div>

      <BloqueEstado cargando={facturas.loading} error={facturas.error} vacio={!facturas.data?.length} mensajeVacio="Todavía no hay facturas para tu empresa." onReintentar={facturas.reload} cantidad={4} />
      <ul className="flex flex-col gap-3">
        {facturas.data?.map((invoice) => {
          const status = invoice.mes === MES_ACTUAL || invoice.estado === 'borrador' ? null : (invoice.estado as keyof typeof statusText)
          return (
            <li key={invoice.mes}>
              <Card className="flex-row flex-wrap items-center justify-between gap-x-6 gap-y-3 px-4 py-4 sm:px-5">
                <div className="min-w-0">
                  <p className="font-semibold">{etiquetaMes(invoice.mes)}</p>
                  <p className="text-xs text-muted-foreground">{status ? `Folio ${invoice.id}` : 'Se emite al cierre del mes'} · {invoice.platillos} platillos</p>
                </div>
                <p className="font-display text-xl font-extrabold tabular-nums sm:ml-auto">{formatMXN(invoice.total)}</p>
                {status ? (
                  <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusStyle[status]}`}>{statusText[status]}</span>
                ) : (
                  <span className="flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-xs font-semibold text-muted-foreground"><Hourglass className="size-3" /> En curso</span>
                )}
                <div className="flex gap-2">
                  <Button size="sm" variant="ghost" onClick={() => setVerMes(invoice.mes)}><Eye /> Ver detalle</Button>
                  <Button size="sm" disabled={!status} onClick={() => pdf(invoice)}><FileText /> PDF</Button>
                  <Button size="sm" variant="outline" disabled={!status} onClick={() => csv(invoice)}><FileSpreadsheet /> CSV</Button>
                </div>
              </Card>
            </li>
          )
        })}
      </ul>
      {verMes && <DetalleFactura empresaId={empresa.id} mes={verMes} subsidio={empresa.contrato.porcentaje_subsidio} onCerrar={() => setVerMes(null)} />}
    </div>
  )
}

/** Desglose de la factura de un mes: totales y cuánto consumió y cuánto pagó cada empleado. También sirve para el mes en curso («hasta hoy»). */
function DetalleFactura({ empresaId, mes, subsidio, onCerrar }: { empresaId: string; mes: string; subsidio: number; onCerrar: () => void }) {
  const detalle = useAsync(() => obtenerFactura(empresaId, mes), [empresaId, mes])
  const f = detalle.data
  const enCurso = mes === MES_ACTUAL || f?.estado === 'borrador'
  return (
    <PanelDetalle etiqueta={enCurso ? 'En curso · hasta hoy' : `Folio ${f?.id ?? ''}`} titulo={`Factura de ${etiquetaMes(mes)}`} onCerrar={onCerrar}>
      <BloqueEstado cargando={detalle.loading} error={detalle.error} vacio={false} mensajeVacio="" onReintentar={detalle.reload} cantidad={3} />
      {f && (
        <>
          <GrillaDatos datos={[
            { etiqueta: 'Platillos', valor: f.platillos },
            { etiqueta: 'Estado', valor: enCurso ? 'En curso' : f.estado === 'pagada' ? 'Pagada' : 'Pendiente de pago' },
            { etiqueta: `Parte de la empresa (${subsidio}%)`, valor: formatMXN(f.parte_empresa) },
            { etiqueta: 'Parte de los empleados', valor: formatMXN(f.parte_empleados) },
            { etiqueta: `Diferencia por mínimo (${f.platillos_faltantes} platillos)`, valor: formatMXN(f.diferencia_minimo) },
            { etiqueta: 'Total', valor: formatMXN(f.total) },
          ]} />
          <h3 className="mb-2 mt-6 font-display text-lg font-bold">Consumo por empleado</h3>
          <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-left text-sm">
              <thead className="border-b bg-muted/40 text-xs uppercase text-muted-foreground"><tr><th className="px-3 py-2">Empleado</th><th className="px-3 py-2 text-right">Platillos</th><th className="px-3 py-2 text-right">Empresa</th><th className="px-3 py-2 text-right">Empleado</th></tr></thead>
              <tbody>
                {f.lineas.map((l) => <tr key={l.empleado_id} className="border-b last:border-0"><td className="px-3 py-2"><p className="font-semibold">{l.nombre}</p><p className="text-xs text-muted-foreground">{l.correo}</p></td><td className="px-3 py-2 text-right tabular-nums">{l.platillos}</td><td className="px-3 py-2 text-right tabular-nums">{formatMXN(l.parte_empresa)}</td><td className="px-3 py-2 text-right tabular-nums">{formatMXN(l.parte_empleado)}</td></tr>)}
                {!f.lineas.length && <tr><td colSpan={4} className="px-3 py-6 text-center text-muted-foreground">Sin consumo en este mes.</td></tr>}
              </tbody>
            </table>
          </div>
        </>
      )}
    </PanelDetalle>
  )
}
