'use client'

import { PageTitle } from '@/components/panel'
import { useState } from 'react'
import { FileSpreadsheet, FileText, Info } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { useEmpresa } from '@/components/empresa-shell'
import { BloqueEstado, FilasEstado } from '@/components/estados'
import { obtenerFactura } from '@/lib/api'
import { AVISO_COBRO_EMPLEADO, MESES, MES_ACTUAL } from '@/lib/config'
import { etiquetaMes, formatMXN } from '@/lib/format'
import { useAsync } from '@/lib/use-async'
import { downloadCSV, printPDF } from '@/lib/export'

export default function EmpresaConsumo() {
  const { empresa } = useEmpresa()
  const [monthId, setMonthId] = useState(MES_ACTUAL)
  const consumo = useAsync(() => obtenerFactura(empresa.id, monthId), [empresa.id, monthId])
  const invoice = consumo.data
  const lineas = invoice?.lineas ?? []
  const month = etiquetaMes(monthId)

  function csv() {
    if (!invoice) return
    downloadCSV(`consumo-${empresa.id}-${monthId}.csv`, [
      ['Empleado', 'Correo', 'Platillos', 'Importe total (MXN)', 'Parte de la empresa (MXN)', 'Parte del empleado (MXN)'],
      ...lineas.map((l) => [l.nombre, l.correo, l.platillos, l.importe, l.parte_empresa, l.parte_empleado]),
      ['Total', '', invoice.platillos, invoice.importe_consumo, invoice.parte_empresa, invoice.parte_empleados],
    ])
  }

  function pdf() {
    if (!invoice) return
    printPDF({
      title: `Consumo y cobro · ${empresa.nombre}`,
      subtitle: month,
      summary: [
        ['Platillos', String(invoice.platillos)],
        ['Importe total', formatMXN(invoice.importe_consumo)],
        ['Parte de la empresa', formatMXN(invoice.parte_empresa)],
        ['Parte de los empleados', formatMXN(invoice.parte_empleados)],
      ],
      head: ['Empleado', 'Platillos', 'Importe total', 'Parte de la empresa', 'Parte del empleado'],
      body: lineas.map((l) => [l.nombre, l.platillos, formatMXN(l.importe), formatMXN(l.parte_empresa), formatMXN(l.parte_empleado)]),
      foot: ['Total', invoice.platillos, formatMXN(invoice.importe_consumo), formatMXN(invoice.parte_empresa), formatMXN(invoice.parte_empleados)],
      note: `${AVISO_COBRO_EMPLEADO}.`,
    })
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-end justify-between gap-4 border-b pb-6">
        <div>
          <PageTitle eyebrow="Reporte mensual" title="Consumo y cobro" description={<>Cuánto consumió cada empleado y qué parte le corresponde pagar.</>} />
        </div>
        <label className="text-sm font-medium">
          <span className="mb-1 block text-xs uppercase text-muted-foreground">Mes</span>
          <select value={monthId} onChange={(e) => setMonthId(e.target.value)} className="h-10 min-w-44 rounded-lg border bg-card px-3 text-sm font-semibold">
            {MESES.map((id) => <option key={id} value={id}>{etiquetaMes(id)}</option>)}
          </select>
        </label>
      </div>

      <div role="note" className="mb-5 flex items-start gap-3 rounded-xl border border-primary/40 bg-accent px-4 py-3 text-sm font-medium">
        <Info className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
        <p>{AVISO_COBRO_EMPLEADO}</p>
      </div>

      <section className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {[
          ['Platillos', invoice ? String(invoice.platillos) : '–'],
          ['Importe total', invoice ? formatMXN(invoice.importe_consumo) : '–'],
          ['Parte de la empresa', invoice ? formatMXN(invoice.parte_empresa) : '–'],
          ['Parte de los empleados', invoice ? formatMXN(invoice.parte_empleados) : '–'],
        ].map(([label, value]) => (
          <Card key={label}><CardContent className="p-4"><p className="text-xs text-muted-foreground">{label}</p><p className="mt-2 font-display text-xl font-extrabold tabular-nums sm:text-2xl">{value}</p></CardContent></Card>
        ))}
      </section>

      <div className="my-5 flex flex-wrap gap-2">
        <Button className="h-10 px-4" onClick={pdf} disabled={!invoice}><FileText /> Descargar PDF</Button>
        <Button variant="outline" className="h-10 px-4" onClick={csv} disabled={!invoice}><FileSpreadsheet /> Descargar CSV</Button>
      </div>

      {/* Escritorio: tabla */}
      <Card className="hidden p-0 md:block">
        <table className="w-full text-left text-sm">
          <thead className="border-b bg-muted/40 text-xs uppercase text-muted-foreground">
            <tr>
              <th className="px-5 py-3">Empleado</th>
              <th className="px-5 py-3 text-right">Platillos</th>
              <th className="px-5 py-3 text-right">Importe total</th>
              <th className="px-5 py-3 text-right">Parte de la empresa</th>
              <th className="px-5 py-3 text-right">Parte del empleado</th>
            </tr>
          </thead>
          <tbody>
            <FilasEstado columnas={5} cargando={consumo.loading} error={consumo.error} vacio={!lineas.length} mensajeVacio={`Sin consumo registrado en ${month}.`} onReintentar={consumo.reload} />
            {lineas.map((l) => (
              <tr key={l.empleado_id} className="border-b last:border-0">
                <td className="px-5 py-3"><p className="font-semibold">{l.nombre}</p><p className="text-xs text-muted-foreground">{l.correo}</p></td>
                <td className="px-5 py-3 text-right tabular-nums">{l.platillos}</td>
                <td className="px-5 py-3 text-right tabular-nums">{formatMXN(l.importe)}</td>
                <td className="px-5 py-3 text-right tabular-nums">{formatMXN(l.parte_empresa)}</td>
                <td className="px-5 py-3 text-right font-semibold tabular-nums">{formatMXN(l.parte_empleado)}</td>
              </tr>
            ))}
          </tbody>
          {invoice && lineas.length > 0 && <tfoot className="border-t bg-muted/40 font-bold">
            <tr>
              <td className="px-5 py-3">Total</td>
              <td className="px-5 py-3 text-right tabular-nums">{invoice.platillos}</td>
              <td className="px-5 py-3 text-right tabular-nums">{formatMXN(invoice.importe_consumo)}</td>
              <td className="px-5 py-3 text-right tabular-nums">{formatMXN(invoice.parte_empresa)}</td>
              <td className="px-5 py-3 text-right tabular-nums">{formatMXN(invoice.parte_empleados)}</td>
            </tr>
          </tfoot>}
        </table>
      </Card>

      {/* Celular: una tarjeta por empleado */}
      <div className="md:hidden"><BloqueEstado cargando={consumo.loading} error={consumo.error} vacio={!lineas.length} mensajeVacio={`Sin consumo registrado en ${month}.`} onReintentar={consumo.reload} cantidad={3} /></div>
      <ul className="flex flex-col gap-3 md:hidden">
        {lineas.map((l) => (
          <li key={l.empleado_id} className="rounded-xl border bg-card p-4">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0"><p className="font-semibold">{l.nombre}</p><p className="break-all text-xs text-muted-foreground">{l.correo}</p></div>
              <span className="shrink-0 rounded-full bg-muted px-2.5 py-1 text-xs font-semibold">{l.platillos} platillos</span>
            </div>
            <dl className="mt-3 grid grid-cols-3 gap-2 text-sm">
              <div><dt className="text-xs text-muted-foreground">Importe</dt><dd className="font-semibold tabular-nums">{formatMXN(l.importe)}</dd></div>
              <div><dt className="text-xs text-muted-foreground">Empresa</dt><dd className="font-semibold tabular-nums">{formatMXN(l.parte_empresa)}</dd></div>
              <div><dt className="text-xs text-muted-foreground">Empleado</dt><dd className="font-semibold tabular-nums text-primary">{formatMXN(l.parte_empleado)}</dd></div>
            </dl>
          </li>
        ))}
      </ul>

      {invoice && invoice.diferencia_minimo > 0 && (
        <p className="mt-5 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-700">
          {monthId === MES_ACTUAL ? 'Hasta hoy' : 'En este mes'} faltan {invoice.platillos_faltantes} platillos para la compra mínima ({empresa.contrato.compra_minima_mensual}). La diferencia ({formatMXN(invoice.diferencia_minimo)}) la cubre la empresa y no se reparte entre los empleados.
        </p>
      )}
    </div>
  )
}
