'use client'

import { ArrowUpRight, Building2, House, UtensilsCrossed } from 'lucide-react'
import { Encabezado, Reveal } from './ui'

const tarjetas = [
  { titulo: 'Empresas', texto: 'Entrega diaria y una sola factura al mes.', Icono: Building2 },
  { titulo: 'Comedores', texto: 'Más clientes, con contrato de compra mínima.', Icono: UtensilsCrossed },
  { titulo: 'Personas', texto: 'Paquetes de 1, 2 o 6 días.', Icono: House },
]

export function ParaQuien() {
  return (
    <section id="para-quien" className="relative mx-auto max-w-7xl px-5 py-24 md:py-32 lg:px-8">
      <Encabezado eyebrow="Para quién" titulo={['Hecho para', 'cada mesa.']} />
      <ul className="mt-12 grid gap-5 md:grid-cols-3">
        {tarjetas.map(({ titulo, texto, Icono }, i) => (
          <li key={titulo}>
            <Reveal delay={i * 0.1} className="h-full">
              <a href="#contacto" className="group relative flex h-full min-h-[15rem] flex-col justify-between overflow-hidden rounded-3xl border border-white/10 bg-white/[0.04] p-7 transition-[transform,border-color] duration-500 hover:-translate-y-1.5 hover:border-lgold/60">
                <span aria-hidden="true" className="pointer-events-none absolute -right-16 -top-16 size-48 rounded-full bg-[radial-gradient(closest-side,rgba(224,168,19,0.22),transparent)] opacity-0 transition-opacity duration-500 group-hover:opacity-100" />
                <div className="flex items-start justify-between">
                  <span className="grid size-14 place-items-center rounded-2xl bg-lgold/15 text-lgold"><Icono className="size-7" aria-hidden="true" /></span>
                  <ArrowUpRight className="size-6 text-lcream/70 transition-transform duration-500 group-hover:-translate-y-1 group-hover:translate-x-1 group-hover:text-lgold" aria-hidden="true" />
                </div>
                <div className="mt-10">
                  <h3 className="font-heading text-3xl text-lcream">{titulo}</h3>
                  <p className="mt-3 text-lg leading-7 text-lcream/75">{texto}</p>
                </div>
              </a>
            </Reveal>
          </li>
        ))}
      </ul>
    </section>
  )
}
