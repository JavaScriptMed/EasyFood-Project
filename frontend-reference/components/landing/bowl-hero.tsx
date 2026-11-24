'use client'

import { motion, useMotionValue, useReducedMotion, useSpring } from 'motion/react'
import { useEffect, useRef } from 'react'
import { IngredientesFlotantes } from './particulas-hero'

/**
 * El bowl de EasyLunch con resplandor ámbar, flotando suave y con una inclinación 3D que sigue al cursor,
 * rodeado de ingredientes que flotan, caen y se apartan del puntero.
 */
export function BowlHero() {
  const reducido = useReducedMotion()
  const caja = useRef<HTMLDivElement>(null)
  const nx = useMotionValue(0)
  const ny = useMotionValue(0)
  const rotY = useSpring(nx, { stiffness: 70, damping: 14 })
  const rotX = useSpring(ny, { stiffness: 70, damping: 14 })

  useEffect(() => {
    if (reducido) return
    const mover = (e: PointerEvent) => {
      if (e.pointerType !== 'mouse' || !caja.current) return
      const r = caja.current.getBoundingClientRect()
      const x = (e.clientX - (r.left + r.width / 2)) / window.innerWidth
      const y = (e.clientY - (r.top + r.height / 2)) / window.innerHeight
      nx.set(Math.max(-1, Math.min(1, x * 2)) * 11)
      ny.set(Math.max(-1, Math.min(1, y * 2)) * -8)
    }
    window.addEventListener('pointermove', mover, { passive: true })
    return () => window.removeEventListener('pointermove', mover)
  }, [reducido, nx, ny])

  return (
    <div ref={caja} className="relative mx-auto aspect-[1/1.02] w-full max-w-[40rem] [perspective:1400px]">
      {/* resplandor ámbar detrás del plato */}
      <div aria-hidden="true" className="absolute left-1/2 top-[56%] aspect-square w-[118%] -translate-x-1/2 -translate-y-1/2 rounded-full bg-[radial-gradient(closest-side,rgba(245,165,30,0.8),rgba(224,168,19,0.34)_42%,transparent_72%)] blur-[4px]" />
      <div aria-hidden="true" className="absolute left-1/2 top-[64%] h-[10%] w-[78%] -translate-x-1/2 rounded-[50%] bg-black/55 blur-2xl" />

      <motion.div style={reducido ? undefined : { rotateX: rotX, rotateY: rotY, transformStyle: 'preserve-3d' }} className="absolute inset-0">
        <div className="landing-flotar absolute left-1/2 top-[56%] w-[96%] -translate-x-1/2 -translate-y-1/2">
          <img src="/hero/bowl.webp" alt="Bowl con pollo deshebrado, aguacate, frijoles negros, elote y cebada" width={900} height={669} fetchPriority="high" className="w-full select-none drop-shadow-[0_40px_50px_rgba(0,0,0,0.55)]" draggable={false} />
        </div>
        <IngredientesFlotantes />
      </motion.div>
    </div>
  )
}
