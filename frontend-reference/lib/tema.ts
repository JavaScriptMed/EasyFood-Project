'use client'

import { useSyncExternalStore } from 'react'

export type Tema = 'claro' | 'oscuro' | 'auto'

export const CLAVE_TEMA = 'easylunch-tema'

/** Se ejecuta en <head> antes de pintar, para que no haya destello del tema equivocado. */
export const scriptTema = `(function(){try{var t=localStorage.getItem('${CLAVE_TEMA}')||'auto';var d=t==='oscuro'||(t==='auto'&&matchMedia('(prefers-color-scheme: dark)').matches);var r=document.documentElement;r.classList.add(d?'dark':'light');r.style.colorScheme=d?'dark':'light'}catch(e){}})()`

const oyentes = new Set<() => void>()

export function leerTema(): Tema {
  try {
    const guardado = window.localStorage.getItem(CLAVE_TEMA)
    return guardado === 'claro' || guardado === 'oscuro' ? guardado : 'auto'
  } catch {
    return 'auto'
  }
}

export const esOscuro = (tema: Tema) => tema === 'oscuro' || (tema === 'auto' && window.matchMedia('(prefers-color-scheme: dark)').matches)

function aplicar(tema: Tema) {
  const oscuro = esOscuro(tema)
  const raiz = document.documentElement
  raiz.classList.toggle('dark', oscuro)
  raiz.classList.toggle('light', !oscuro)
  raiz.style.colorScheme = oscuro ? 'dark' : 'light'
}

export function guardarTema(tema: Tema) {
  try { window.localStorage.setItem(CLAVE_TEMA, tema) } catch {}
  aplicar(tema)
  oyentes.forEach((o) => o())
}

function suscribir(aviso: () => void) {
  oyentes.add(aviso)
  const sistema = window.matchMedia('(prefers-color-scheme: dark)')
  const alCambiarSistema = () => { if (leerTema() === 'auto') { aplicar('auto'); aviso() } }
  sistema.addEventListener('change', alCambiarSistema)
  return () => { oyentes.delete(aviso); sistema.removeEventListener('change', alCambiarSistema) }
}

/** Tema elegido ("auto" sigue al sistema) y si en este momento se ve oscuro. */
export function useTema() {
  const tema = useSyncExternalStore(suscribir, leerTema, () => 'auto' as Tema)
  const oscuro = useSyncExternalStore(suscribir, () => esOscuro(leerTema()), () => false)
  return { tema, oscuro, setTema: guardarTema }
}
