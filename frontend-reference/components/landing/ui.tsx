'use client'

import { motion, useMotionValue, useReducedMotion, useSpring } from 'motion/react'
import type { PointerEvent, ReactNode } from 'react'

export const ease = [0.22, 1, 0.36, 1] as const
const viewport = { once: true, margin: '0px 0px -12% 0px' } as const

/** Aparece desplazándose suave. Solo usa transform y opacity. */
export function Reveal({ children, delay = 0, className = '', y = 28 }: { children: ReactNode; delay?: number; className?: string; y?: number }) {
  const reducido = useReducedMotion()
  if (reducido) return <div className={className}>{children}</div>
  return <motion.div className={className} initial={{ opacity: 0, y }} whileInView={{ opacity: 1, y: 0 }} viewport={viewport} transition={{ duration: 0.8, ease, delay }}>{children}</motion.div>
}

/** Texto por líneas con efecto de máscara: cada línea sube desde debajo de su caja. */
export function LineasMascara({ lineas, className = '', lineaClassName = '', delay = 0, alMontar = false }: { lineas: ReactNode[]; className?: string; lineaClassName?: string; delay?: number; alMontar?: boolean }) {
  const reducido = useReducedMotion()
  return (
    <span className={`block ${className}`}>
      {lineas.map((linea, i) => (
        <span key={i} className="block overflow-hidden pb-[0.14em] -mb-[0.14em]">
          {reducido ? (
            <span className={`block ${lineaClassName}`}>{linea}</span>
          ) : (
            <motion.span className={`block will-change-transform ${lineaClassName}`} initial={{ y: '115%' }} {...(alMontar ? { animate: { y: 0 } } : { whileInView: { y: 0 }, viewport })} transition={{ duration: 0.9, ease, delay: delay + i * 0.12 }}>{linea}</motion.span>
          )}
        </span>
      ))}
    </span>
  )
}

/** Encabezado de sección: antetítulo y título con máscara. */
export function Encabezado({ eyebrow, titulo, oscuro = false, className = '' }: { eyebrow: string; titulo: ReactNode[]; oscuro?: boolean; className?: string }) {
  return (
    <div className={className}>
      <Reveal><p className={`mb-4 flex items-center gap-3 text-xs font-bold uppercase tracking-[0.24em] ${oscuro ? 'text-lbg/70' : 'text-lgold'}`}><span aria-hidden="true" className={`h-px w-8 ${oscuro ? 'bg-lbg/40' : 'bg-lgold'}`} />{eyebrow}</p></Reveal>
      <h2 className={`font-heading text-[2.1rem] leading-[1.05] sm:text-5xl lg:text-[3.5rem] ${oscuro ? 'text-lbg' : 'text-lcream'}`}><LineasMascara lineas={titulo} /></h2>
    </div>
  )
}

/** El elemento se acerca al cursor. Solo con ratón; en pantallas táctiles no hace nada. */
export function Magnetico({ children, className = '' }: { children: ReactNode; className?: string }) {
  const reducido = useReducedMotion()
  const x = useMotionValue(0)
  const y = useMotionValue(0)
  const sx = useSpring(x, { stiffness: 220, damping: 18, mass: 0.4 })
  const sy = useSpring(y, { stiffness: 220, damping: 18, mass: 0.4 })
  if (reducido) return <div className={className}>{children}</div>

  function mover(e: PointerEvent<HTMLDivElement>) {
    if (e.pointerType !== 'mouse') return
    const r = e.currentTarget.getBoundingClientRect()
    x.set((e.clientX - (r.left + r.width / 2)) * 0.28)
    y.set((e.clientY - (r.top + r.height / 2)) * 0.4)
  }
  function soltar() { x.set(0); y.set(0) }

  return <motion.div className={className} style={{ x: sx, y: sy }} onPointerMove={mover} onPointerLeave={soltar}>{children}</motion.div>
}
