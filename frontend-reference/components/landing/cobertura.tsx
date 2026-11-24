'use client'

import { MapPin } from 'lucide-react'
import { useMemo } from 'react'
import { listarZonasDisponibles } from '@/lib/api'
import type { ZonaDisponible } from '@/lib/types'
import { useAsync } from '@/lib/use-async'

type Anillo = [number, number][]

/** Zonas por si el servidor no responde: la tarjeta se ve igual, solo que sin el dibujo de los polígonos. */
const RESPALDO = ['Guadalajara', 'Zapopan', 'Tlaquepaque', 'Tonalá']

/** Los anillos exteriores de una zona (Polygon o MultiPolygon), como pares [longitud, latitud]. */
function anillosDe(zona: ZonaDisponible): Anillo[] {
  const g = zona.geojson.geometry
  const c = g.coordinates as number[][][] | number[][][][]
  const poligonos = (g.type === 'Polygon' ? [c as number[][][]] : (c as number[][][][])).filter(Boolean)
  return poligonos.map((p) => p[0] as Anillo).filter((a) => Array.isArray(a) && a.length > 2)
}

/** «guadalajara» se muestra como «Guadalajara»; un nombre que ya trae mayúsculas no se toca. */
const bonito = (nombre: string) => (nombre === nombre.toLowerCase() ? nombre.replace(/(^|\s)(\S)/g, (_, e, l) => e + l.toUpperCase()) : nombre)

const ANCHO = 320
const ALTO = 200
const MARGEN = 16

/** Dibuja las zonas con servicio: contornos dorados y un punto que late en el centro de cada una. */
function Mapa({ zonas }: { zonas: ZonaDisponible[] }) {
  const dibujo = useMemo(() => {
    const todas = zonas.map((z) => ({ zona: z, anillos: anillosDe(z) })).filter((z) => z.anillos.length)
    const puntos = todas.flatMap((z) => z.anillos.flat())
    if (!puntos.length) return null
    const lons = puntos.map((p) => p[0])
    const lats = puntos.map((p) => p[1])
    const [minX, maxX, minY, maxY] = [Math.min(...lons), Math.max(...lons), Math.min(...lats), Math.max(...lats)]
    const k = Math.cos(((minY + maxY) / 2) * (Math.PI / 180)) // un grado de longitud mide menos que uno de latitud
    const escala = Math.min((ANCHO - MARGEN * 2) / ((maxX - minX) * k || 1), (ALTO - MARGEN * 2) / (maxY - minY || 1))
    const ox = (ANCHO - (maxX - minX) * k * escala) / 2
    const oy = (ALTO - (maxY - minY) * escala) / 2
    const proyectar = ([lon, lat]: [number, number]) => [ox + (lon - minX) * k * escala, oy + (maxY - lat) * escala] as const
    return todas.map(({ zona, anillos }) => {
      const xs = anillos.flat().map((p) => proyectar(p)[0])
      const ys = anillos.flat().map((p) => proyectar(p)[1])
      return {
        id: zona.id,
        nombre: zona.nombre,
        trazo: anillos.map((a) => `M${a.map((p) => proyectar(p).map((n) => n.toFixed(1)).join(' ')).join('L')}Z`).join(''),
        centro: [(Math.min(...xs) + Math.max(...xs)) / 2, (Math.min(...ys) + Math.max(...ys)) / 2] as const,
      }
    })
  }, [zonas])

  return (
    <svg viewBox={`0 0 ${ANCHO} ${ALTO}`} role="img" aria-label={`Mapa de las zonas con servicio: ${zonas.map((z) => bonito(z.nombre)).join(', ')}`} className="size-full">
      <defs>
        <pattern id="cobertura-rejilla" width="20" height="20" patternUnits="userSpaceOnUse"><path d="M20 0H0V20" fill="none" stroke="rgba(250,248,242,0.05)" strokeWidth="1" /></pattern>
        <radialGradient id="cobertura-punto"><stop offset="0" stopColor="#ffd45e" /><stop offset="1" stopColor="#e0a813" /></radialGradient>
      </defs>
      <rect width={ANCHO} height={ALTO} fill="#0b1218" />
      <rect width={ANCHO} height={ALTO} fill="url(#cobertura-rejilla)" />
      {dibujo?.map((z) => <path key={z.id} d={z.trazo} fill="rgba(224,168,19,0.13)" stroke="rgba(224,168,19,0.75)" strokeWidth="1.4" strokeLinejoin="round" />)}
      {dibujo?.map((z, i) => (
        <g key={`p${z.id}`}>
          <circle cx={z.centro[0]} cy={z.centro[1]} r="9" fill="rgba(224,168,19,0.28)" className="landing-flotar" style={{ animationDelay: `${i * 0.4}s` }} />
          <circle cx={z.centro[0]} cy={z.centro[1]} r="4.2" fill="url(#cobertura-punto)" stroke="#fff3c4" strokeWidth="1" />
        </g>
      ))}
    </svg>
  )
}

/** Tarjeta «Dónde hay servicio»: las zonas activas del sistema, con su mapa. */
export function Cobertura() {
  const zonas = useAsync(listarZonasDisponibles)
  const lista = zonas.data ?? []
  const nombres = lista.length ? lista.map((z) => bonito(z.nombre)) : zonas.loading ? [] : RESPALDO

  return (
    <section aria-label="Zonas con servicio" className="landing-brillo mt-10 overflow-hidden rounded-3xl bg-[#111b23]/90 backdrop-blur-sm">
      <div className="grid sm:grid-cols-[1.15fr_1fr]">
        <div className="relative aspect-[16/10] bg-[#0b1218] sm:aspect-auto sm:min-h-[12.5rem]">
          {lista.length ? <Mapa zonas={lista} /> : <div aria-hidden="true" className="size-full animate-pulse bg-[radial-gradient(ellipse_at_50%_50%,rgba(224,168,19,0.12),transparent_70%)]" />}
        </div>
        <div className="flex flex-col justify-center gap-3 p-6">
          <p className="flex items-center gap-2 text-xs font-bold uppercase tracking-[0.2em] text-lgold"><MapPin className="size-4" aria-hidden="true" /> Zonas con servicio</p>
          <h3 className="font-heading text-2xl text-lcream">Guadalajara</h3>
          {nombres.length > 0 ? (
            <ul className="flex flex-wrap gap-2">{nombres.map((n) => <li key={n} className="rounded-full border border-lgold/45 bg-lgold/10 px-3 py-1 text-sm font-medium text-lcream">{n}</li>)}</ul>
          ) : (
            <div aria-hidden="true" className="flex gap-2"><span className="h-7 w-20 animate-pulse rounded-full bg-white/10" /><span className="h-7 w-24 animate-pulse rounded-full bg-white/10" /></div>
          )}
          <p className="text-sm leading-6 text-lcream/65">¿Tu empresa o tu casa está en otra zona? Escríbenos y revisamos cómo llegar.</p>
        </div>
      </div>
    </section>
  )
}
