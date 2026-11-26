'use client'

import { motion, useReducedMotion } from 'motion/react'
import { useCallback, useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react'
import { ChevronLeft, ChevronRight, Flame, UtensilsCrossed } from 'lucide-react'
import { Encabezado, Reveal } from './ui'

const platillos = [
  { nombre: 'Pollo teriyaki con arroz', kcal: 520, etiquetas: ['Alto en proteína', 'Sin lácteos'], foto: '/platillos/pollo-teriyaki.webp', encuadre: '50% 50%', descripcion: 'Pollo glaseado con arroz jazmín y verduras salteadas, en plato hondo, vista cenital.' },
  { nombre: 'Bowl mexicano', kcal: 480, etiquetas: ['Vegetariano', 'Con fibra'], foto: '/platillos/bowl-mexicano.webp', encuadre: '50% 50%', descripcion: 'Arroz, frijoles negros, elote, aguacate y pico de gallo en un bowl.' },
  { nombre: 'Enchiladas verdes', kcal: 560, etiquetas: ['Tradicional', 'Con pollo'], foto: '/platillos/enchiladas-verdes.webp', encuadre: '42% 50%', descripcion: 'Tres enchiladas bañadas en salsa verde con crema, cebolla y queso.' },
  { nombre: 'Avena con frutos rojos', kcal: 290, etiquetas: ['Desayuno', 'Ligero'], foto: '/platillos/avena-frutos-rojos.webp', encuadre: '62% 50%', descripcion: 'Avena cremosa con fresas, zarzamoras y nuez tostada.' },
  { nombre: 'Tacos fit', kcal: 410, etiquetas: ['Alto en proteína', 'Bajo en carbohidratos'], foto: '/platillos/tacos-fit.webp', encuadre: '45% 50%', descripcion: 'Tacos de tortilla de nopal con pollo a la plancha y salsa fresca.' },
  { nombre: 'Pozole ligero', kcal: 380, etiquetas: ['Ligero', 'Sin gluten'], foto: '/platillos/pozole-ligero.webp', encuadre: '0% 50%', descripcion: 'Pozole rojo con pollo deshebrado, lechuga, rábano y limón.' },
]

const filtros = ['Todos', 'Alto en proteína', 'Vegetariano', 'Desayuno', 'Sin lácteos'] as const

type Platillo = (typeof platillos)[number]

function Foto({ platillo }: { platillo: Platillo }) {
  const [falla, setFalla] = useState(false)
  const img = useRef<HTMLImageElement>(null)
  // Si la foto no existe, el error llega antes de que React enganche `onError`: se revisa al montar.
  useEffect(() => { if (img.current?.complete && img.current.naturalWidth === 0) setFalla(true) }, [])
  if (falla) {
    return (
      <div role="img" aria-label={`${platillo.nombre}: ${platillo.descripcion}`} className="relative grid size-full place-items-center bg-[radial-gradient(ellipse_at_50%_35%,#24333f_0%,#16232d_70%)]">
        <UtensilsCrossed className="size-16 text-lgold/35" aria-hidden="true" strokeWidth={1.25} />
      </div>
    )
  }
  return <img ref={img} src={platillo.foto} alt="" loading="lazy" width={640} height={520} onError={() => setFalla(true)} style={{ objectPosition: platillo.encuadre }} className="size-full object-cover" />
}

/** Cuánto se separa y se inclina cada tarjeta según qué tan lejos está de la central (efecto Coverflow). */
function posicion(d: number, ancho: number, reducido: boolean | null) {
  const lejos = Math.abs(d)
  const signo = Math.sign(d)
  return {
    x: signo * Math.min(lejos, 3) * ancho * 0.64,
    scale: Math.max(0.6, 1 - lejos * 0.12),
    rotateY: reducido ? 0 : -signo * Math.min(lejos, 2) * 26,
    opacity: lejos === 0 ? 1 : lejos === 1 ? 0.95 : lejos === 2 ? 0.5 : 0,
  }
}

export function Platillos() {
  const reducido = useReducedMotion()
  const [filtro, setFiltro] = useState<(typeof filtros)[number]>('Todos')
  const [activo, setActivo] = useState(2)
  const [anchoEscena, setAnchoEscena] = useState(1100)
  const escena = useRef<HTMLDivElement>(null)

  const lista = useMemo(() => (filtro === 'Todos' ? platillos : platillos.filter((p) => p.etiquetas.includes(filtro))), [filtro])
  const indice = Math.min(activo, lista.length - 1)
  const ancho = Math.round(Math.min(330, Math.max(224, anchoEscena * 0.7)))
  const alto = Math.round(ancho * 0.8 + 250)

  useEffect(() => {
    const el = escena.current
    if (!el) return
    const medir = () => setAnchoEscena(el.clientWidth)
    medir()
    const observador = new ResizeObserver(medir)
    observador.observe(el)
    return () => observador.disconnect()
  }, [])

  const ir = useCallback((i: number) => setActivo(Math.max(0, Math.min(lista.length - 1, i))), [lista.length])
  const elegirFiltro = (f: (typeof filtros)[number]) => {
    setFiltro(f)
    const resultado = f === 'Todos' ? platillos : platillos.filter((p) => p.etiquetas.includes(f))
    setActivo(Math.min(2, Math.floor((resultado.length - 1) / 2)))
  }
  function teclas(e: KeyboardEvent<HTMLDivElement>) {
    if (e.key === 'ArrowRight') { e.preventDefault(); ir(indice + 1) }
    if (e.key === 'ArrowLeft') { e.preventDefault(); ir(indice - 1) }
    if (e.key === 'Home') { e.preventDefault(); ir(0) }
    if (e.key === 'End') { e.preventDefault(); ir(lista.length - 1) }
  }
  const transicion = reducido ? { duration: 0 } : { type: 'spring' as const, stiffness: 190, damping: 26, mass: 0.9 }

  return (
    <section id="platillos" className="relative overflow-hidden py-24 md:py-32">
      <div className="mx-auto max-w-7xl px-5 lg:px-8">
        <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-6">
          <Encabezado eyebrow="Platillos" titulo={['Lo que se', 'sirve esta semana.']} />
          <Reveal className="flex gap-2">
            <button onClick={() => ir(indice - 1)} disabled={indice <= 0} aria-label="Platillo anterior" className="grid size-12 place-items-center rounded-full border border-lcream/40 text-lcream transition-colors hover:bg-lgold hover:text-lbg disabled:opacity-35 disabled:hover:bg-transparent disabled:hover:text-lcream"><ChevronLeft className="size-5" aria-hidden="true" /></button>
            <button onClick={() => ir(indice + 1)} disabled={indice >= lista.length - 1} aria-label="Platillo siguiente" className="grid size-12 place-items-center rounded-full border border-lcream/40 text-lcream transition-colors hover:bg-lgold hover:text-lbg disabled:opacity-35 disabled:hover:bg-transparent disabled:hover:text-lcream"><ChevronRight className="size-5" aria-hidden="true" /></button>
          </Reveal>
        </div>
        <Reveal className="mt-8">
          <ul aria-label="Filtrar platillos" className="flex flex-wrap gap-2.5">
            {filtros.map((f) => (
              <li key={f}><button onClick={() => elegirFiltro(f)} aria-pressed={filtro === f} className={`rounded-full border px-4 py-2 text-sm font-semibold transition-colors ${filtro === f ? 'border-lgold bg-lgold text-lbg shadow-[0_8px_24px_-10px_rgba(224,168,19,0.9)]' : 'border-lcream/30 bg-lbg/60 text-lcream/90 hover:border-lgold hover:text-lgold'}`}>{f}</button></li>
            ))}
          </ul>
        </Reveal>
      </div>

      <Reveal className="mt-10">
        <motion.div
          ref={escena}
          role="group"
          aria-roledescription="carrusel"
          aria-label="Platillos de la semana. Usa las flechas del teclado o desliza para cambiar de platillo."
          tabIndex={0}
          onKeyDown={teclas}
          onPanEnd={(_, info) => { if (info.offset.x < -50 || info.velocity.x < -450) ir(indice + 1); else if (info.offset.x > 50 || info.velocity.x > 450) ir(indice - 1) }}
          className="relative mx-auto w-full max-w-6xl touch-pan-y select-none outline-none [perspective:1500px] focus-visible:ring-2 focus-visible:ring-lgold/60"
          style={{ height: alto }}
        >
          {lista.map((p, i) => {
            const d = i - indice
            const central = d === 0
            const objetivo = posicion(d, ancho, reducido)
            return (
              <motion.div
                key={p.nombre}
                role="group"
                aria-roledescription="diapositiva"
                aria-label={`${i + 1} de ${lista.length}: ${p.nombre}`}
                aria-hidden={Math.abs(d) > 2}
                initial={false}
                animate={objetivo}
                transition={transicion}
                style={{ width: ancho, height: alto - 8, marginLeft: -ancho / 2, zIndex: 20 - Math.abs(d), transformStyle: 'preserve-3d', pointerEvents: Math.abs(d) > 2 ? 'none' : 'auto' }}
                className="absolute left-1/2 top-0 will-change-transform"
              >
                <article className={`relative flex h-full flex-col overflow-hidden rounded-3xl bg-[#101a22] text-lcream transition-shadow duration-500 ${central ? 'landing-brillo' : 'shadow-[0_30px_60px_-30px_rgba(0,0,0,0.9)] ring-1 ring-white/10'}`}>
                  <div className="relative min-h-0 flex-1 overflow-hidden">
                    <div className="absolute inset-0"><Foto platillo={p} /></div>
                    <div aria-hidden="true" className="pointer-events-none absolute inset-x-0 bottom-0 h-1/4 bg-gradient-to-b from-transparent to-[#101a22]" />
                  </div>
                  <div className="flex shrink-0 flex-col gap-3 p-5">
                    <h3 className="font-heading text-2xl leading-tight">{p.nombre}</h3>
                    <p className="flex items-center gap-1.5 text-sm font-medium text-lgold"><Flame className="size-4" aria-hidden="true" /> {p.kcal} kcal</p>
                    <p className="line-clamp-3 text-sm leading-6 text-lcream/70">{p.descripcion}</p>
                    <ul className="flex flex-wrap gap-2">{p.etiquetas.map((e) => <li key={e} className="rounded-full border border-lcream/25 px-3 py-1 text-xs font-medium text-lcream/90">{e}</li>)}</ul>
                  </div>
                  {!central && (
                    <>
                      <div aria-hidden="true" className="pointer-events-none absolute inset-0 bg-lbg/45" />
                      <button onClick={() => ir(i)} aria-label={`Ver ${p.nombre}`} className="absolute inset-0 cursor-pointer rounded-3xl" />
                    </>
                  )}
                </article>
              </motion.div>
            )
          })}
        </motion.div>
        <p className="sr-only" aria-live="polite">{lista[indice] ? `Platillo ${indice + 1} de ${lista.length}: ${lista[indice].nombre}` : ''}</p>
      </Reveal>
    </section>
  )
}
