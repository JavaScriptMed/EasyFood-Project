'use client'

import { useReducedMotion } from 'motion/react'
import { useEffect, useRef } from 'react'

/* ------------------------------------------------------------------------------------------------ ingredientes flotantes */

type Ingrediente = {
  src: string
  /** Posición de reposo dentro del contenedor, en %. */
  x: number
  y: number
  /** Tamaño en px (a 1000 px de ancho de contenedor; se escala con él). */
  tam: number
  rot: number
  /** Qué tanto se aparta del cursor (los de adelante, más) y cuánto se desenfoca (los de atrás). */
  prof: number
  /** Si cae como en cascada (px por segundo) en lugar de solo flotar. */
  cae?: number
}

const INGREDIENTES: Ingrediente[] = [
  { src: 'aguacate', x: 60, y: 9, tam: 150, rot: 18, prof: 1 },
  { src: 'aguacate-rodaja', x: 19, y: 27, tam: 118, rot: -24, prof: 0.9 },
  { src: 'tomate-mitad', x: 70, y: 27, tam: 66, rot: 12, prof: 0.8 },
  { src: 'tomate', x: 41, y: 20, tam: 70, rot: -8, prof: 1 },
  { src: 'tomate', x: 90, y: 42, tam: 56, rot: 20, prof: 0.7 },
  { src: 'tomate-mitad', x: 8, y: 50, tam: 52, rot: -30, prof: 0.6 },
  { src: 'albahaca', x: 31, y: 8, tam: 86, rot: -28, prof: 0.9 },
  { src: 'albahaca', x: 93, y: 72, tam: 80, rot: 40, prof: 0.8 },
  { src: 'albahaca', x: 7, y: 76, tam: 74, rot: -20, prof: 0.7 },
  { src: 'cilantro', x: 88, y: 14, tam: 84, rot: 22, prof: 0.9 },
  { src: 'cilantro', x: 14, y: 62, tam: 76, rot: -12, prof: 0.7 },
  { src: 'rabano', x: 25, y: 18, tam: 48, rot: 10, prof: 0.8 },
  { src: 'rabano', x: 77, y: 56, tam: 40, rot: -16, prof: 0.6 },
  { src: 'calabacin', x: 50, y: 33, tam: 56, rot: 14, prof: 0.9 },
  { src: 'chile', x: 4, y: 32, tam: 50, rot: 0, prof: 0.6 },
  { src: 'elote', x: 56, y: 22, tam: 28, rot: 20, prof: 1, cae: 34 },
  { src: 'elote', x: 66, y: 4, tam: 24, rot: -10, prof: 1, cae: 46 },
  { src: 'elote', x: 80, y: 34, tam: 26, rot: 35, prof: 1, cae: 40 },
  { src: 'elote', x: 36, y: 36, tam: 22, rot: -25, prof: 1, cae: 52 },
  { src: 'elote', x: 94, y: 28, tam: 22, rot: 15, prof: 1, cae: 38 },
  { src: 'elote', x: 18, y: 44, tam: 24, rot: 60, prof: 1, cae: 44 },
  { src: 'elote', x: 46, y: 2, tam: 20, rot: -40, prof: 1, cae: 48 },
]

/** Los ingredientes se dibujan a este tamaño sobre el de la tabla (más presencia alrededor del bowl). */
const ESCALA = 1.5

/** Pseudoaleatorio estable (el servidor y el cliente deben dibujar lo mismo). */
const azar = (n: number) => { const v = Math.sin(n * 127.1 + 311.7) * 43758.5453; return v - Math.floor(v) }

/**
 * Ingredientes en 3D flotando sobre el bowl. Cada uno flota, algunos caen en cascada, y todos se apartan del cursor
 * (y salen disparados con un clic o un toque) para luego volver a su sitio con un resorte. Si el usuario pide «menos movimiento»
 * quedan quietos en su lugar.
 */
export function IngredientesFlotantes() {
  const reducido = useReducedMotion()
  const caja = useRef<HTMLDivElement>(null)
  const nodos = useRef<(HTMLImageElement | null)[]>([])

  useEffect(() => {
    const el = caja.current
    if (!el || reducido) return
    const n = INGREDIENTES.length
    const est = INGREDIENTES.map((_, i) => ({ ox: 0, oy: 0, vx: 0, vy: 0, or: 0, vr: 0, caida: azar(i + 3) * 120, fase: azar(i + 9) * 6.28 }))
    const puntero = { x: -9999, y: -9999, visible: false }
    let dentro = true
    let raf = 0
    let ultimo = performance.now()
    const rect = { w: 1, h: 1, l: 0, t: 0 }
    const medir = () => { const r = el.getBoundingClientRect(); rect.w = r.width; rect.h = r.height; rect.l = r.left; rect.t = r.top }

    const alMover = (e: PointerEvent) => { puntero.x = e.clientX; puntero.y = e.clientY; puntero.visible = e.pointerType === 'mouse' }
    const fuera = () => { puntero.visible = false }
    /** Un clic o un toque cerca de los ingredientes los dispersa. */
    const rafaga = (e: PointerEvent) => {
      medir()
      for (let i = 0; i < n; i++) {
        const cx = rect.l + (INGREDIENTES[i].x / 100) * rect.w + est[i].ox
        const cy = rect.t + (INGREDIENTES[i].y / 100) * rect.h + est[i].oy
        const dx = cx - e.clientX
        const dy = cy - e.clientY
        const d = Math.hypot(dx, dy) || 1
        if (d < 340) { const f = (1 - d / 340) * 900 * INGREDIENTES[i].prof; est[i].vx += (dx / d) * f; est[i].vy += (dy / d) * f; est[i].vr += (azar(i) - 0.5) * 900 }
      }
    }

    const frame = (ahora: number) => {
      const dt = Math.min(0.04, (ahora - ultimo) / 1000)
      ultimo = ahora
      const t = ahora / 1000
      medir()
      const escala = rect.w / 1000
      for (let i = 0; i < n; i++) {
        const p = INGREDIENTES[i]
        const s = est[i]
        const cx = rect.l + (p.x / 100) * rect.w + s.ox
        const cy = rect.t + (p.y / 100) * rect.h + s.oy
        if (puntero.visible) {
          const dx = cx - puntero.x
          const dy = cy - puntero.y
          const d = Math.hypot(dx, dy) || 1
          const radio = 210 * Math.max(0.7, escala + 0.2)
          if (d < radio) {
            const f = Math.pow(1 - d / radio, 1.5) * 6200 * p.prof
            s.vx += (dx / d) * f * dt
            s.vy += (dy / d) * f * dt
            s.vr += (dx > 0 ? 1 : -1) * f * 0.35 * dt
          }
        }
        // resorte hacia el reposo
        s.vx += (-26 * s.ox - 7 * s.vx) * dt
        s.vy += (-26 * s.oy - 7 * s.vy) * dt
        s.vr += (-26 * s.or - 6 * s.vr) * dt
        s.ox += s.vx * dt
        s.oy += s.vy * dt
        s.or += s.vr * dt
        // flotar y caer
        const fx = Math.sin(t * (0.45 + (i % 5) * 0.07) + s.fase) * (6 + p.prof * 7)
        const fy = Math.cos(t * (0.5 + (i % 4) * 0.08) + s.fase * 1.3) * (7 + p.prof * 8)
        let caida = 0
        let opacidad = 1
        if (p.cae) {
          s.caida += p.cae * dt
          const recorrido = rect.h * 0.62
          if (s.caida > recorrido) s.caida = -rect.h * 0.04
          caida = s.caida
          opacidad = Math.max(0, Math.min(1, s.caida < 0 ? 0 : Math.min(s.caida / 60, (recorrido - s.caida) / 90, 1)))
        }
        const giro = p.rot + Math.sin(t * 0.6 + s.fase) * 9 + s.or + (p.cae ? t * 40 * (i % 2 ? 1 : -1) : 0)
        const nodo = nodos.current[i]
        if (nodo) {
          nodo.style.transform = `translate3d(${(s.ox + fx).toFixed(1)}px, ${(s.oy + fy + caida).toFixed(1)}px, 0) rotate(${giro.toFixed(1)}deg)`
          nodo.style.opacity = String(opacidad)
        }
      }
      if (dentro) raf = requestAnimationFrame(frame)
    }

    const observador = new IntersectionObserver(([e]) => {
      dentro = e.isIntersecting
      if (dentro) { ultimo = performance.now(); cancelAnimationFrame(raf); raf = requestAnimationFrame(frame) }
    })
    observador.observe(el)
    window.addEventListener('pointermove', alMover, { passive: true })
    window.addEventListener('pointerdown', rafaga, { passive: true })
    document.documentElement.addEventListener('pointerleave', fuera)
    raf = requestAnimationFrame(frame)
    return () => {
      cancelAnimationFrame(raf)
      observador.disconnect()
      window.removeEventListener('pointermove', alMover)
      window.removeEventListener('pointerdown', rafaga)
      document.documentElement.removeEventListener('pointerleave', fuera)
    }
  }, [reducido])

  return (
    <div ref={caja} aria-hidden="true" className="pointer-events-none absolute inset-0">
      {INGREDIENTES.map((p, i) => (
        <img
          key={i}
          ref={(el) => { nodos.current[i] = el }}
          src={`/hero/ingredientes/${p.src}.svg`}
          alt=""
          draggable={false}
          loading="eager"
          className="absolute will-change-transform"
          style={{
            left: `${p.x}%`,
            top: `${p.y}%`,
            width: `${(p.tam * ESCALA) / 10}%`,
            marginLeft: `${-(p.tam * ESCALA) / 20}%`,
            marginTop: `${-(p.tam * ESCALA) / 20}%`,
            transform: `rotate(${p.rot}deg)`,
            opacity: p.cae ? 0 : 1,
            filter: `${p.prof < 0.7 ? 'blur(1.4px) ' : ''}drop-shadow(0 12px 14px rgba(0,0,0,0.45))`,
          }}
        />
      ))}
    </div>
  )
}

/* ------------------------------------------------------------------------------------------------ polvo dorado en canvas */

type Particula = { x: number; y: number; vx: number; vy: number; r: number; a: number; fase: number; vida?: number; chispa?: boolean }

/**
 * Polvo dorado sobre todo el hero: sube lento, se aparta del cursor y brilla al acercarse. El cursor deja una estela de destellos
 * y un clic suelta una ráfaga. Dibuja con sprites precalculados (sin sombras por partícula) y se detiene cuando no se ve.
 */
export function PolvoDorado() {
  const reducido = useReducedMotion()
  const lienzo = useRef<HTMLCanvasElement>(null)

  useEffect(() => {
    const cv = lienzo.current
    const ctx = cv?.getContext('2d')
    if (!cv || !ctx) return
    let ancho = 0
    let alto = 0
    let dpr = 1
    let particulas: Particula[] = []
    const chispas: Particula[] = []
    const puntero = { x: -9999, y: -9999, visible: false, ultimaChispa: 0 }
    let raf = 0
    let dentro = true
    let ultimo = performance.now()

    const sprite = (color: string) => {
      const s = document.createElement('canvas'); s.width = s.height = 64
      const c = s.getContext('2d')!
      const g = c.createRadialGradient(32, 32, 0, 32, 32, 32)
      g.addColorStop(0, '#ffffff'); g.addColorStop(0.18, color); g.addColorStop(0.5, color.replace(')', ',0.35)').replace('rgb', 'rgba')); g.addColorStop(1, 'rgba(224,168,19,0)')
      c.fillStyle = g; c.fillRect(0, 0, 64, 64)
      return s
    }
    const dorado = sprite('rgb(255,208,92)')
    const crema = sprite('rgb(255,238,180)')

    const crear = () => {
      const cantidad = Math.round(Math.min(110, Math.max(26, (ancho * alto) / 17000)))
      particulas = Array.from({ length: cantidad }, (_, i) => ({
        x: azar(i + 1) * ancho, y: azar(i + 77) * alto, vx: (azar(i + 5) - 0.5) * 8, vy: -(6 + azar(i + 11) * 16),
        r: 1.2 + azar(i + 21) * 2.6, a: 0.35 + azar(i + 31) * 0.6, fase: azar(i + 41) * 6.28,
      }))
    }
    const ajustar = () => {
      const r = cv.getBoundingClientRect()
      dpr = Math.min(2, window.devicePixelRatio || 1)
      ancho = r.width; alto = r.height
      cv.width = Math.round(ancho * dpr); cv.height = Math.round(alto * dpr)
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
      crear()
    }
    ajustar()
    const observadorTamano = new ResizeObserver(ajustar)
    observadorTamano.observe(cv)

    const alMover = (e: PointerEvent) => {
      const r = cv.getBoundingClientRect()
      puntero.x = e.clientX - r.left; puntero.y = e.clientY - r.top
      puntero.visible = e.pointerType === 'mouse' && puntero.y >= 0 && puntero.y <= r.height
      const ahora = performance.now()
      if (puntero.visible && ahora - puntero.ultimaChispa > 28) {
        puntero.ultimaChispa = ahora
        chispas.push({ x: puntero.x, y: puntero.y, vx: (Math.random() - 0.5) * 50, vy: (Math.random() - 0.5) * 50 + 12, r: 1.6 + Math.random() * 2.4, a: 1, fase: 0, vida: 0.9, chispa: true })
        if (chispas.length > 60) chispas.shift()
      }
    }
    const fuera = () => { puntero.visible = false }
    const rafaga = (e: PointerEvent) => {
      const r = cv.getBoundingClientRect()
      const x = e.clientX - r.left
      const y = e.clientY - r.top
      if (y < 0 || y > r.height) return
      for (let i = 0; i < 18; i++) {
        const ang = (i / 18) * 6.28 + Math.random() * 0.4
        const v = 90 + Math.random() * 160
        chispas.push({ x, y, vx: Math.cos(ang) * v, vy: Math.sin(ang) * v, r: 1.8 + Math.random() * 2.8, a: 1, fase: 0, vida: 0.9 + Math.random() * 0.5, chispa: true })
      }
      if (chispas.length > 90) chispas.splice(0, chispas.length - 90)
    }

    const pintar = (s: HTMLCanvasElement, x: number, y: number, r: number, a: number) => {
      ctx.globalAlpha = a
      const d = r * 6
      ctx.drawImage(s, x - d / 2, y - d / 2, d, d)
    }

    const frame = (ahora: number) => {
      const dt = Math.min(0.04, (ahora - ultimo) / 1000)
      ultimo = ahora
      const t = ahora / 1000
      ctx.clearRect(0, 0, ancho, alto)
      ctx.globalCompositeOperation = 'lighter'
      for (const p of particulas) {
        p.x += (p.vx + Math.sin(t * 0.7 + p.fase) * 5) * dt
        p.y += p.vy * dt
        let brillo = 0
        if (puntero.visible) {
          const dx = p.x - puntero.x
          const dy = p.y - puntero.y
          const d = Math.hypot(dx, dy) || 1
          if (d < 150) {
            const f = Math.pow(1 - d / 150, 2)
            p.x += (dx / d) * f * 340 * dt
            p.y += (dy / d) * f * 340 * dt
            brillo = f
          }
        }
        if (p.y < -10) { p.y = alto + 10; p.x = Math.random() * ancho }
        if (p.x < -10) p.x = ancho + 10
        if (p.x > ancho + 10) p.x = -10
        const titileo = 0.55 + 0.45 * Math.sin(t * 2 + p.fase)
        pintar(p.r > 2.4 ? crema : dorado, p.x, p.y, p.r * (1 + brillo * 0.9), Math.min(1, p.a * titileo + brillo * 0.7))
      }
      for (let i = chispas.length - 1; i >= 0; i--) {
        const c = chispas[i]
        c.vida = (c.vida ?? 0) - dt
        if (c.vida <= 0) { chispas.splice(i, 1); continue }
        c.x += c.vx * dt; c.y += c.vy * dt; c.vx *= 0.95; c.vy *= 0.95
        pintar(crema, c.x, c.y, c.r * (0.6 + c.vida), Math.min(1, c.vida * 1.4))
      }
      ctx.globalAlpha = 1
      ctx.globalCompositeOperation = 'source-over'
      if (dentro) raf = requestAnimationFrame(frame)
    }

    if (reducido) {
      // Sin movimiento: se pinta una sola vez, quieto.
      ctx.globalCompositeOperation = 'lighter'
      for (const p of particulas) pintar(dorado, p.x, p.y, p.r, p.a * 0.6)
      return () => observadorTamano.disconnect()
    }
    const observador = new IntersectionObserver(([e]) => {
      dentro = e.isIntersecting
      if (dentro) { ultimo = performance.now(); cancelAnimationFrame(raf); raf = requestAnimationFrame(frame) }
    })
    observador.observe(cv)
    window.addEventListener('pointermove', alMover, { passive: true })
    window.addEventListener('pointerdown', rafaga, { passive: true })
    document.documentElement.addEventListener('pointerleave', fuera)
    raf = requestAnimationFrame(frame)
    return () => {
      cancelAnimationFrame(raf)
      observador.disconnect()
      observadorTamano.disconnect()
      window.removeEventListener('pointermove', alMover)
      window.removeEventListener('pointerdown', rafaga)
      document.documentElement.removeEventListener('pointerleave', fuera)
    }
  }, [reducido])

  return <canvas ref={lienzo} aria-hidden="true" className="pointer-events-none absolute inset-0 size-full" />
}
