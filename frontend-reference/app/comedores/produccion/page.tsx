'use client'

import { PageTitle } from '@/components/panel'
import { useState } from 'react'
import { toast } from 'sonner'
import { Download, Printer, CheckCircle2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { FilasEstado } from '@/components/estados'
import { listarOrdenesProduccion, marcarOrdenesListas } from '@/lib/api'
import { COMEDOR_PORTAL_ID } from '@/lib/config'
import { formatFechaLarga } from '@/lib/format'
import { useAsync } from '@/lib/use-async'
export default function Produccion() { const [fecha, setFecha] = useState('2026-10-19'); const ordenes = useAsync(() => listarOrdenesProduccion(COMEDOR_PORTAL_ID, fecha), [fecha]); const todasListas = !!ordenes.data?.length && ordenes.data.every(o => o.estado === 'lista'); async function marcarLista() { try { await marcarOrdenesListas(COMEDOR_PORTAL_ID, fecha); toast.success('Producción marcada como lista'); ordenes.reload({ silent: true }) } catch (error) { toast.error(error instanceof Error ? error.message : 'No se pudo marcar la producción como lista') } } return <div><Header title="Órdenes de producción" subtitle="Consolida lo que tu cocina debe preparar para la fecha seleccionada."/><div className="mb-5 flex flex-wrap gap-3"><input type="date" value={fecha} onChange={e => e.target.value && setFecha(e.target.value)} className="h-10 rounded-lg border bg-card px-3"/><Button variant="outline"><Download /> Descargar PDF</Button><Button variant="outline"><Printer /> Imprimir</Button><Button onClick={marcarLista} disabled={!ordenes.data?.length || todasListas}><CheckCircle2 /> {todasListas ? 'Producción lista' : 'Marcar como lista'}</Button></div><Card><CardHeader><CardTitle>Producción · {formatFechaLarga(fecha)}</CardTitle></CardHeader><CardContent><div className="overflow-x-auto"><table className="w-full min-w-[720px] text-left text-sm"><thead className="border-b text-xs uppercase text-muted-foreground"><tr><th className="pb-3">Platillo</th><th className="pb-3">Total</th><th className="pb-3">Empresas</th><th className="pb-3">Individuales</th><th className="pb-3">Recolección</th></tr></thead><tbody><FilasEstado columnas={5} cargando={ordenes.loading} error={ordenes.error} vacio={!ordenes.data?.length} mensajeVacio="No hay órdenes de producción para esta fecha." onReintentar={ordenes.reload} />{ordenes.data?.map(o => <tr key={o.id} className="border-b last:border-0"><td className="py-4 font-semibold">{o.platillo_nombre}</td><td className="py-4">{o.porciones_totales}</td><td className="py-4">{o.porciones_empresas}</td><td className="py-4">{o.porciones_individuales}</td><td className="py-4">{o.hora_recoleccion}</td></tr>)}</tbody></table></div></CardContent></Card></div> }
function Header({ title, subtitle }: { title: string; subtitle: string }) { return <div className="mb-8 border-b pb-6"><PageTitle title={title} description={<>{subtitle}</>} /></div> }
