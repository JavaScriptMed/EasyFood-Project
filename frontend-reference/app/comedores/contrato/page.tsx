'use client'

import { PageTitle } from '@/components/panel'
import { BadgeCheck, FileText } from 'lucide-react'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { PaginaEstado } from '@/components/estados'
import { obtenerComedor } from '@/lib/api'
import { COMEDOR_PORTAL_ID } from '@/lib/config'
import { formatDate, mesesEntre } from '@/lib/format'
import { useAsync } from '@/lib/use-async'
export default function Contrato() { const datos = useAsync(() => obtenerComedor(COMEDOR_PORTAL_ID)); const kitchen = datos.data; return <div><div className="mb-8 border-b pb-6"><PageTitle eyebrow="Cuenta" title="Mi contrato" description={<>Consulta las condiciones vigentes de tu acuerdo con EasyLunch.</>} /></div><div className="mt-8 max-w-3xl"><PaginaEstado cargando={datos.loading} error={datos.error} onReintentar={datos.reload} bloques={2} />{kitchen && <Card><CardHeader className="flex flex-row items-start justify-between"><div><CardTitle className="flex items-center gap-2">{kitchen.nombre} <BadgeCheck className="size-5 text-primary"/></CardTitle><p className="mt-1 text-sm text-muted-foreground">Contrato {kitchen.id} · Solo lectura</p></div><FileText className="size-6 text-muted-foreground"/></CardHeader><CardContent className="grid gap-5 sm:grid-cols-2"><Item label="Vigencia" value={`${mesesEntre(kitchen.contrato.vigencia_inicio, kitchen.contrato.vigencia_fin)} meses · ${formatDate(kitchen.contrato.vigencia_inicio)} — ${formatDate(kitchen.contrato.vigencia_fin)}`}/><Item label="Precio por platillo" value={`$${kitchen.contrato.precio_por_platillo} MXN fijo`}/><Item label="Compra mínima mensual" value={`${kitchen.contrato.compra_minima_mensual} platillos`}/><Item label="Avance del mes" value={`${kitchen.platillos_entregados_mes} de ${kitchen.contrato.compra_minima_mensual} platillos`}/><div className="sm:col-span-2"><p className="mb-2 text-sm text-muted-foreground">Avance del mínimo mensual</p><div className="h-3 rounded-full bg-muted"><div className="h-full rounded-full bg-primary" style={{ width: `${kitchen.avance_minimo_porcentaje}%` }}/></div></div></CardContent></Card>}</div></div> }
function Item({ label, value }: { label: string; value: string }) { return <div className="rounded-xl border p-4"><p className="text-sm text-muted-foreground">{label}</p><p className="mt-2 font-semibold">{value}</p></div> }
