'use client'

import { CircleAlert, Inbox, RotateCcw } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'

type EstadoProps = {
  cargando: boolean
  error: Error | null
  vacio: boolean
  mensajeVacio: string
  onReintentar: () => void
}

/** Cuadro de error con el botón "Reintentar". */
export function ErrorEstado({ error, onReintentar, className = '' }: { error: Error | null; onReintentar: () => void; className?: string }) {
  return (
    <div role="alert" className={`flex flex-col items-center justify-center gap-3 px-5 py-10 text-center ${className}`}>
      <div className="grid size-11 place-items-center rounded-xl bg-destructive/10 text-destructive"><CircleAlert className="size-5" aria-hidden="true" /></div>
      <div>
        <p className="font-semibold">No se pudo cargar la información</p>
        <p className="mt-1 text-sm text-muted-foreground">{error?.message || 'Ocurrió un error inesperado.'}</p>
      </div>
      <Button variant="outline" onClick={onReintentar}><RotateCcw /> Reintentar</Button>
    </div>
  )
}

export function VacioEstado({ mensaje, className = '' }: { mensaje: string; className?: string }) {
  return (
    <div className={`flex flex-col items-center justify-center gap-2 px-5 py-10 text-center text-sm text-muted-foreground ${className}`}>
      <Inbox className="size-5" aria-hidden="true" />
      <p>{mensaje}</p>
    </div>
  )
}

/** Filas de `<tbody>`: skeletons al cargar, error con "Reintentar" o mensaje de vacío. No pinta nada si hay datos. */
export function FilasEstado({ columnas, filas = 5, cargando, error, vacio, mensajeVacio, onReintentar }: EstadoProps & { columnas: number; filas?: number }) {
  if (cargando) {
    return (
      <>
        {Array.from({ length: filas }, (_, i) => (
          <tr key={i} className="border-b last:border-0" aria-hidden="true">
            {Array.from({ length: columnas }, (_, j) => <td key={j} className="px-5 py-4"><Skeleton className={`h-4 ${j === 0 ? 'w-40' : 'w-20'}`} /></td>)}
          </tr>
        ))}
      </>
    )
  }
  if (error) return <tr><td colSpan={columnas}><ErrorEstado error={error} onReintentar={onReintentar} /></td></tr>
  if (vacio) return <tr><td colSpan={columnas}><VacioEstado mensaje={mensajeVacio} /></td></tr>
  return null
}

/** Para tarjetas y listas fuera de una tabla. No pinta nada si hay datos. */
export function BloqueEstado({ variante = 'lista', cantidad = 3, cargando, error, vacio, mensajeVacio, onReintentar, className = '' }: EstadoProps & { variante?: 'lista' | 'tarjetas'; cantidad?: number; className?: string }) {
  if (cargando) {
    return variante === 'tarjetas' ? (
      <div className={`grid gap-5 sm:grid-cols-2 xl:grid-cols-3 ${className}`} aria-hidden="true">
        {Array.from({ length: cantidad }, (_, i) => <Skeleton key={i} className="h-60 rounded-xl" />)}
      </div>
    ) : (
      <div className={`flex flex-col gap-3 ${className}`} aria-hidden="true">
        {Array.from({ length: cantidad }, (_, i) => <Skeleton key={i} className="h-16 rounded-xl" />)}
      </div>
    )
  }
  if (error) return <ErrorEstado error={error} onReintentar={onReintentar} className={`rounded-xl border bg-card ${className}`} />
  if (vacio) return <VacioEstado mensaje={mensajeVacio} className={`rounded-xl border border-dashed ${className}`} />
  return null
}

/** Reemplaza una vista completa (por ejemplo un panel de tarjetas) mientras carga o falla. */
export function PaginaEstado({ cargando, error, onReintentar, bloques = 4 }: { cargando: boolean; error: Error | null; onReintentar: () => void; bloques?: number }) {
  if (cargando) {
    return (
      <div className="flex flex-col gap-4" aria-hidden="true">
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{Array.from({ length: bloques }, (_, i) => <Skeleton key={i} className="h-36 rounded-xl" />)}</div>
        <Skeleton className="h-72 rounded-xl" />
      </div>
    )
  }
  if (error) return <ErrorEstado error={error} onReintentar={onReintentar} className="rounded-xl border bg-card" />
  return null
}
