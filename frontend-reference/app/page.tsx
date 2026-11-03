'use client'

import { MotionConfig } from 'motion/react'
import { ComoFunciona } from '@/components/landing/como-funciona'
import { Contacto } from '@/components/landing/contacto'
import { Hero } from '@/components/landing/hero'
import { Nav } from '@/components/landing/nav'
import { ParaQuien } from '@/components/landing/para-quien'
import { Pie } from '@/components/landing/pie'
import { Platillos } from '@/components/landing/platillos'

export default function Inicio() {
  return (
    <MotionConfig reducedMotion="user">
      <div className="landing font-body min-h-screen overflow-x-clip bg-lbg text-lcream antialiased">
        <Nav />
        <main id="contenido">
          <Hero />
          <ComoFunciona />
          <Platillos />
          <ParaQuien />
          <Contacto />
        </main>
        <Pie />
      </div>
    </MotionConfig>
  )
}
