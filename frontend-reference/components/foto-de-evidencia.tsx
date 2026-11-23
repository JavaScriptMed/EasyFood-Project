"use client"

import { useEffect, useState } from "react"
import { ImageOff } from "lucide-react"
import { Dialog, DialogContent, DialogDescription, DialogTitle } from "@/components/ui/dialog"

const TAMANOS = { md: "h-28 w-full", sm: "h-14 w-20" } as const

/** Lugar de la foto cuando no hay URL, la URL está rota o el archivo ya no existe. */
export function FotoNoDisponible({ tamano = "md" }: { tamano?: keyof typeof TAMANOS }) {
  return (
    <div role="img" aria-label="Foto no disponible" className={`${TAMANOS[tamano]} flex shrink-0 flex-col items-center justify-center gap-1 rounded-lg border border-dashed bg-muted/50 px-1 text-center text-muted-foreground`}>
      <ImageOff className={tamano === "sm" ? "size-4" : "size-6"} aria-hidden />
      <span className={tamano === "sm" ? "text-[10px] leading-tight" : "text-xs"}>Foto no disponible</span>
    </div>
  )
}

/**
 * La foto de evidencia que dejó el repartidor: una miniatura que se abre en grande al hacer clic. Si no hay URL o la imagen no carga
 * (URL rota, archivo ausente) muestra «Foto no disponible» en su lugar y la pantalla sigue funcionando.
 */
export function FotoDeEvidencia({ url, alt, tamano = "md" }: { url: string | null | undefined; alt: string; tamano?: keyof typeof TAMANOS }) {
  const [fallo, setFallo] = useState(false)
  const [falloGrande, setFalloGrande] = useState(false)
  const [abierta, setAbierta] = useState(false)

  useEffect(() => {
    setFallo(false)
    setFalloGrande(false)
  }, [url])

  if (!url || fallo) return <FotoNoDisponible tamano={tamano} />

  return (
    <>
      <button
        type="button"
        onClick={() => setAbierta(true)}
        aria-label={`Ver en grande: ${alt}`}
        title="Ver en grande"
        className={`${TAMANOS[tamano]} group relative shrink-0 overflow-hidden rounded-lg border bg-muted outline-none ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2`}
      >
        {/* eslint-disable-next-line @next/next/no-img-element -- la URL viene de la API (otro dominio) y puede fallar: se maneja a mano */}
        <img src={url} alt={alt} loading="lazy" onError={() => setFallo(true)} className="size-full object-cover transition-transform group-hover:scale-105" />
      </button>
      <Dialog open={abierta} onOpenChange={setAbierta}>
        <DialogContent className="sm:max-w-3xl">
          <DialogTitle>{alt}</DialogTitle>
          <DialogDescription className="sr-only">Foto de evidencia en tamaño grande. Cierra con Escape o con el botón de cerrar.</DialogDescription>
          {falloGrande ? (
            <FotoNoDisponible />
          ) : (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={url} alt={alt} onError={() => setFalloGrande(true)} className="max-h-[75vh] w-full rounded-lg object-contain" />
          )}
        </DialogContent>
      </Dialog>
    </>
  )
}
