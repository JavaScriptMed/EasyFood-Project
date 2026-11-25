'use client'

import { MapPin } from 'lucide-react'
import { listarZonasDisponibles } from '@/lib/api'
import { useAsync } from '@/lib/use-async'

/** Zonas por si el servidor no responde. */
const RESPALDO = ['Guadalajara', 'Zapopan', 'Tlaquepaque', 'Tonalá']

/** «guadalajara» se muestra como «Guadalajara»; un nombre que ya trae mayúsculas no se toca. */
const bonito = (nombre: string) => (nombre === nombre.toLowerCase() ? nombre.replace(/(^|\s)(\S)/g, (_, e, l) => e + l.toUpperCase()) : nombre)

/** Etiquetas sobre la imagen del mapa, en sus coordenadas (524 × 394). Cada una tapa el rótulo que ya trae la captura. */
const ROTULOS = [
  { nombre: 'Zapopan', x: 134, y: 159, ancho: 80 },
  { nombre: 'Guadalajara', x: 236, y: 172, ancho: 112 },
  { nombre: 'Tlaquepaque', x: 215, y: 299, ancho: 112 },
  { nombre: 'Tonalá', x: 384, y: 257, ancho: 92 },
]

/** El mapa de las zonas con servicio: una imagen fija (`public/cobertura/mapa-zonas.webp`) con sus nombres encima. */
function Mapa() {
  return (
    <svg viewBox="0 0 524 394" role="img" aria-label={`Mapa de las zonas con servicio: ${ROTULOS.map((r) => r.nombre).join(', ')}`} className="block h-auto w-full">
      <image href="/cobertura/mapa-zonas.webp" width="524" height="394" />
      {ROTULOS.map((r) => (
        <g key={r.nombre} transform={`translate(${r.x} ${r.y})`}>
          <rect x={-r.ancho / 2} y="-15" width={r.ancho} height="30" rx="15" fill="#0b1218" stroke="rgba(224,168,19,0.75)" strokeWidth="1.2" />
          <text textAnchor="middle" dominantBaseline="central" fontSize="15" fontWeight="600" fill="#faf8f2">{r.nombre}</text>
        </g>
      ))}
      <text x="518" y="387" textAnchor="end" fontSize="10" fill="rgba(250,248,242,0.55)">© Mapbox © OpenStreetMap</text>
    </svg>
  )
}

/** Tarjeta «Dónde hay servicio»: el mapa y, debajo, las zonas activas del sistema. */
export function Cobertura() {
  const zonas = useAsync(listarZonasDisponibles)
  const lista = zonas.data ?? []
  const nombres = lista.length ? lista.map((z) => bonito(z.nombre)) : zonas.loading ? [] : RESPALDO

  return (
    <section aria-label="Zonas con servicio" className="landing-brillo mt-10 overflow-hidden rounded-3xl bg-[#111b23]/90 backdrop-blur-sm">
      <Mapa />
      <div className="flex flex-col gap-3 p-6">
        <p className="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.2em] text-lgold"><MapPin className="size-4" aria-hidden="true" /> Zonas con servicio</p>
        <h3 className="font-heading text-2xl text-lcream">Guadalajara</h3>
        {nombres.length > 0 ? (
          <ul className="flex flex-wrap gap-2">{nombres.map((n) => <li key={n} className="rounded-full border border-lgold/45 bg-lgold/10 px-3 py-1 text-sm font-medium text-lcream">{n}</li>)}</ul>
        ) : (
          <div aria-hidden="true" className="flex gap-2"><span className="h-7 w-20 animate-pulse rounded-full bg-white/10" /><span className="h-7 w-24 animate-pulse rounded-full bg-white/10" /></div>
        )}
        <p className="text-sm leading-6 text-lcream/65">¿Tu empresa o tu casa está en otra zona? Escríbenos y revisamos cómo llegar.</p>
      </div>
    </section>
  )
}
