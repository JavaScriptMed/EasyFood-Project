'use client'

import { motion, useReducedMotion } from 'motion/react'
import { Fragment } from 'react'
import { ease, Encabezado } from './ui'

const pasos = [
  { n: '1', titulo: 'Elige', texto: 'Cada noche, tu platillo del día siguiente.', imagen: '/pasos/elige.webp', alt: 'Pantalla del menú semanal con los platillos del día, elegidos con el dedo' },
  { n: '2', titulo: 'Cocinamos', texto: 'Comedores certificados, bajo pedido.', imagen: '/pasos/cocina.webp', alt: 'Cocineros con filete y gorro preparando comida en una cocina industrial' },
  { n: '3', titulo: 'Recibe', texto: 'En tu empresa o en tu casa.', imagen: '/pasos/entrega.webp', alt: 'Repartidor de EasyLunch entregando la comida en una puerta' },
]

function Tarjeta({ paso, i }: { paso: (typeof pasos)[number]; i: number }) {
  const reducido = useReducedMotion()
  return (
    <motion.li
      initial={reducido ? false : { opacity: 0, y: 56, scale: 0.94 }}
      whileInView={{ opacity: 1, y: 0, scale: 1 }}
      viewport={{ once: true, margin: '0px 0px -12% 0px' }}
      transition={{ duration: 0.85, ease, delay: i * 0.18 }}
      whileHover={reducido ? undefined : { y: -8 }}
      className="group/paso landing-brillo relative flex flex-col overflow-hidden rounded-3xl bg-[#111b23]/90 p-3 backdrop-blur-sm md:flex-1"
      style={{ animationDelay: `${i * 0.7}s` }}
    >
      <div className="relative aspect-[4/3] overflow-hidden rounded-2xl">
        <img src={paso.imagen} alt={paso.alt} loading="lazy" width={800} height={598} className="size-full object-cover transition-transform duration-[900ms] ease-out group-hover/paso:scale-110" />
        <div aria-hidden="true" className="pointer-events-none absolute inset-x-0 bottom-0 h-1/3 bg-gradient-to-b from-transparent to-[#111b23]/60" />
      </div>
      <div className="px-3 pb-3 pt-2">
        <span aria-hidden="true" className="block font-heading text-[5.5rem] leading-[0.95] text-lgold drop-shadow-[0_0_22px_rgba(224,168,19,0.35)] sm:text-[6.5rem]">{paso.n}</span>
        <h3 className="mt-1 font-heading text-3xl text-lcream">{paso.titulo}</h3>
        <p className="mt-2 text-base leading-7 text-lcream/80 sm:text-lg">{paso.texto}</p>
      </div>
    </motion.li>
  )
}

/** Puntos dorados que viajan de un paso al siguiente (en pantallas anchas van en horizontal; en celular, en vertical). */
function Conector({ i }: { i: number }) {
  const reducido = useReducedMotion()
  return (
    <motion.li
      aria-hidden="true"
      initial={reducido ? false : { opacity: 0, scale: 0.6 }}
      whileInView={{ opacity: 1, scale: 1 }}
      viewport={{ once: true, margin: '0px 0px -10% 0px' }}
      transition={{ duration: 0.6, ease, delay: 0.5 + i * 0.18 }}
      className="mx-auto flex h-10 w-2 items-center md:h-auto md:w-12 md:self-center lg:w-16"
    >
      <span className="landing-puntos block h-2 w-full md:block" />
    </motion.li>
  )
}

export function ComoFunciona() {
  return (
    <section id="como-funciona" className="relative mx-auto max-w-7xl px-5 py-24 md:py-32 lg:px-8">
      <Encabezado eyebrow="Cómo funciona" titulo={['Tres pasos.']} />
      <ol className="mt-12 flex flex-col gap-0 md:mt-16 md:flex-row md:items-stretch md:gap-0">
        {pasos.map((paso, i) => (
          <Fragment key={paso.n}>
            <Tarjeta paso={paso} i={i} />
            {i < pasos.length - 1 && <Conector i={i} />}
          </Fragment>
        ))}
      </ol>
    </section>
  )
}
