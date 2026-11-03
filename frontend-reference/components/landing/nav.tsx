'use client'

import Link from 'next/link'
import { motion, useMotionValueEvent, useReducedMotion, useScroll, useSpring } from 'motion/react'
import { useState } from 'react'
import { UtensilsCrossed } from 'lucide-react'
import { Magnetico } from './ui'

const enlaces = [['Cómo funciona', '#como-funciona'], ['Platillos', '#platillos'], ['Para quién', '#para-quien'], ['Contacto', '#contacto']] as const

export function Logo({ className = '' }: { className?: string }) {
  return (
    <Link href="/" aria-label="EasyLunch, inicio" className={`flex items-center gap-2.5 ${className}`}>
      <span className="grid size-10 place-items-center rounded-xl bg-lgold text-lbg shadow-[0_8px_24px_-8px_rgba(224,168,19,0.7)]"><UtensilsCrossed className="size-5" aria-hidden="true" /></span>
      <span className="font-heading text-xl text-lcream">Easy<span className="text-lgold">Lunch</span></span>
    </Link>
  )
}

/** Navegación fija: se vuelve translúcida al bajar. La barra de progreso de scroll cuelga de la misma cabecera. */
export function Nav() {
  const reducido = useReducedMotion()
  const { scrollY, scrollYProgress } = useScroll()
  const [bajo, setBajo] = useState(false)
  const suave = useSpring(scrollYProgress, { stiffness: 120, damping: 30, restDelta: 0.001 })
  useMotionValueEvent(scrollY, 'change', (v) => setBajo(v > 24))

  return (
    <header className="fixed inset-x-0 top-0 z-50">
      <a href="#contenido" className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:rounded-lg focus:bg-lgold focus:px-4 focus:py-2 focus:font-semibold focus:text-lbg">Saltar al contenido</a>
      <div aria-hidden="true" className={`absolute inset-0 border-b border-white/10 bg-lbg/70 backdrop-blur-xl transition-opacity duration-300 ${bajo ? 'opacity-100' : 'opacity-0'}`} />
      <nav aria-label="Principal" className="relative mx-auto flex h-[72px] max-w-7xl items-center justify-between gap-6 px-5 lg:px-8">
        <Logo />
        <ul className="hidden items-center gap-8 md:flex">
          {enlaces.map(([texto, href]) => <li key={href}><a href={href} className="text-sm font-medium text-lcream/80 transition-colors hover:text-lgold">{texto}</a></li>)}
        </ul>
        <div className="flex items-center gap-2 sm:gap-3">
          <Link href="/login" className="rounded-full px-3 py-2 text-sm font-medium text-lcream/80 transition-colors hover:text-lgold">Iniciar sesión</Link>
          <Magnetico className="hidden sm:inline-block"><a href="#contacto" className="inline-flex items-center rounded-full bg-lgold px-5 py-2.5 text-sm font-semibold text-lbg transition-colors hover:bg-[#f0bb2c]">Quiero para mi empresa</a></Magnetico>
        </div>
      </nav>
      <motion.div aria-hidden="true" className="absolute inset-x-0 bottom-0 h-[3px] origin-left bg-lgold" style={{ scaleX: reducido ? scrollYProgress : suave }} />
    </header>
  )
}
