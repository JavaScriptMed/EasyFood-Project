'use client'

import { Maximize } from 'lucide-react'

/** `lock` existe en Android pero no viene en los tipos del DOM de TypeScript. */
type OrientacionBloqueable = ScreenOrientation & { lock?: (orientacion: 'landscape') => Promise<void> }

/** Pone en pantalla completa el iframe del juego y, en celular, intenta girarlo a horizontal. */
export function BotonPantallaCompleta({ idJuego }: { idJuego: string }) {
  async function abrir() {
    const juego = document.getElementById(idJuego) as HTMLIFrameElement | null
    if (!juego) return
    try {
      await juego.requestFullscreen()
      await (screen.orientation as OrientacionBloqueable | undefined)?.lock?.('landscape')
    } catch {
      // iPhone no permite pantalla completa en un iframe y algunos navegadores no bloquean la orientación.
    }
    juego.focus()
  }

  return (
    <button type="button" onClick={abrir} className="inline-flex items-center gap-2 rounded-full bg-lgold px-5 py-2.5 text-sm font-semibold text-lbg transition-colors hover:bg-[#f0bb2c]">
      <Maximize className="size-4" aria-hidden="true" />
      Pantalla completa
    </button>
  )
}
