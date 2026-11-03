'use client'

import { motion, useReducedMotion, useScroll, useTransform, type MotionValue } from 'motion/react'
import { useRef } from 'react'
import { Encabezado } from './ui'

const pasos = [
  { n: '1', titulo: 'Elige', texto: 'Cada noche, tu platillo del día siguiente.' },
  { n: '2', titulo: 'Cocinamos', texto: 'Comedores certificados, bajo pedido.' },
  { n: '3', titulo: 'Recibe', texto: 'En tu empresa o en tu casa.' },
]

function Paso({ i, paso, progreso }: { i: number; paso: (typeof pasos)[number]; progreso: MotionValue<number> }) {
  const inicio = 0.06 + i * 0.27
  const p = useTransform(progreso, [inicio, inicio + 0.2], [0, 1], { clamp: true })
  const y = useTransform(p, [0, 1], [56, 0])
  const escala = useTransform(p, [0, 1], [0.85, 1])
  return (
    <motion.li style={{ opacity: p, y }} className="flex items-center gap-5 md:block">
      <motion.span style={{ scale: escala }} aria-hidden="true" className="block origin-left font-heading text-[5rem] leading-none text-lgold sm:text-[7rem] md:text-[10rem] lg:text-[12rem]">{paso.n}</motion.span>
      <div className="md:mt-2">
        <h3 className="font-heading text-2xl text-lcream sm:text-3xl">{paso.titulo}</h3>
        <p className="mt-2 max-w-xs text-base leading-7 text-lcream/75 md:text-lg">{paso.texto}</p>
      </div>
    </motion.li>
  )
}

export function ComoFunciona() {
  const reducido = useReducedMotion()
  const ref = useRef<HTMLElement>(null)
  const { scrollYProgress } = useScroll({ target: ref, offset: ['start start', 'end end'] })
  const linea = useTransform(scrollYProgress, [0.05, 0.85], [0, 1], { clamp: true })

  // Con "menos movimiento" los tres pasos se muestran completos y sin fijar la pantalla.
  if (reducido) {
    return (
      <section id="como-funciona" className="mx-auto max-w-7xl px-5 py-24 lg:px-8">
        <Encabezado eyebrow="Cómo funciona" titulo={['Tres pasos.']} />
        <ol className="mt-14 grid gap-10 md:grid-cols-3">
          {pasos.map((p) => <li key={p.n} className="flex items-center gap-5 md:block"><span aria-hidden="true" className="block font-heading text-[5rem] leading-none text-lgold md:text-[9rem]">{p.n}</span><div><h3 className="font-heading text-2xl text-lcream">{p.titulo}</h3><p className="mt-2 max-w-xs text-lcream/75">{p.texto}</p></div></li>)}
        </ol>
      </section>
    )
  }

  return (
    <section id="como-funciona" ref={ref} className="relative h-[250vh]">
      <div className="sticky top-0 flex h-screen items-center overflow-hidden">
        <div className="mx-auto w-full max-w-7xl px-5 pt-16 lg:px-8">
          <Encabezado eyebrow="Cómo funciona" titulo={['Tres pasos.']} />
          <ol className="mt-8 grid gap-6 md:mt-14 md:grid-cols-3 md:gap-10">
            {pasos.map((paso, i) => <Paso key={paso.n} i={i} paso={paso} progreso={scrollYProgress} />)}
          </ol>
          <div aria-hidden="true" className="mt-8 h-px w-full bg-white/10 md:mt-12"><motion.div style={{ scaleX: linea }} className="h-full origin-left bg-lgold" /></div>
        </div>
      </div>
    </section>
  )
}
