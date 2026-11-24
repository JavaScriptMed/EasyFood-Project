'use client'

import { useEffect, useMemo, useRef, useState } from 'react'
import { ImagePlus, Loader2, Upload, X } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { subirImagenPlatillo } from '@/lib/api'
import type { PlatilloDetalle } from '@/lib/types'

export const TIPOS_DE_FOTO = ['image/jpeg', 'image/png', 'image/webp']
export const MAXIMO_FOTO_MB = 2

/** Revisa la foto antes de mandarla (el servidor vuelve a revisarla). Devuelve el motivo si no sirve. */
export function motivoDeFotoInvalida(archivo: File): string | null {
  if (!TIPOS_DE_FOTO.includes(archivo.type)) return 'La foto debe ser JPG, PNG o WebP.'
  if (archivo.size > MAXIMO_FOTO_MB * 1024 * 1024) return `La foto pesa más de ${MAXIMO_FOTO_MB} MB.`
  return null
}

/** Botón para cambiar la foto de un platillo ya guardado. */
export function BotonCambiarFoto({ platilloId, onSubida, className }: { platilloId: string; onSubida: (platillo: PlatilloDetalle) => void; className?: string }) {
  const entrada = useRef<HTMLInputElement>(null)
  const [subiendo, setSubiendo] = useState(false)

  async function elegida(archivo: File | undefined) {
    if (!archivo) return
    const motivo = motivoDeFotoInvalida(archivo)
    if (motivo) return void toast.error(motivo)
    setSubiendo(true)
    try {
      onSubida(await subirImagenPlatillo(platilloId, archivo))
      toast.success('Foto actualizada')
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo subir la foto')
    } finally {
      setSubiendo(false)
      if (entrada.current) entrada.current.value = ''
    }
  }

  return (
    <>
      <input ref={entrada} type="file" accept={TIPOS_DE_FOTO.join(',')} className="sr-only" aria-label="Elegir foto del platillo" onChange={(e) => elegida(e.target.files?.[0])} />
      <Button type="button" variant="outline" size="sm" className={className} disabled={subiendo} onClick={() => entrada.current?.click()}>
        <ImagePlus /> {subiendo ? 'Subiendo…' : 'Cambiar foto'}
      </Button>
    </>
  )
}

/**
 * Zona para elegir la foto de un platillo: se puede arrastrar un archivo encima, hacer clic o usar el teclado (Enter o espacio).
 * Muestra una vista previa. `onChange` recibe el archivo ya revisado (tipo y tamaño) o null si se quita.
 * Con `fotoActual` (platillo ya guardado) muestra la foto que tiene; con `ocupado` avisa que se está subiendo.
 */
export function ZonaDeFoto({ archivo, onChange, fotoActual, ocupado = false, deshabilitada = false }: { archivo: File | null; onChange: (archivo: File | null) => void; fotoActual?: string | null; ocupado?: boolean; deshabilitada?: boolean }) {
  const entrada = useRef<HTMLInputElement>(null)
  const [encima, setEncima] = useState(false)
  const [fallo, setFallo] = useState(false)
  const vista = useMemo(() => (archivo ? URL.createObjectURL(archivo) : null), [archivo])
  useEffect(() => () => { if (vista) URL.revokeObjectURL(vista) }, [vista])
  useEffect(() => setFallo(false), [fotoActual])
  const imagen = vista ?? (fotoActual && !fallo ? fotoActual : null)
  const bloqueada = deshabilitada || ocupado

  function recibir(archivos: FileList | null | undefined) {
    const elegido = archivos?.[0]
    if (!elegido) return
    const motivo = motivoDeFotoInvalida(elegido)
    if (motivo) return void toast.error(motivo)
    onChange(elegido)
  }

  return (
    <div>
      <div
        role="button"
        tabIndex={bloqueada ? -1 : 0}
        aria-disabled={bloqueada}
        aria-label="Elegir o arrastrar la foto del platillo"
        onClick={() => !bloqueada && entrada.current?.click()}
        onKeyDown={(e) => { if (!bloqueada && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); entrada.current?.click() } }}
        onDragOver={(e) => { e.preventDefault(); if (!bloqueada) setEncima(true) }}
        onDragEnter={(e) => { e.preventDefault(); if (!bloqueada) setEncima(true) }}
        onDragLeave={() => setEncima(false)}
        onDrop={(e) => { e.preventDefault(); setEncima(false); if (!bloqueada) recibir(e.dataTransfer.files) }}
        className={`flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-6 text-center text-sm outline-none transition-colors focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-ring ${encima ? 'border-primary bg-primary/10' : 'text-muted-foreground hover:border-primary/60'} ${bloqueada ? 'cursor-not-allowed opacity-60' : ''}`}
      >
        {imagen ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={imagen} alt="Vista previa de la foto del platillo" onError={() => setFallo(true)} className="max-h-40 rounded-lg object-cover" />
        ) : (
          <Upload className="size-6" aria-hidden />
        )}
        <span className="font-medium text-foreground">
          {ocupado ? <span className="inline-flex items-center gap-2"><Loader2 className="size-4 animate-spin" /> Subiendo la foto…</span> : archivo ? archivo.name : fotoActual ? 'Arrastra otra foto o haz clic para cambiarla' : 'Arrastra una foto aquí o haz clic para elegirla'}
        </span>
        <span className="text-xs">JPG, PNG o WebP · hasta {MAXIMO_FOTO_MB} MB</span>
        <input ref={entrada} type="file" accept={TIPOS_DE_FOTO.join(',')} className="sr-only" tabIndex={-1} aria-label="Elegir foto del platillo" disabled={bloqueada} onClick={(e) => e.stopPropagation()} onChange={(e) => { recibir(e.target.files); e.target.value = '' }} />
      </div>
      {archivo && !ocupado && (
        <button type="button" onClick={() => onChange(null)} className="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-muted-foreground hover:text-foreground"><X className="size-3.5" /> Quitar la foto</button>
      )}
    </div>
  )
}
