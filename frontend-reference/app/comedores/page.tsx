'use client'

import { capitalizar } from '@/lib/etiquetas'
import { formatFechaLarga, sumarDias } from '@/lib/format'
import { PageTitle } from '@/components/panel'
import { useState } from 'react'
import { ArrowRight, CheckCircle2, Clock3, PackageCheck, Send, UtensilsCrossed } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { BloqueEstado, PaginaEstado } from '@/components/estados'
import { listarLiquidacionesComedor, listarOrdenesProduccion, obtenerComedor } from '@/lib/api'
import { COMEDOR_PORTAL_ID } from '@/lib/config'
import { etiquetasTiempoComida } from '@/lib/etiquetas'
import { formatMXN } from '@/lib/format'
import { useAsync } from '@/lib/use-async'

const FECHA_PRODUCCION = '2026-10-19'
const cargarInicio = async () => {
  const [comedor, ordenes, liquidaciones] = await Promise.all([obtenerComedor(COMEDOR_PORTAL_ID), listarOrdenesProduccion(COMEDOR_PORTAL_ID, FECHA_PRODUCCION), listarLiquidacionesComedor(COMEDOR_PORTAL_ID)])
  return { comedor, ordenes, liquidaciones }
}
export default function ComedorHome() {
  const [notice, setNotice] = useState('')
  const inicio = useAsync(cargarInicio)
  const comedor = inicio.data?.comedor
  const ordenes = inicio.data?.ordenes ?? []
  const [actual, anterior] = inicio.data?.liquidaciones ?? []
  const entregados = comedor?.platillos_entregados_mes ?? 0
  const variacion = anterior?.platillos_entregados ? Math.round(((entregados - anterior.platillos_entregados) / anterior.platillos_entregados) * 100) : 0
  const minimo = comedor?.contrato.compra_minima_mensual ?? 0
  const porProducir = ordenes.reduce((suma, o) => suma + o.porciones_totales, 0)
  return <div><div className="mb-8 flex flex-wrap items-end justify-between gap-4 border-b pb-6"><div><PageTitle eyebrow={`${capitalizar(formatFechaLarga(sumarDias(FECHA_PRODUCCION, -1)))} de 2026`} title={<>Buenos días, {comedor?.nombre ?? '…'}</>} description={<>Esto es lo que necesita tu cocina para mañana.</>} /></div><Badge variant="outline" className="gap-2 px-3 py-2"><Clock3 className="size-4"/> Corte de pedidos: 05:42:18</Badge></div><PaginaEstado cargando={inicio.loading} error={inicio.error} onReintentar={inicio.reload} bloques={3} />{comedor && <><section className="grid gap-4 md:grid-cols-3"><Metric icon={UtensilsCrossed} label="Platillos a producir mañana" value={String(porProducir)} helper={`${comedor.platillos_publicados} recetas publicadas`}/><Metric icon={PackageCheck} label="Platillos entregados este mes" value={String(entregados)} helper={`${variacion >= 0 ? '+' : ''}${variacion}% contra septiembre`}/><Metric icon={Send} label="Pago estimado del mes" value={formatMXN(actual?.monto ?? entregados * comedor.contrato.precio_por_platillo)} helper={`${entregados} × $${comedor.contrato.precio_por_platillo} MXN${actual?.diferencia_minimo ? ` + ${formatMXN(actual.diferencia_minimo)} por mínimo` : ''}`}/></section><Card className="mt-5"><CardContent className="p-5"><div className="flex flex-wrap items-end justify-between gap-3"><div><p className="text-sm font-semibold">Mínimo mensual del contrato</p><p className="mt-1 text-sm text-muted-foreground">{entregados} / {minimo} platillos</p></div><span className="text-sm font-bold text-primary">{comedor.avance_minimo_porcentaje}%</span></div><div className="mt-3 h-3 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-primary" style={{ width: `${comedor.avance_minimo_porcentaje}%` }}/></div><p className="mt-3 text-xs text-muted-foreground">{entregados < minimo ? `Te faltan ${minimo - entregados} platillos para alcanzar el mínimo contratado.` : 'Ya alcanzaste el mínimo contratado.'}</p></CardContent></Card><section className="mt-5 grid gap-5 lg:grid-cols-[1.2fr_.8fr]"><Card><CardHeader className="flex flex-row items-center justify-between"><div><CardTitle>Órdenes de mañana</CardTitle><p className="mt-1 text-sm text-muted-foreground">Detalle por platillo y horario de recolección.</p></div><Button variant="outline" size="sm" onClick={() => setNotice('Orden de producción abierta')}>Ver producción <ArrowRight /></Button></CardHeader><CardContent className="grid gap-3"><BloqueEstado cargando={false} error={null} vacio={!ordenes.length} mensajeVacio="No hay órdenes de producción para mañana." onReintentar={inicio.reload} />{ordenes.map(orden => <div key={orden.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"><div><p className="font-semibold">{orden.platillo_nombre}</p><p className="mt-1 text-sm text-muted-foreground">{etiquetasTiempoComida[orden.tiempo_comida]} · Recolección {orden.hora_recoleccion}</p></div><Badge variant="secondary">{orden.porciones_totales} porciones</Badge></div>)}</CardContent></Card><Card><CardHeader><CardTitle>Estado de operación</CardTitle></CardHeader><CardContent className="flex flex-col gap-4"><div className="rounded-xl bg-primary/10 p-4"><p className="text-sm font-semibold">Todo en orden</p><p className="mt-1 text-sm text-muted-foreground">No hay porciones sobrevendidas ni órdenes sin confirmar.</p></div><div className="flex items-center gap-3 text-sm"><CheckCircle2 className="size-5 text-success"/> Disponibilidad actualizada</div><div className="flex items-center gap-3 text-sm"><CheckCircle2 className="size-5 text-success"/> {ordenes.length} órdenes listas para cocina</div>{notice && <p className="text-sm font-semibold text-primary">{notice}</p>}</CardContent></Card></section></>}</div> }
function Metric({ icon: Icon, label, value, helper }: { icon: typeof UtensilsCrossed; label: string; value: string; helper: string }) { return <div className="relative overflow-hidden rounded-2xl border bg-card p-5"><div className="flex items-start justify-between gap-3"><p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">{label}</p><span className="grid size-9 shrink-0 place-items-center rounded-xl bg-primary/15"><Icon className="size-[18px]"/></span></div><p className="mt-3 font-display text-[2rem] font-bold leading-none tracking-tight tabular-nums">{value}</p><p className="mt-2 text-xs text-muted-foreground">{helper}</p></div> }
