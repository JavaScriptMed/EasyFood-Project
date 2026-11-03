'use client'

import { PageTitle } from '@/components/panel'
import { Download } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { FilasEstado } from '@/components/estados'
import { listarLiquidacionesComedor } from '@/lib/api'
import { COMEDOR_PORTAL_ID } from '@/lib/config'
import { etiquetasEstadoLiquidacion } from '@/lib/etiquetas'
import { etiquetaMes, formatMXN } from '@/lib/format'
import { useAsync } from '@/lib/use-async'
export default function Pagos() { const liquidaciones = useAsync(() => listarLiquidacionesComedor(COMEDOR_PORTAL_ID)); return <div><div className="mb-8 border-b pb-6"><PageTitle eyebrow="Finanzas" title="Pagos" description={<>Consulta tus liquidaciones mensuales y descarga tus comprobantes.</>} /></div><Card className="mt-8"><CardHeader><CardTitle>Liquidaciones</CardTitle></CardHeader><CardContent><table className="w-full text-left text-sm"><thead className="border-b text-xs uppercase text-muted-foreground"><tr><th className="pb-3">Periodo</th><th className="pb-3">Platillos entregados</th><th className="pb-3">Monto</th><th className="pb-3">Estado</th><th /></tr></thead><tbody><FilasEstado columnas={5} cargando={liquidaciones.loading} error={liquidaciones.error} vacio={!liquidaciones.data?.length} mensajeVacio="Todavía no tienes liquidaciones." onReintentar={liquidaciones.reload} />{liquidaciones.data?.map(l => <tr key={l.id} className="border-b last:border-0"><td className="py-4 font-semibold">{etiquetaMes(l.mes)}</td><td className="py-4">{l.platillos_entregados}</td><td className="py-4">{formatMXN(l.monto)} MXN{l.diferencia_minimo > 0 && <p className="text-xs text-muted-foreground">incluye {formatMXN(l.diferencia_minimo)} por mínimo no alcanzado</p>}</td><td className="py-4"><span className="rounded-full bg-primary/15 px-3 py-1 text-xs font-semibold">{etiquetasEstadoLiquidacion[l.estado]}</span></td><td className="py-4 text-right"><Button variant="ghost" size="sm"><Download /></Button></td></tr>)}</tbody></table></CardContent></Card></div> }
