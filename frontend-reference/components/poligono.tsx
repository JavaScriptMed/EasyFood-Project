'use client'

/** Lo que se puede leer de un archivo GeoJSON antes de mandarlo: el polígono para dibujarlo y cuántos puntos tiene. */
export type PoligonoLeido = { anillos: [number, number][][]; puntos: number }

/** Saca los anillos exteriores de un Polygon, MultiPolygon, Feature o FeatureCollection (solo para la vista previa; el servidor valida de verdad). */
export function anillosDe(geojson: any): PoligonoLeido | null {
  const g = geojson?.type === 'FeatureCollection' ? geojson.features?.[0]?.geometry : geojson?.type === 'Feature' ? geojson.geometry : geojson
  const poligonos: any[] = g?.type === 'Polygon' ? [g.coordinates] : g?.type === 'MultiPolygon' ? g.coordinates : []
  const anillos = poligonos.map((p) => p?.[0]).filter((a) => Array.isArray(a) && a.every((pt: any) => Array.isArray(pt) && typeof pt[0] === 'number' && typeof pt[1] === 'number')) as [number, number][][]
  return anillos.length ? { anillos, puntos: anillos.reduce((n, a) => n + a.length, 0) } : null
}

/** Dibujo simple del polígono (longitud hacia la derecha, latitud hacia arriba) para comprobar de un vistazo que el archivo es el que esperabas. */
export function VistaPreviaPoligono({ poligono, etiqueta }: { poligono: PoligonoLeido; etiqueta: string }) {
  const puntos = poligono.anillos.flat()
  const [minX, maxX] = [Math.min(...puntos.map((p) => p[0])), Math.max(...puntos.map((p) => p[0]))]
  const [minY, maxY] = [Math.min(...puntos.map((p) => p[1])), Math.max(...puntos.map((p) => p[1]))]
  const ancho = Math.max(maxX - minX, 1e-9)
  const alto = Math.max(maxY - minY, 1e-9)
  const escala = 180 / Math.max(ancho, alto)
  const trazo = (anillo: [number, number][]) => anillo.map(([x, y]) => `${10 + (x - minX) * escala},${10 + (maxY - y) * escala}`).join(' ')
  return (
    <svg role="img" aria-label={etiqueta} viewBox={`0 0 ${20 + ancho * escala} ${20 + alto * escala}`} className="mx-auto h-40 w-full rounded-lg border bg-muted/30">
      {poligono.anillos.map((a, i) => <polygon key={i} points={trazo(a)} className="fill-primary/25 stroke-primary" strokeWidth={1.5} strokeLinejoin="round" />)}
    </svg>
  )
}

/** Lee un archivo .geojson o .json (hasta 5 MB) y lo convierte a objeto. */
export async function leerGeoJson(archivo: File): Promise<any> {
  if (archivo.size > 5 * 1024 * 1024) throw new Error('El archivo pesa más de 5 MB.')
  try {
    return JSON.parse(await archivo.text())
  } catch {
    throw new Error('El archivo no es un JSON válido: guárdalo como GeoJSON (.geojson).')
  }
}
