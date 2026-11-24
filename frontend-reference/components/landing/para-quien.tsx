'use client'

import { ArrowUpRight, Building2, House, UtensilsCrossed, type LucideIcon } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Encabezado, Reveal } from './ui'

const tarjetas: { titulo: string; texto: string; insignia: string; foto: string; alt: string; posicion: string; Icono: LucideIcon }[] = [
  { titulo: 'Empresas', texto: 'Entrega diaria y una sola factura al mes.', insignia: 'Facturación 100% deducible', foto: '/para-quien/empresas.webp', alt: 'Equipo de una empresa comiendo juntos en la sala de juntas', posicion: '62% 50%', Icono: Building2 },
  { titulo: 'Comedores', texto: 'Más clientes, con contrato de compra mínima.', insignia: 'Demanda garantizada', foto: '/para-quien/comedores.webp', alt: 'Cocineros con guantes preparando platillos en una cocina industrial', posicion: '30% 50%', Icono: UtensilsCrossed },
  { titulo: 'Personas', texto: 'Paquetes de 1, 2 o 6 días.', insignia: 'Sin suscripción forzosa', foto: '/para-quien/personas.webp', alt: 'Una pareja cenando en casa a la luz de una vela', posicion: '18% 50%', Icono: House },
]

/** La foto de la tarjeta; si todavía no existe (o falla), se ve un marcador con el icono en lugar del icono roto del navegador. */
function Foto({ src, alt, posicion, Icono }: { src: string; alt: string; posicion: string; Icono: LucideIcon }) {
  const [falla, setFalla] = useState(false)
  const img = useRef<HTMLImageElement>(null)
  // Si el archivo no existe, el error llega antes de que React enganche `onError`: se revisa al montar.
  useEffect(() => { if (img.current?.complete && img.current.naturalWidth === 0) setFalla(true) }, [])
  if (falla) {
    return (
      <div role="img" aria-label={alt} className="grid size-full place-items-center bg-[radial-gradient(ellipse_at_50%_35%,#2a3945_0%,#16232d_72%)]">
        <Icono className="size-24 text-lgold/25" aria-hidden="true" strokeWidth={1.1} />
      </div>
    )
  }
  return <img ref={img} src={src} alt={alt} loading="lazy" width={900} height={600} onError={() => setFalla(true)} style={{ objectPosition: posicion }} className="size-full object-cover transition-transform duration-[900ms] ease-out group-hover/tarjeta:scale-110" />
}

export function ParaQuien() {
  return (
    <section id="para-quien" className="relative mx-auto max-w-7xl px-5 py-24 md:py-32 lg:px-8">
      <Encabezado eyebrow="Para quién" titulo={['Hecho para', 'cada mesa.']} />
      <ul className="mt-12 grid gap-6 md:grid-cols-3 md:gap-7">
        {tarjetas.map(({ titulo, texto, insignia, foto, alt, posicion, Icono }, i) => (
          <li key={titulo}>
            <Reveal delay={i * 0.12} className="h-full">
              <a href="#contacto" className="group/tarjeta landing-brillo relative flex aspect-[4/5] h-full min-h-[24rem] flex-col justify-end overflow-hidden rounded-3xl bg-[#101a22] transition-transform duration-500 hover:-translate-y-2" style={{ animationDelay: `${i * 0.8}s` }}>
                <div className="absolute inset-0"><Foto src={foto} alt={alt} posicion={posicion} Icono={Icono} /></div>
                <div aria-hidden="true" className="pointer-events-none absolute inset-0 bg-gradient-to-b from-[#0b1218]/55 via-transparent via-35% to-[#0b1218]/95" />
                <div className="absolute inset-x-4 top-4 flex items-start justify-between gap-3">
                  <span className="grid size-12 shrink-0 place-items-center rounded-2xl border border-lgold/40 bg-[#1b1710]/80 text-lgold backdrop-blur-sm"><Icono className="size-6" aria-hidden="true" /></span>
                  <span className="flex min-w-0 items-center gap-2">
                    <span className="truncate rounded-full bg-lgold px-3.5 py-1.5 text-xs font-bold text-lbg shadow-[0_8px_22px_-8px_rgba(224,168,19,0.9)] sm:text-[0.8rem]">{insignia}</span>
                    <ArrowUpRight className="size-6 shrink-0 text-lgold transition-transform duration-500 group-hover/tarjeta:-translate-y-1 group-hover/tarjeta:translate-x-1" aria-hidden="true" />
                  </span>
                </div>
                <div className="relative p-6 pt-24">
                  <h3 className="font-heading text-4xl text-lcream drop-shadow-[0_2px_12px_rgba(0,0,0,0.6)]">{titulo}</h3>
                  <p className="mt-3 max-w-[18rem] text-lg leading-7 text-lcream/85">{texto}</p>
                </div>
              </a>
            </Reveal>
          </li>
        ))}
      </ul>
    </section>
  )
}
