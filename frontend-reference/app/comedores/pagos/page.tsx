'use client'

import { PageTitle } from '@/components/panel'
import { useState } from 'react'
import { Download, Eye } from 'lucide-react'
import { GrillaDatos, PanelDetalle } from '@/components/ventanas'
import { formatDate } from '@/lib/format'
import type { LiquidacionComedor } from '@/lib/types'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { FilasEstado } from '@/components/estados'
import { listarLiquidacionesComedor } from '@/lib/api'
import { printPDF } from '@/lib/export'
import { COMEDOR_PORTAL_ID } from '@/lib/config'
import { etiquetasEstadoLiquidacion } from '@/lib/etiquetas'
import { etiquetaMes, formatMXN } from '@/lib/format'
import { useAsync } from '@/lib/use-async'
/** El comprobante de una liquidación: se imprime o se guarda como PDF desde el diálogo del navegador. */
function comprobante(l: LiquidacionComedor) {
  const pagada = l.estado === 'pagado' && l.pagada_en ? `Pagada el ${formatDate(l.pagada_en)}` : etiquetasEstadoLiquidacion[l.estado]
  printPDF({
    title: `Liquidación de ${etiquetaMes(l.mes)}`, subtitle: pagada,
    summary: [['Platillos entregados', String(l.platillos_entregados)], ['Mínimo del mes', String(l.platillos_minimos)], ['Monto', `${formatMXN(l.monto)} MXN`]],
    head: ['Concepto', 'Cantidad', 'Precio', 'Importe'],
    body: [['Platillos entregados', l.platillos_entregados, formatMXN(l.precio_por_platillo), formatMXN(l.platillos_entregados * l.precio_por_platillo)], ...(l.diferencia_minimo > 0 ? [['Diferencia por mínimo no alcanzado', l.platillos_faltantes, formatMXN(l.precio_por_platillo), formatMXN(l.diferencia_minimo)]] : [])],
    foot: ['Total', '', '', `${formatMXN(l.monto)} MXN`],
  })
}

export default function Pagos() { const liquidaciones = useAsync(() => listarLiquidacionesComedor(COMEDOR_PORTAL_ID)); const [ver, setVer] = useState<LiquidacionComedor | null>(null); return <div><div className="mb-8 border-b pb-6"><PageTitle eyebrow="Finanzas" title="Pagos" description={<>Consulta tus liquidaciones mensuales y descarga tus comprobantes.</>} /></div><Card className="mt-8"><CardHeader><CardTitle>Liquidaciones</CardTitle></CardHeader><CardContent><table className="w-full text-left text-sm"><thead className="border-b text-xs uppercase text-muted-foreground"><tr><th className="pb-3">Periodo</th><th className="pb-3">Platillos entregados</th><th className="pb-3">Monto</th><th className="pb-3">Estado</th><th /></tr></thead><tbody><FilasEstado columnas={5} cargando={liquidaciones.loading} error={liquidaciones.error} vacio={!liquidaciones.data?.length} mensajeVacio="Todavía no tienes liquidaciones." onReintentar={liquidaciones.reload} />{liquidaciones.data?.map(l => <tr key={l.id} className="border-b last:border-0"><td className="py-4 font-semibold">{etiquetaMes(l.mes)}</td><td className="py-4">{l.platillos_entregados}</td><td className="py-4">{formatMXN(l.monto)} MXN{l.diferencia_minimo > 0 && <p className="text-xs text-muted-foreground">incluye {formatMXN(l.diferencia_minimo)} por mínimo no alcanzado</p>}</td><td className="py-4"><span className="rounded-full bg-primary/15 px-3 py-1 text-xs font-semibold">{etiquetasEstadoLiquidacion[l.estado]}</span></td><td className="py-4 text-right"><Button variant="ghost" size="sm" onClick={() => setVer(l)} aria-label={`Ver detalle de ${etiquetaMes(l.mes)}`}><Eye /> Ver detalle</Button><Button variant="ghost" size="sm" onClick={() => comprobante(l)} aria-label={`Descargar comprobante de ${etiquetaMes(l.mes)}`} title="Imprimir o guardar el comprobante en PDF"><Download /></Button></td></tr>)}</tbody></table></CardContent></Card>{ver && <PanelDetalle etiqueta={etiquetasEstadoLiquidacion[ver.estado]} titulo={`Liquidación de ${etiquetaMes(ver.mes)}`} onCerrar={() => setVer(null)}><GrillaDatos datos={[{ etiqueta: 'Platillos entregados', valor: ver.platillos_entregados }, { etiqueta: 'Precio por platillo', valor: `${formatMXN(ver.precio_por_platillo)} MXN` }, { etiqueta: 'Mínimo del mes', valor: `${ver.platillos_minimos} platillos` }, { etiqueta: 'Faltantes para el mínimo', valor: ver.platillos_faltantes }]} /><div className="mt-5 rounded-lg border p-4 text-sm"><div className="flex justify-between py-1"><span>Entregados × precio</span><span className="tabular-nums">{formatMXN(ver.platillos_entregados * ver.precio_por_platillo)}</span></div><div className="flex justify-between py-1"><span>Diferencia por mínimo no alcanzado</span><span className="tabular-nums">{formatMXN(ver.diferencia_minimo)}</span></div><div className="mt-2 flex justify-between border-t pt-3 text-base font-bold"><span>Monto a pagar</span><span className="tabular-nums">{formatMXN(ver.monto)} MXN</span></div></div><p className="mt-4 text-sm text-muted-foreground">{ver.estado === 'pagado' && ver.pagada_en ? `Pagada el ${formatDate(ver.pagada_en)}.` : 'Todavía no se paga: EasyLunch la marcará como pagada al hacer la transferencia.'}</p></PanelDetalle>}</div> }
