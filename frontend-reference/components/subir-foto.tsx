'use client'

import { useRef, useState } from 'react'
import { ImagePlus } from 'lucide-react'
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
