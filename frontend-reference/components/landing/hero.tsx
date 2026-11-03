'use client'

import { motion, useReducedMotion } from 'motion/react'
import { ArrowRight, MapPin } from 'lucide-react'
import { Ambiente } from './ambiente'
import { ObjetoHero } from './objeto-hero'
import { ease, LineasMascara, Magnetico } from './ui'

export function Hero() {
  const reducido = useReducedMotion()
  const aparece = (delay: number) => (reducido ? {} : { initial: { opacity: 0, y: 24 }, animate: { opacity: 1, y: 0 }, transition: { duration: 0.8, ease, delay } })

  return (
    <section id="inicio" className="relative isolate flex min-h-[100svh] items-center overflow-hidden">
      <Ambiente />
      <div className="relative mx-auto grid w-full max-w-7xl items-center gap-10 px-5 pb-16 pt-32 lg:grid-cols-[1.15fr_0.85fr] lg:gap-8 lg:px-8 lg:pb-24">
        <div>
          <h1 className="font-heading text-[2.6rem] leading-[1.02] text-lcream sm:text-6xl lg:text-7xl xl:text-[5.5rem]">
            <LineasMascara alMontar delay={0.15} lineas={['Tu comida', 'de la semana,', <span key="r" className="text-lgold">resuelta.</span>]} />
          </h1>
          <motion.p {...aparece(0.65)} className="mt-6 max-w-lg text-lg leading-8 text-lcream/80 sm:text-xl sm:leading-9">Comedores certificados cocinan; nosotros la llevamos a tu empresa o a tu casa.</motion.p>
          <motion.div {...aparece(0.8)} className="mt-9 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
            <Magnetico className="block w-full sm:inline-block sm:w-auto"><a href="#contacto" className="inline-flex w-full items-center justify-center gap-2 rounded-full bg-lgold px-8 py-4 text-base font-semibold text-lbg shadow-[0_14px_40px_-12px_rgba(224,168,19,0.8)] transition-colors hover:bg-[#f0bb2c] sm:w-auto">Quiero para mi empresa <ArrowRight className="size-4" aria-hidden="true" /></a></Magnetico>
            <Magnetico className="block w-full sm:inline-block sm:w-auto"><a href="#como-funciona" className="inline-flex w-full items-center justify-center rounded-full border border-lcream/35 px-8 py-4 text-base font-semibold text-lcream transition-colors hover:border-lgold hover:text-lgold sm:w-auto">Ver cómo funciona</a></Magnetico>
          </motion.div>
          <motion.p {...aparece(0.95)} className="mt-8 flex items-center gap-2 text-sm text-lcream/70"><MapPin className="size-4 text-lgold" aria-hidden="true" /> Guadalajara · Comida preparada con NOM-251</motion.p>
        </div>

        <motion.div {...(reducido ? {} : { initial: { opacity: 0, scale: 0.92 }, animate: { opacity: 1, scale: 1 }, transition: { duration: 1.1, ease, delay: 0.4 } })}>
          <ObjetoHero />
        </motion.div>
      </div>
    </section>
  )
}
