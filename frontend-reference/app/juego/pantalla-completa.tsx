'use client'

import { Maximize } from 'lucide-react'

/** Pone en pantalla completa el iframe del juego. */
export function BotonPantallaCompleta({ idJuego }: { idJuego: string }) {
  function abrir() {
    const juego = document.getElementById(idJuego)
    juego?.requestFullscreen?.().catch(() => {})
    ;(juego as HTMLIFrameElement | null)?.focus()
  }

  return (
    <button type="button" onClick={abrir} className="inline-flex items-center gap-2 rounded-full bg-lgold px-5 py-2.5 text-sm font-semibold text-lbg transition-colors hover:bg-[#f0bb2c]">
      <Maximize className="size-4" aria-hidden="true" />
      Pantalla completa
    </button>
  )
}
