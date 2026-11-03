'use client'

import { PageTitle } from '@/components/panel'
import { FileSpreadsheet, FileText, Hourglass } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { useEmpresa } from '@/components/empresa-shell'
import { BloqueEstado } from '@/components/estados'
import { listarFacturasEmpresa } from '@/lib/api'
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
                  <Button size="sm" disabled={!status} onClick={() => pdf(invoice)}><FileText /> PDF</Button>
                  <Button size="sm" variant="outline" disabled={!status} onClick={() => csv(invoice)}><FileSpreadsheet /> CSV</Button>
                </div>
              </Card>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
