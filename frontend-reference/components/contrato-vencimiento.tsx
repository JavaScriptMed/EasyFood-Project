'use client'

import Link from 'next/link'
import { useState } from 'react'
import { toast } from 'sonner'
import { CalendarClock, RefreshCw, X } from 'lucide-react'
import { ErrorEstado } from '@/components/estados'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { listarContratosPorVencer } from '@/lib/api'
import { FECHA_HOY } from '@/lib/config'
import { formatDate, sumarMeses } from '@/lib/format'
import { useAsync } from '@/lib/use-async'

export const DIAS_POR_VENCER = 30

/** "Vence en 12 días"; no pinta nada si el contrato vence en más de 30 días. */
export function BadgeVencimiento({ dias, className = '' }: { dias: number; className?: string }) {
  if (dias > DIAS_POR_VENCER) return null
  const texto = dias < 0 ? `Vencido hace ${-dias} ${dias === -1 ? 'día' : 'días'}` : dias === 0 ? 'Vence hoy' : `Vence en ${dias} ${dias === 1 ? 'día' : 'días'}`
  const urgente = dias <= 7
  return (
    <span className={`inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold ${urgente ? 'bg-destructive/10 text-destructive' : 'bg-amber-500/10 text-amber-700'} ${className}`}>
      <CalendarClock className="size-3" aria-hidden="true" />{texto}
    </span>
  )
}

export function FiltroPorVencer({ activo, onChange, cantidad }: { activo: boolean; onChange: (activo: boolean) => void; cantidad?: number }) {
  return (
    <button onClick={() => onChange(!activo)} aria-pressed={activo} className={`flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-xs font-semibold transition ${activo ? 'border-foreground bg-foreground text-background' : 'bg-card text-muted-foreground hover:text-foreground'}`}>
      <CalendarClock className="size-3.5" aria-hidden="true" /> Por vencer ({DIAS_POR_VENCER} días){cantidad !== undefined && <span className="opacity-70">{cantidad}</span>}
    </button>
  )
}

/** Botón "Renovar contrato por 6 meses" con confirmación. `onRenovar` debe lanzar si falla. */
export function RenovarContrato({ nombre, vigenciaFin, onRenovar }: { nombre: string; vigenciaFin: string; onRenovar: () => Promise<unknown> }) {
  const [abierto, setAbierto] = useState(false)
  const [trabajando, setTrabajando] = useState(false)
  const nuevaFecha = sumarMeses(vigenciaFin < FECHA_HOY ? FECHA_HOY : vigenciaFin, 6)

  async function confirmar() {
    setTrabajando(true)
    try {
      await onRenovar()
      toast.success(`Contrato de ${nombre} renovado hasta el ${formatDate(nuevaFecha)}`)
      setAbierto(false)
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo renovar el contrato')
    } finally {
      setTrabajando(false)
    }
  }

  return (
    <>
      <Button variant="outline" className="h-10 px-4" onClick={() => setAbierto(true)}><RefreshCw /> Renovar contrato por 6 meses</Button>
      {abierto && (
        <div className="fixed inset-0 z-[70] grid place-items-center bg-black/40 p-4" onClick={() => !trabajando && setAbierto(false)}>
          <div role="dialog" aria-modal="true" aria-label="Renovar contrato" className="w-full max-w-md rounded-2xl bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-start justify-between gap-3"><h2 className="font-display text-xl font-bold">¿Renovar el contrato por 6 meses?</h2><button onClick={() => setAbierto(false)} disabled={trabajando} aria-label="Cerrar"><X /></button></div>
            <p className="mt-3 text-sm text-muted-foreground">El contrato de <strong className="text-foreground">{nombre}</strong> {vigenciaFin < FECHA_HOY ? 'venció' : 'vence'} el {formatDate(vigenciaFin)}. Con la renovación vencerá el <strong className="text-foreground">{formatDate(nuevaFecha)}</strong>. Se conservan precio, mínimo y demás condiciones.</p>
            <div className="mt-6 flex justify-end gap-2">
              <Button variant="outline" className="h-10 px-4" onClick={() => setAbierto(false)} disabled={trabajando}>Cancelar</Button>
              <Button className="h-10 px-4" onClick={confirmar} disabled={trabajando}>{trabajando ? 'Renovando…' : 'Renovar contrato'}</Button>
            </div>
          </div>
        </div>
      )}
    </>
  )
}

/** Aviso del Dashboard: contratos de empresas y comedores que vencen pronto. No ocupa espacio si no hay ninguno. */
export function AvisoContratosPorVencer() {
  const contratos = useAsync(() => listarContratosPorVencer(DIAS_POR_VENCER))
  if (contratos.loading) return <Skeleton className="mb-4 h-20 rounded-2xl" aria-hidden="true" />
  if (contratos.error) return <ErrorEstado error={contratos.error} onReintentar={contratos.reload} className="mb-4 rounded-2xl border bg-card" />
  if (!contratos.data?.length) return null
  return (
    <section role="status" className="mb-4 rounded-2xl border border-amber-500/30 bg-amber-500/10 p-5">
      <div className="flex items-center gap-3"><div className="grid size-9 place-items-center rounded-xl bg-amber-500/15 text-amber-700"><CalendarClock className="size-5" aria-hidden="true" /></div><div><p className="font-display font-bold">{contratos.data.length === 1 ? '1 contrato por vencer' : `${contratos.data.length} contratos por vencer`}</p><p className="text-sm text-muted-foreground">Vencen en los próximos {DIAS_POR_VENCER} días. Renuévalos desde Empresas o Comedores.</p></div></div>
      <ul className="mt-4 flex flex-wrap gap-2">
        {contratos.data.map((c) => (
          <li key={`${c.tipo}-${c.id}`}><Link href={c.tipo === 'empresa' ? '/admin/empresas' : '/admin/comedores'} className="flex items-center gap-2 rounded-xl border bg-card px-3 py-2 text-sm font-medium transition hover:border-primary"><span className="text-[11px] uppercase text-muted-foreground">{c.tipo === 'empresa' ? 'Empresa' : 'Comedor'}</span>{c.nombre}<BadgeVencimiento dias={c.dias} /></Link></li>
        ))}
      </ul>
    </section>
  )
}
