'use client'

import dynamic from 'next/dynamic'
import { Component, useCallback, useEffect, useRef, useState, type ReactNode } from 'react'

function Esqueleto() {
  return (
    <div aria-hidden="true" className="absolute inset-0 grid place-items-center">
      <div className="absolute -inset-10 rounded-full bg-[radial-gradient(closest-side,rgba(224,168,19,0.22),transparent)]" />
      <div className="size-[64%] animate-pulse rounded-full border border-lgold/30 bg-[radial-gradient(circle_at_35%_28%,rgba(224,168,19,0.22),rgba(224,168,19,0.05)_70%)]" />
    </div>
  )
}

/** Imagen fija: móvil, "menos movimiento" o equipos sin WebGL. */
function ObjetoEstatico() {
  return (
    <>
      <div aria-hidden="true" className="absolute -inset-10 rounded-full bg-[radial-gradient(closest-side,rgba(224,168,19,0.28),transparent)]" />
      <img src="/hero/lonchera.svg" alt="Lonchera térmica dorada con aguacate, chile y limón" width={600} height={600} className="relative size-full object-contain" />
    </>
  )
}

const Escena3D = dynamic(() => import('./escena-3d'), { ssr: false, loading: () => <Esqueleto /> })

class Limite extends Component<{ alFallar: () => void; children: ReactNode }, { fallo: boolean }> {
  state = { fallo: false }
  static getDerivedStateFromError() { return { fallo: true } }
  componentDidCatch() { this.props.alFallar() }
  render() { return this.state.fallo ? null : this.props.children }
}

function webglDisponible() {
  try {
    const lienzo = document.createElement('canvas')
    return !!(lienzo.getContext('webgl2') || lienzo.getContext('webgl'))
  } catch {
    return false
  }
}

type Modo = 'pendiente' | '3d' | 'estatico'

/** Decide entre la escena 3D y la imagen fija, y pausa el render cuando el objeto sale de pantalla. */
export function ObjetoHero() {
  const [modo, setModo] = useState<Modo>('pendiente')
  const [visible, setVisible] = useState(true)
  const [listo, setListo] = useState(false)
  const contenedor = useRef<HTMLDivElement>(null)
  const alListo = useCallback(() => setListo(true), [])

  useEffect(() => {
    const ancho = window.matchMedia('(min-width: 768px)')
    const reducido = window.matchMedia('(prefers-reduced-motion: reduce)')
    const decidir = () => setModo(ancho.matches && !reducido.matches && webglDisponible() ? '3d' : 'estatico')
    decidir()
    ancho.addEventListener('change', decidir)
    reducido.addEventListener('change', decidir)
    return () => { ancho.removeEventListener('change', decidir); reducido.removeEventListener('change', decidir) }
  }, [])

  useEffect(() => {
    const el = contenedor.current
    if (!el || modo !== '3d') return
    const observador = new IntersectionObserver(([entrada]) => setVisible(entrada.isIntersecting), { threshold: 0.05 })
    observador.observe(el)
    return () => observador.disconnect()
  }, [modo])

  return (
    <div ref={contenedor} role="img" aria-label="Lonchera térmica dorada con aguacate, chile y limón flotando" aria-busy={modo === '3d' && !listo} className="relative mx-auto aspect-square w-[min(72vw,26rem)] lg:w-full lg:max-w-[28rem]">
      {modo === 'estatico' && <ObjetoEstatico />}
      {modo === '3d' && (
        <Limite alFallar={() => setModo('estatico')}>
          <div className={`absolute inset-[-12%] transition-opacity duration-700 ${listo ? 'opacity-100' : 'opacity-0'}`}><Escena3D activa={visible} onListo={alListo} /></div>
          {!listo && <Esqueleto />}
        </Limite>
      )}
      {modo === 'pendiente' && <Esqueleto />}
    </div>
  )
}
