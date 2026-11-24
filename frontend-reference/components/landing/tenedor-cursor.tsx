'use client'

import { motion, useMotionValue, useReducedMotion, useSpring, useTransform } from 'motion/react'
import { useEffect, useState } from 'react'

/**
 * Un tenedor dorado que sigue al cursor con suavidad por toda la página. Se inclina según hacia dónde se mueve
 * y se agranda un poco sobre botones y enlaces. Solo con ratón (en pantallas táctiles no aparece) y no se muestra si el usuario pide «menos movimiento».
 * No bloquea los clics: ignora el puntero.
 */
export function TenedorCursor() {
  const reducido = useReducedMotion()
  const [activo, setActivo] = useState(false)
  const [visible, setVisible] = useState(false)
  const [sobreAccion, setSobreAccion] = useState(false)
  const x = useMotionValue(-100)
  const y = useMotionValue(-100)
  const empuje = useMotionValue(0) // velocidad horizontal: inclina el tenedor
  const sx = useSpring(x, { stiffness: 110, damping: 17, mass: 0.7 })
  const sy = useSpring(y, { stiffness: 110, damping: 17, mass: 0.7 })
  const inclinacion = useSpring(empuje, { stiffness: 90, damping: 14 })
  const rotar = useTransform(inclinacion, (v) => -28 + Math.max(-24, Math.min(24, v * 0.55)))

  useEffect(() => {
    setActivo(window.matchMedia('(hover: hover) and (pointer: fine)').matches)
  }, [])

  useEffect(() => {
    if (!activo || reducido) return
    let ultimoX = 0
    const mover = (e: PointerEvent) => {
      if (e.pointerType !== 'mouse') return
      empuje.set(e.clientX - ultimoX)
      ultimoX = e.clientX
      x.set(e.clientX + 14)
      y.set(e.clientY + 12)
      setVisible(true)
      const objetivo = e.target instanceof Element ? e.target.closest('a, button, [role="button"], input, select, textarea, label') : null
      setSobreAccion(!!objetivo)
    }
    const salir = () => setVisible(false)
    const reposo = setInterval(() => empuje.set(empuje.get() * 0.5), 120) // la inclinación vuelve a su sitio cuando el cursor se detiene
    window.addEventListener('pointermove', mover, { passive: true })
    document.documentElement.addEventListener('pointerleave', salir)
    return () => {
      clearInterval(reposo)
      window.removeEventListener('pointermove', mover)
      document.documentElement.removeEventListener('pointerleave', salir)
    }
  }, [activo, reducido, x, y, empuje])

  if (!activo || reducido) return null
  return (
    <motion.div aria-hidden="true" style={{ x: sx, y: sy }} className="pointer-events-none fixed left-0 top-0 z-[90]">
      <motion.div style={{ rotate: rotar }} animate={{ opacity: visible ? 1 : 0, scale: sobreAccion ? 1.3 : 1 }} transition={{ duration: 0.25 }} className="-ml-4 -mt-1 origin-top">
        <svg width="34" height="64" viewBox="0 0 34 64" fill="none" className="drop-shadow-[0_6px_10px_rgba(0,0,0,0.55)]">
          <defs>
            <linearGradient id="tenedor-oro" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stopColor="#ffe08a" />
              <stop offset="0.55" stopColor="#e0a813" />
              <stop offset="1" stopColor="#a8760a" />
            </linearGradient>
          </defs>
          <path d="M5 2v17c0 5 3 8 6 9v32c0 2 1.5 3.5 3 3.5S17 62 17 60V28c3-1 6-4 6-9V2h-3v14h-2.5V2H15v14h-2.5V2H10v14H7.5V2z" fill="url(#tenedor-oro)" stroke="#6d4a05" strokeWidth="1.2" strokeLinejoin="round" />
          <path d="M14 30v28" stroke="#fff3c4" strokeOpacity="0.55" strokeWidth="1.4" strokeLinecap="round" />
        </svg>
      </motion.div>
    </motion.div>
  )
}
