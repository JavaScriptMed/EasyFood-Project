'use client'

import Link from 'next/link'
import { capitalizar } from '@/lib/etiquetas'
import { etiquetaMes, formatFechaLarga, sumarDias } from '@/lib/format'
import { PageTitle } from '@/components/panel'
import { useEffect, useState } from 'react'
import { ArrowRight, CheckCircle2, Circle, Clock3, PackageCheck, Send, UtensilsCrossed } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { BloqueEstado, PaginaEstado } from '@/components/estados'
import { listarLiquidacionesComedor, listarOrdenesProduccion, obtenerComedor } from '@/lib/api'
import { COMEDOR_PORTAL_ID, FECHA_OPERACION, HORA_CORTE, USAR_MOCK } from '@/lib/config'
import { etiquetasTiempoComida } from '@/lib/etiquetas'
import { formatMXN } from '@/lib/format'
import { useAsync } from '@/lib/use-async'

/** La fecha para la que cocina el comedor: en la maqueta una fija; con el servidor, el día de operación (hoy o el siguiente día de servicio). */
const FECHA_PRODUCCION = USAR_MOCK ? '2026-10-19' : FECHA_OPERACION
const hoyIso = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` }

/** Cuenta regresiva hasta el corte de pedidos de la fecha de producción: las 18:00 del día anterior. */
function CuentaRegresivaDelCorte({ fecha }: { fecha: string }) {
  const [restante, setRestante] = useState<number | null>(null)
  useEffect(() => {
    const [y, m, d] = sumarDias(fecha, -1).split('-').map(Number)
    const corte = new Date(y, m - 1, d, HORA_CORTE, 0, 0).getTime()
    const tic = () => setRestante(Math.max(0, Math.floor((corte - Date.now()) / 1000)))
    tic()
    const id = window.setInterval(tic, 1000)
    return () => window.clearInterval(id)
  }, [fecha])
  if (restante === null) return null
  const dos = (n: number) => String(n).padStart(2, '0')
  return <Badge variant="outline" className="gap-2 px-3 py-2"><Clock3 className="size-4" />{restante > 0 ? `Corte de pedidos en ${dos(Math.floor(restante / 3600))}:${dos(Math.floor((restante % 3600) / 60))}:${dos(restante % 60)}` : 'Corte de pedidos cerrado'}</Badge>
}
const cargarInicio = async () => {
  const [comedor, ordenes, liquidaciones] = await Promise.all([obtenerComedor(COMEDOR_PORTAL_ID), listarOrdenesProduccion(COMEDOR_PORTAL_ID, FECHA_PRODUCCION), listarLiquidacionesComedor(COMEDOR_PORTAL_ID)])
  return { comedor, ordenes, liquidaciones }
}
export default function ComedorHome() {
  const [saludo, setSaludo] = useState('Hola')
  useEffect(() => { const h = new Date().getHours(); setSaludo(h < 12 ? 'Buenos días' : h < 19 ? 'Buenas tardes' : 'Buenas noches') }, [])
  const inicio = useAsync(cargarInicio)
  const comedor = inicio.data?.comedor
  const ordenes = inicio.data?.ordenes ?? []
  const [actual, anterior] = inicio.data?.liquidaciones ?? []
  const entregados = comedor?.platillos_entregados_mes ?? 0
  const variacion = anterior?.platillos_entregados ? Math.round(((entregados - anterior.platillos_entregados) / anterior.platillos_entregados) * 100) : 0
  const minimo = comedor?.contrato.compra_minima_mensual ?? 0
  const hoy = USAR_MOCK ? sumarDias(FECHA_PRODUCCION, -1) : hoyIso()
  const cuando = FECHA_PRODUCCION === hoy ? 'hoy' : FECHA_PRODUCCION === sumarDias(hoy, 1) ? 'mañana' : formatFechaLarga(FECHA_PRODUCCION)
  const listas = ordenes.filter((o) => o.estado === 'lista').length
  const porProducir = ordenes.reduce((suma, o) => suma + o.porciones_totales, 0)
  return <div><div className="mb-8 flex flex-wrap items-end justify-between gap-4 border-b pb-6"><div><PageTitle eyebrow={capitalizar(formatFechaLarga(hoy))} title={<>{saludo}, {comedor?.nombre ?? '…'}</>} description={<>Esto es lo que necesita tu cocina para {cuando}.</>} /></div><CuentaRegresivaDelCorte fecha={FECHA_PRODUCCION} /></div><PaginaEstado cargando={inicio.loading} error={inicio.error} onReintentar={inicio.reload} bloques={3} />{comedor && <><section className="grid gap-4 md:grid-cols-3"><Metric icon={UtensilsCrossed} label={`Platillos a producir ${cuando}`} value={String(porProducir)} helper={`${comedor.platillos_publicados} recetas publicadas`}/><Metric icon={PackageCheck} label="Platillos entregados este mes" value={String(entregados)} helper={anterior ? `${variacion >= 0 ? '+' : ''}${variacion}% contra ${etiquetaMes(anterior.mes).split(' ')[0].toLowerCase()}` : 'Sin mes anterior para comparar'}/><Metric icon={Send} label="Pago estimado del mes" value={formatMXN(actual?.monto ?? entregados * comedor.contrato.precio_por_platillo)} helper={`${entregados} × $${comedor.contrato.precio_por_platillo} MXN${actual?.diferencia_minimo ? ` + ${formatMXN(actual.diferencia_minimo)} por mínimo` : ''}`}/></section><Card className="mt-5"><CardContent className="p-5"><div className="flex flex-wrap items-end justify-between gap-3"><div><p className="text-sm font-semibold">Mínimo mensual del contrato</p><p className="mt-1 text-sm text-muted-foreground">{entregados} / {minimo} platillos</p></div><span className="text-sm font-bold text-primary">{comedor.avance_minimo_porcentaje}%</span></div><div className="mt-3 h-3 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-primary" style={{ width: `${comedor.avance_minimo_porcentaje}%` }}/></div><p className="mt-3 text-xs text-muted-foreground">{entregados < minimo ? `Te faltan ${minimo - entregados} platillos para alcanzar el mínimo contratado.` : 'Ya alcanzaste el mínimo contratado.'}</p></CardContent></Card><section className="mt-5 grid gap-5 lg:grid-cols-[1.2fr_.8fr]"><Card><CardHeader className="flex flex-row items-center justify-between"><div><CardTitle>Órdenes de {cuando}</CardTitle><p className="mt-1 text-sm text-muted-foreground">Detalle por platillo y horario de recolección.</p></div><Button variant="outline" size="sm" render={<Link href="/comedores/produccion" />}>Ver producción <ArrowRight /></Button></CardHeader><CardContent className="grid gap-3"><BloqueEstado cargando={false} error={null} vacio={!ordenes.length} mensajeVacio={`No hay órdenes de producción para ${cuando}.`} onReintentar={inicio.reload} />{ordenes.map(orden => <div key={orden.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"><div><p className="font-semibold">{orden.platillo_nombre}</p><p className="mt-1 text-sm text-muted-foreground">{etiquetasTiempoComida[orden.tiempo_comida]} · Recolección {orden.hora_recoleccion}</p></div><Badge variant="secondary">{orden.porciones_totales} porciones</Badge></div>)}</CardContent></Card><Card><CardHeader><CardTitle>Estado de operación</CardTitle></CardHeader><CardContent className="flex flex-col gap-4"><div className="flex items-center gap-3 text-sm">{ordenes.length > 0 && listas === ordenes.length ? <CheckCircle2 className="size-5 text-success" /> : <Circle className="size-5 text-muted-foreground" />} {ordenes.length ? `${listas} de ${ordenes.length} órdenes marcadas como listas` : `Sin órdenes de producción para ${cuando}`}</div><Button variant="outline" size="sm" render={<Link href="/comedores/disponibilidad" />}>Revisar disponibilidad <ArrowRight /></Button></CardContent></Card></section></>}</div> }
function Metric({ icon: Icon, label, value, helper }: { icon: typeof UtensilsCrossed; label: string; value: string; helper: string }) { return <div className="relative overflow-hidden rounded-2xl border bg-card p-5"><div className="flex items-start justify-between gap-3"><p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">{label}</p><span className="grid size-9 shrink-0 place-items-center rounded-xl bg-primary/15"><Icon className="size-[18px]"/></span></div><p className="mt-3 font-display text-[2rem] font-bold leading-none tracking-tight tabular-nums">{value}</p><p className="mt-2 text-xs text-muted-foreground">{helper}</p></div> }
