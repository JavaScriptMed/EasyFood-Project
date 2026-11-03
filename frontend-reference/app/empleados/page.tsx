'use client'

import { useState } from 'react'
import { toast } from 'sonner'
import { Check, Clock3, Flame, MapPin, ShieldAlert, UtensilsCrossed } from 'lucide-react'
import { BloqueEstado, ErrorEstado, FilasEstado } from '@/components/estados'
import { PageTitle, Pill } from '@/components/panel'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { elegirPlatillo, listarMisElecciones, obtenerMiMenu } from '@/lib/api'
import { EMPLEADO_PORTAL_ID } from '@/lib/config'
import { etiquetasTiempoComida } from '@/lib/etiquetas'
import { formatDate, formatFechaLarga, formatMXN } from '@/lib/format'
import { formatCountdown, secondsUntilHour, useNow } from '@/lib/live'
import type { OpcionMenu } from '@/lib/types'
import { useAsync } from '@/lib/use-async'

const fondos = ['#dca94e', '#72a66c', '#d77e55', '#739bb0', '#a98bc4']

export default function MiComida() {
  const menu = useAsync(() => obtenerMiMenu(EMPLEADO_PORTAL_ID))
  const historial = useAsync(() => listarMisElecciones(EMPLEADO_PORTAL_ID))
  const now = useNow()
  const [eligiendo, setEligiendo] = useState<string | null>(null)
  const m = menu.data

  const [hora, minuto] = (m?.hora_corte ?? '18:00').split(':').map(Number)
  const segundos = now && m ? secondsUntilHour(now, hora, minuto) : null
  const puedeElegir = !!m && !m.cerrado && m.empleado.activo

  async function elegir(opcion: OpcionMenu) {
    if (!m) return
    setEligiendo(opcion.platillo.id)
    try {
      await elegirPlatillo(EMPLEADO_PORTAL_ID, m.fecha, opcion.platillo.tiempo_comida, opcion.platillo.id)
      toast.success(`Elegiste ${opcion.platillo.nombre}`, { description: `Te lo entregamos el ${formatFechaLarga(m.fecha)}.` })
      menu.reload({ silent: true })
      historial.reload({ silent: true })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo guardar tu elección')
      menu.reload({ silent: true })
    } finally {
      setEligiendo(null)
    }
  }

  return (
    <div>
      <div className="mb-8 border-b pb-6"><PageTitle eyebrow="Mi comida" title={m ? `Hola, ${m.empleado.nombre.split(' ')[0]}` : 'Mi comida'} description={m ? `${m.empleado.empresa_nombre} · elige tu platillo para el ${formatFechaLarga(m.fecha)}.` : 'Elige tu platillo para el próximo día de servicio.'} /></div>

      {menu.loading && <div className="flex flex-col gap-5" aria-hidden="true"><Skeleton className="h-40 rounded-2xl" /><div className="grid gap-5 sm:grid-cols-2"><Skeleton className="h-80 rounded-2xl" /><Skeleton className="h-80 rounded-2xl" /></div></div>}
      {menu.error && <ErrorEstado error={menu.error} onReintentar={menu.reload} className="rounded-2xl border bg-card" />}

      {m && (
        <>
          <section className="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div className="grid gap-6 p-6 md:grid-cols-[1.3fr_1fr] md:p-7">
              <div>
                <div className="flex flex-wrap items-center gap-2">
                  {m.eleccion ? <Pill tono="success">{m.eleccion.origen === 'manual' ? 'Elegiste tu platillo' : 'Platillo asignado por defecto'}</Pill> : <Pill tono={m.cerrado || !m.empleado.activo ? 'neutral' : 'warning'}>{m.cerrado ? 'Pedidos cerrados' : 'Aún no eliges'}</Pill>}
                </div>
                <h2 className="mt-3 font-display text-2xl font-bold tracking-tight">{m.eleccion ? m.eleccion.platillo_nombre : 'Escoge lo que se te antoje'}</h2>
                <p className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground"><span className="flex items-center gap-1.5"><MapPin className="size-4" /> Llega a tu empresa a las {m.hora_entrega} h</span><span className="flex items-center gap-1.5"><Clock3 className="size-4" /> {m.cerrado ? 'Corte ejecutado' : segundos === null ? 'Cierra a las ' + m.hora_corte + ' h' : <>Cierra en <strong className="font-mono tabular-nums text-foreground">{formatCountdown(segundos)}</strong></>}</span></p>
                {m.eleccion && !m.cerrado && <p className="mt-3 text-sm text-muted-foreground">Puedes cambiarla cuantas veces quieras antes del corte.</p>}
              </div>
              <dl className="grid grid-cols-3 gap-3 text-sm">
                {[['Precio', formatMXN(m.precio)], [`Empresa (${m.porcentaje_subsidio} %)`, formatMXN(m.empresa_cubre)], ['Tú pagas', formatMXN(m.empleado_paga)]].map(([k, v], i) => (
                  <div key={k} className={`rounded-xl p-3 ${i === 2 ? 'bg-primary/20' : 'bg-muted/60'}`}><dt className="text-[11px] leading-tight text-muted-foreground">{k}</dt><dd className="mt-1.5 font-display text-lg font-bold tabular-nums">{v}</dd></div>
                ))}
              </dl>
            </div>
            {!m.empleado.activo && <p role="status" className="flex items-center gap-2 border-t bg-destructive/5 px-6 py-3 text-sm text-destructive"><ShieldAlert className="size-4" /> Tu cuenta está inactiva y no puedes elegir platillo. Habla con RR. HH. para reactivarla.</p>}
            {m.motivo_cerrado === 'empresa_cerrada' && <p role="status" className="flex items-center gap-2 border-t bg-amber-500/10 px-6 py-3 text-sm text-amber-700"><ShieldAlert className="size-4" /> Tu empresa no tiene servicio ese día.</p>}
            {m.motivo_cerrado === 'corte' && <p role="status" className="flex items-center gap-2 border-t bg-muted/50 px-6 py-3 text-sm text-muted-foreground"><Check className="size-4" /> Los pedidos de este día ya se cerraron; tu elección quedó registrada.</p>}
          </section>

          <section className="mt-8">
            <h2 className="font-display text-xl font-bold tracking-tight">Menú del {formatFechaLarga(m.fecha)}</h2>
            <p className="mt-1 text-sm text-muted-foreground">{m.opciones.length ? `${m.opciones.length} opciones con cupo limitado.` : ''}</p>
            <div className="mt-4">
              <BloqueEstado variante="tarjetas" cargando={false} error={null} vacio={!m.opciones.length} mensajeVacio="Todavía no hay menú publicado para este día. Vuelve más tarde." onReintentar={menu.reload} />
              <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                {m.opciones.map((op, i) => {
                  const elegido = m.eleccion?.platillo_id === op.platillo.id
                  const p = op.platillo
                  const uso = Math.round((op.porciones_tomadas / op.porciones_maximas) * 100)
                  return (
                    <article key={p.id} className={`group flex flex-col overflow-hidden rounded-2xl border bg-card transition ${elegido ? 'ring-2 ring-primary' : 'hover:-translate-y-0.5'}`}>
                      <div className="relative h-36" style={p.foto_url ? undefined : { background: `linear-gradient(135deg, ${fondos[i % fondos.length]}, #172630)` }}>
                        {p.foto_url && <img src={p.foto_url} alt={p.nombre} className="h-full w-full object-cover" />}
                        <span className="absolute left-3 top-3 rounded-full bg-background/90 px-2.5 py-1 text-[11px] font-semibold backdrop-blur">{etiquetasTiempoComida[p.tiempo_comida]}</span>
                        {elegido && <span className="absolute right-3 top-3 grid size-7 place-items-center rounded-full bg-primary text-primary-foreground shadow"><Check className="size-4" /></span>}
                      </div>
                      <div className="flex flex-1 flex-col p-5">
                        <h3 className="font-display text-lg font-bold leading-snug">{p.nombre}</h3>
                        <p className="mt-1.5 line-clamp-2 text-sm text-muted-foreground">{p.descripcion}</p>
                        <div className="mt-3 flex flex-wrap items-center gap-1.5 text-xs">
                          <span className="flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 font-medium"><Flame className="size-3" /> {p.calorias} kcal</span>
                          {p.alergenos.map((a) => <span key={a} className="rounded-full border px-2.5 py-1 text-muted-foreground">{a}</span>)}
                        </div>
                        <div className="mt-4">
                          <div className="mb-1.5 flex justify-between text-xs text-muted-foreground"><span>{op.agotado ? 'Agotado' : `Quedan ${op.porciones_restantes}`}</span><span>{op.porciones_maximas} porciones</span></div>
                          <div className="h-1.5 overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuenow={uso} aria-valuemin={0} aria-valuemax={100} aria-label="Porciones tomadas"><div className={`h-full rounded-full ${uso >= 90 ? 'bg-amber-500' : 'bg-primary'}`} style={{ width: `${uso}%` }} /></div>
                        </div>
                        <div className="mt-5 flex-1" />
                        <Button className="h-10 w-full" variant={elegido ? 'outline' : 'default'} disabled={!puedeElegir || elegido || op.agotado || eligiendo !== null} onClick={() => elegir(op)}>
                          {elegido ? <><Check /> Tu elección</> : eligiendo === p.id ? 'Guardando…' : op.agotado ? 'Agotado' : m.eleccion ? 'Cambiar a este' : <><UtensilsCrossed /> Elegir este platillo</>}
                        </Button>
                      </div>
                    </article>
                  )
                })}
              </div>
            </div>
          </section>
        </>
      )}

      <section className="mt-10">
        <h2 className="font-display text-xl font-bold tracking-tight">Mis últimas comidas</h2>
        <div className="mt-4 overflow-hidden rounded-2xl border bg-card shadow-sm"><div className="overflow-x-auto">
          <table className="w-full min-w-[560px] text-left text-sm">
            <thead className="border-b bg-muted/40 text-xs uppercase text-muted-foreground"><tr><th className="px-5 py-3.5">Fecha</th><th className="px-5 py-3.5">Platillo</th><th className="px-5 py-3.5">Cómo se eligió</th><th className="px-5 py-3.5 text-right">Tú pagas</th></tr></thead>
            <tbody>
              <FilasEstado columnas={4} filas={4} cargando={historial.loading} error={historial.error} vacio={!historial.data?.length} mensajeVacio="Todavía no tienes comidas registradas." onReintentar={historial.reload} />
              {historial.data?.map((e) => (
                <tr key={e.id} className="border-b last:border-0"><td className="whitespace-nowrap px-5 py-3.5 text-muted-foreground">{formatDate(e.fecha)}</td><td className="px-5 py-3.5 font-medium">{e.platillo_nombre}</td><td className="px-5 py-3.5"><Pill tono={e.origen === 'manual' ? 'success' : 'warning'}>{e.origen === 'manual' ? 'Elegido por ti' : 'Asignado por defecto'}</Pill></td><td className="px-5 py-3.5 text-right font-semibold tabular-nums">{formatMXN(e.empleado_paga)}</td></tr>
              ))}
            </tbody>
          </table>
        </div></div>
      </section>
    </div>
  )
}
