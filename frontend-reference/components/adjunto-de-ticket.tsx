'use client'

import { FileText, X } from 'lucide-react'
import type { MensajeTicket } from '@/lib/types'

export const TIPOS_DE_ADJUNTO = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf']
export const MAXIMO_ADJUNTO_MB = 5

/** Revisa el archivo antes de mandarlo (el servidor vuelve a revisarlo). Devuelve el motivo si no sirve. */
export function motivoDeAdjuntoInvalido(archivo: File): string | null {
  if (!TIPOS_DE_ADJUNTO.includes(archivo.type)) return 'Solo se pueden adjuntar fotos (JPG, PNG o WebP) o PDF.'
  if (archivo.size > MAXIMO_ADJUNTO_MB * 1024 * 1024) return `El archivo pesa más de ${MAXIMO_ADJUNTO_MB} MB.`
  return null
}

/** El archivo elegido, antes de enviarlo: nombre y botón para quitarlo. */
export function AdjuntoElegido({ archivo, onQuitar }: { archivo: File; onQuitar: () => void }) {
  return (
    <div className="mb-2 flex items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2 text-sm">
      <FileText className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
      <span className="min-w-0 flex-1 truncate">{archivo.name}</span>
      <span className="shrink-0 text-xs text-muted-foreground">{(archivo.size / 1024).toFixed(0)} KB</span>
      <button type="button" onClick={onQuitar} aria-label={`Quitar ${archivo.name}`} className="rounded p-1 text-muted-foreground hover:bg-muted"><X className="size-4" /></button>
    </div>
  )
}

/** El archivo de un mensaje ya enviado: la foto se ve en el chat; el PDF es un enlace. */
export function AdjuntoDelMensaje({ adjunto }: { adjunto: NonNullable<MensajeTicket['adjunto']> }) {
  if (adjunto.tipo.startsWith('image/')) {
    return (
      <a href={adjunto.url} target="_blank" rel="noopener noreferrer" className="mt-2 block overflow-hidden rounded-lg">
        <img src={adjunto.url} alt={adjunto.nombre} className="max-h-56 w-full rounded-lg object-cover" />
      </a>
    )
  }
  return (
    <a href={adjunto.url} target="_blank" rel="noopener noreferrer" className="mt-2 flex items-center gap-2 rounded-lg border border-current/20 px-3 py-2 text-xs font-medium underline-offset-2 hover:underline">
      <FileText className="size-4 shrink-0" aria-hidden="true" /> <span className="truncate">{adjunto.nombre}</span>
    </a>
  )
}
