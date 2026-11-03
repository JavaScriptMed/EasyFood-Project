'use client'

import { motion, useMotionValue, useReducedMotion, useSpring, useTransform } from 'motion/react'
import { useCallback, useEffect, useRef, useState, type PointerEvent } from 'react'
import { ChevronLeft, ChevronRight, Flame } from 'lucide-react'
import { Encabezado, Reveal } from './ui'

const platillos = [
  { nombre: 'Pollo teriyaki con arroz', kcal: 520, etiquetas: ['Alto en proteína', 'Sin lácteos'], foto: '/platillos/pollo-teriyaki.jpg', descripcion: 'Pollo glaseado con arroz jazmín y verduras salteadas, en plato hondo, vista cenital.' },
  { nombre: 'Bowl mexicano', kcal: 480, etiquetas: ['Vegetariano', 'Con fibra'], foto: '/platillos/bowl-mexicano.jpg', descripcion: 'Arroz, frijoles negros, elote, aguacate y pico de gallo en un bowl.' },
  { nombre: 'Enchiladas verdes', kcal: 560, etiquetas: ['Tradicional', 'Con pollo'], foto: '/platillos/enchiladas-verdes.jpg', descripcion: 'Tres enchiladas bañadas en salsa verde con crema, cebolla y queso.' },
  { nombre: 'Avena con frutos rojos', kcal: 290, etiquetas: ['Desayuno', 'Ligero'], foto: '/platillos/avena-frutos-rojos.jpg', descripcion: 'Avena cremosa con fresas, zarzamoras y nuez tostada.' },
  { nombre: 'Tacos fit', kcal: 410, etiquetas: ['Alto en proteína', 'Bajo en carbohidratos'], foto: '/platillos/tacos-fit.jpg', descripcion: 'Tacos de tortilla de nopal con pollo a la plancha y salsa fresca.' },
  { nombre: 'Pozole ligero', kcal: 380, etiquetas: ['Ligero', 'Sin gluten'], foto: '/platillos/pozole-ligero.jpg', descripcion: 'Pozole rojo con pollo deshebrado, lechuga, rábano y limón.' },
]

type Platillo = (typeof platillos)[number]

function Foto({ platillo }: { platillo: Platillo }) {
  const [falla, setFalla] = useState(false)
  if (falla) {
    return (
      <div role="img" aria-label={`Foto pendiente: ${platillo.nombre}. ${platillo.descripcion}`} className="flex size-full flex-col justify-end gap-2 bg-[repeating-linear-gradient(135deg,#1b2a35_0_14px,#16232d_14px_28px)] p-5">
        <span className="w-fit rounded-full border border-lgold/50 px-2.5 py-1 text-[10px] font-bold uppercase tracking-[0.18em] text-lgold">Foto pendiente</span>
        <p className="text-sm leading-6 text-lcream/80">{platillo.descripcion}</p>
      </div>
    )
  }
  return <img src={platillo.foto} alt={`${platillo.nombre}: ${platillo.descripcion}`} loading="lazy" width={640} height={520} onError={() => setFalla(true)} className="size-full object-cover transition-transform duration-700 ease-out group-hover/tarjeta:scale-110" />
}

/** Tarjeta con inclinación 3D al pasar el cursor y zoom suave en la foto. */
function Tarjeta({ platillo }: { platillo: Platillo }) {
  const reducido = useReducedMotion()
  const mx = useMotionValue(0)
  const my = useMotionValue(0)
  const rx = useSpring(useTransform(my, [-0.5, 0.5], [9, -9]), { stiffness: 180, damping: 18 })
  const ry = useSpring(useTransform(mx, [-0.5, 0.5], [-11, 11]), { stiffness: 180, damping: 18 })

  function mover(e: PointerEvent<HTMLElement>) {
    if (reducido || e.pointerType !== 'mouse') return
    const r = e.currentTarget.getBoundingClientRect()
    mx.set((e.clientX - r.left) / r.width - 0.5)
    my.set((e.clientY - r.top) / r.height - 0.5)
  }
  const soltar = () => { mx.set(0); my.set(0) }

  return (
    <li className="w-[78vw] max-w-[22rem] shrink-0 snap-center sm:w-[22rem] [perspective:1000px]">
      <motion.article onPointerMove={mover} onPointerLeave={soltar} style={reducido ? undefined : { rotateX: rx, rotateY: ry, transformStyle: 'preserve-3d' }} className="group/tarjeta flex h-full flex-col overflow-hidden rounded-3xl bg-lbg text-lcream shadow-[0_30px_60px_-30px_rgba(14,22,29,0.65)]">
        <div className="relative aspect-[5/4] overflow-hidden"><Foto platillo={platillo} /><div aria-hidden="true" className="pointer-events-none absolute inset-x-0 bottom-0 h-20 bg-gradient-to-b from-transparent to-lbg/70" /></div>
        <div className="flex flex-1 flex-col gap-4 p-6">
          <h3 className="font-heading text-2xl leading-tight">{platillo.nombre}</h3>
          <p className="flex items-center gap-1.5 text-sm font-medium text-lgold"><Flame className="size-4" aria-hidden="true" /> {platillo.kcal} kcal</p>
          <ul className="mt-auto flex flex-wrap gap-2">{platillo.etiquetas.map((e) => <li key={e} className="rounded-full border border-lcream/25 px-3 py-1 text-xs font-medium text-lcream/90">{e}</li>)}</ul>
        </div>
      </motion.article>
    </li>
  )
}

export function Platillos() {
  const reducido = useReducedMotion()
  const pista = useRef<HTMLUListElement>(null)
  const arrastre = useRef({ activo: false, x: 0, scroll: 0, movido: false })
  const [arrastrando, setArrastrando] = useState(false)
  const [extremos, setExtremos] = useState({ inicio: true, fin: false })
  const avance = useMotionValue(0)
  const barra = useTransform(avance, [0, 1], [0.14, 1])

  const medir = useCallback(() => {
    const el = pista.current
    if (!el) return
    const max = el.scrollWidth - el.clientWidth
    avance.set(max > 0 ? el.scrollLeft / max : 0)
    setExtremos({ inicio: el.scrollLeft <= 4, fin: el.scrollLeft >= max - 4 })
  }, [avance])

  useEffect(() => {
    medir()
    window.addEventListener('resize', medir)
    return () => window.removeEventListener('resize', medir)
  }, [medir])

  function empezar(e: PointerEvent<HTMLUListElement>) {
    if (e.pointerType !== 'mouse' || !pista.current) return
    arrastre.current = { activo: true, x: e.clientX, scroll: pista.current.scrollLeft, movido: false }
  }
  function mover(e: PointerEvent<HTMLUListElement>) {
    const a = arrastre.current
    if (!a.activo || !pista.current) return
    const dx = e.clientX - a.x
    if (Math.abs(dx) > 4 && !a.movido) { a.movido = true; setArrastrando(true) }
    if (a.movido) pista.current.scrollLeft = a.scroll - dx
  }
  function terminar() {
    arrastre.current.activo = false
    setArrastrando(false)
  }
  function ir(sentido: 1 | -1) {
    const el = pista.current
    if (!el) return
    el.scrollBy({ left: sentido * Math.min(el.clientWidth * 0.8, 380), behavior: reducido ? 'auto' : 'smooth' })
  }

  return (
    <section id="platillos" className="relative overflow-hidden bg-lcream py-24 text-lbg md:py-32">
      <div className="mx-auto max-w-7xl px-5 lg:px-8">
        <div className="flex flex-wrap items-end justify-between gap-6">
          <Encabezado oscuro eyebrow="Platillos" titulo={['Lo que se', 'sirve esta semana.']} />
          <Reveal className="hidden gap-2 md:flex">
            <button onClick={() => ir(-1)} disabled={extremos.inicio} aria-label="Platillos anteriores" className="grid size-12 place-items-center rounded-full border border-lbg/25 transition-colors hover:bg-lbg hover:text-lcream disabled:opacity-35 disabled:hover:bg-transparent disabled:hover:text-lbg"><ChevronLeft className="size-5" aria-hidden="true" /></button>
            <button onClick={() => ir(1)} disabled={extremos.fin} aria-label="Platillos siguientes" className="grid size-12 place-items-center rounded-full border border-lbg/25 transition-colors hover:bg-lbg hover:text-lcream disabled:opacity-35 disabled:hover:bg-transparent disabled:hover:text-lbg"><ChevronRight className="size-5" aria-hidden="true" /></button>
          </Reveal>
        </div>
      </div>

      <Reveal className="mt-12">
        <ul
          ref={pista}
          tabIndex={0}
          aria-label="Carrusel de platillos. Desliza o usa las flechas del teclado."
          onScroll={medir}
          onPointerDown={empezar}
          onPointerMove={mover}
          onPointerUp={terminar}
          onPointerLeave={terminar}
          className={`flex gap-5 overflow-x-auto px-5 pb-8 pt-2 [scrollbar-width:none] lg:px-[max(2rem,calc((100vw-80rem)/2+2rem))] [&::-webkit-scrollbar]:hidden ${arrastrando ? 'cursor-grabbing select-none snap-none' : 'cursor-grab snap-x snap-mandatory'}`}
        >
          {platillos.map((p) => <Tarjeta key={p.nombre} platillo={p} />)}
        </ul>
      </Reveal>

      <div className="mx-auto mt-2 max-w-7xl px-5 lg:px-8"><div aria-hidden="true" className="h-0.5 w-full max-w-xs bg-lbg/15"><motion.div style={{ scaleX: barra }} className="h-full origin-left bg-lbg" /></div></div>
    </section>
  )
}
