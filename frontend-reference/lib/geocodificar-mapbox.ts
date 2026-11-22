import { listarZonas, obtenerZona } from './api'
import type { EstadoParada, RutaDetalle, ZonaDetalle } from './types'

export interface Coordenada {
  latitud: number
  longitud: number
}

export interface GeometriaPoligono {
  type: 'Polygon' | 'MultiPolygon'
  coordinates: number[][][] | number[][][][]
}

export interface FeatureZona {
  type: 'Feature'
  properties: { nombre: string; id?: string; color?: string }
  geometry: GeometriaPoligono
}

export interface ZonaConPoligono extends ZonaDetalle {
  feature: FeatureZona
}

export interface ParadaMapa {
  id: string
  orden: number
  rutaId: string
  repartidor: string
  zona: string
  destinatario: string
  direccion: string
  estado: EstadoParada
  horaEstimada: string
  color: string
  latitud: number
  longitud: number
  origenUbicacion: 'api' | 'geocodificada'
}

export const CENTRO_GUADALAJARA: [number, number] = [-103.3496, 20.6597]

export const COLORES_ZONAS = ['#e9b51f', '#3d7fe8', '#43a86b', '#d9822b', '#a855f7', '#06b6d4']

const PREFIJO_CACHE = 'easylunch.geocode.v1:'
const cacheMemoria = new Map<string, Coordenada | null>()
const enCurso = new Map<string, Promise<Coordenada | null>>()
let cacheZonas: Promise<ZonaConPoligono[]> | null = null
let cacheZonasDesde = 0
/** Las zonas se vuelven a pedir después de este tiempo: así un polígono editado en otra pestaña o sesión no se queda viejo. */
const VIGENCIA_ZONAS_MS = 5 * 60 * 1000
/** Consultas de geocodificación en paralelo como máximo (Mapbox limita la tasa de peticiones). */
const GEOCODIFICAR_EN_PARALELO = 4

export function obtenerTokenMapbox(): string {
  return (process.env.NEXT_PUBLIC_MAPBOX_TOKEN ?? '').trim()
}

function normalizarDireccion(direccion: string): string {
  return direccion.trim().toLowerCase().replace(/\s+/g, ' ')
}

function leerSessionStorage(clave: string): { encontrado: boolean; valor: Coordenada | null } {
  if (typeof window === 'undefined') return { encontrado: false, valor: null }
  try {
    const crudo = window.sessionStorage.getItem(PREFIJO_CACHE + clave)
    if (crudo === null) return { encontrado: false, valor: null }
    const parsed = JSON.parse(crudo)
    if (parsed === null) return { encontrado: true, valor: null }
    if (typeof parsed?.latitud === 'number' && typeof parsed?.longitud === 'number') {
      return { encontrado: true, valor: { latitud: parsed.latitud, longitud: parsed.longitud } }
    }
  } catch {
    // ignorar errores de storage bloqueado o JSON inválido
  }
  return { encontrado: false, valor: null }
}

function guardarSessionStorage(clave: string, valor: Coordenada | null): void {
  if (typeof window === 'undefined') return
  try {
    window.sessionStorage.setItem(PREFIJO_CACHE + clave, JSON.stringify(valor))
  } catch {
    // ignorar cuota excedida o navegación privada
  }
}

/**
 * Geocodifica una dirección con Mapbox Geocoding (acotado al Área Metropolitana de Guadalajara).
 * Hace como máximo una consulta por dirección única y guarda el resultado (incluidos fallos) en memoria y sessionStorage.
 * Nunca lanza excepciones: si falta el token o falla la red devuelve null.
 */
export async function geocodificarDireccion(direccion: string): Promise<Coordenada | null> {
  const clave = normalizarDireccion(direccion)
  if (!clave) return null

  if (cacheMemoria.has(clave)) return cacheMemoria.get(clave) ?? null

  const guardado = leerSessionStorage(clave)
  if (guardado.encontrado) {
    cacheMemoria.set(clave, guardado.valor)
    return guardado.valor
  }

  const pendiente = enCurso.get(clave)
  if (pendiente) return pendiente

  const token = obtenerTokenMapbox()
  if (!token) return null

  const consulta = `${direccion.trim()}, Jalisco, México`
  const url = `https://api.mapbox.com/geocoding/v5/mapbox.places/${encodeURIComponent(consulta)}.json?access_token=${encodeURIComponent(token)}&country=mx&proximity=-103.3496,20.6597&bbox=-103.75,20.40,-103.05,20.95&limit=1&language=es`

  const promesa = (async (): Promise<Coordenada | null> => {
    try {
      const res = await fetch(url)
      if (!res.ok) return null
      const data = await res.json()
      const centro = data?.features?.[0]?.center
      const coord: Coordenada | null =
        Array.isArray(centro) && typeof centro[0] === 'number' && typeof centro[1] === 'number'
          ? { longitud: Math.round(centro[0] * 1e6) / 1e6, latitud: Math.round(centro[1] * 1e6) / 1e6 }
          : null
      cacheMemoria.set(clave, coord)
      guardarSessionStorage(clave, coord)
      return coord
    } catch {
      return null
    } finally {
      enCurso.delete(clave)
    }
  })()

  enCurso.set(clave, promesa)
  return promesa
}

function extraerFeature(zona: ZonaDetalle): FeatureZona | null {
  const g: any = zona.geojson
  if (!g || typeof g !== 'object') return null
  const geom = g.type === 'Feature' ? g.geometry : g.type === 'FeatureCollection' ? g.features?.[0]?.geometry : g
  if (!geom || !['Polygon', 'MultiPolygon'].includes(geom.type) || !Array.isArray(geom.coordinates)) return null
  return {
    type: 'Feature',
    properties: { nombre: zona.nombre, id: zona.id },
    geometry: geom as GeometriaPoligono,
  }
}

/**
 * Carga las zonas activas con sus polígonos GeoJSON reutilizando la API existente (`GET /admin/zonas` + `GET /admin/zonas/{id}`).
 */
export function cargarZonasConPoligonos(forzar = false): Promise<ZonaConPoligono[]> {
  if (!forzar && cacheZonas && Date.now() - cacheZonasDesde < VIGENCIA_ZONAS_MS) return cacheZonas
  cacheZonasDesde = Date.now()
  const promesa = (async () => {
    const lista = await listarZonas()
    const activas = lista.filter((z) => z.activo)
    const detalles = await Promise.all(
      activas.map(async (z) => {
        try {
          return await obtenerZona(z.id)
        } catch {
          return z
        }
      }),
    )
    return detalles
      .map((z) => {
        const feature = extraerFeature(z)
        return feature ? ({ ...z, feature } as ZonaConPoligono) : null
      })
      .filter((z): z is ZonaConPoligono => z !== null)
  })()

  cacheZonas = promesa.catch((err) => {
    cacheZonas = null
    throw err
  })
  return cacheZonas
}

/**
 * Resuelve las paradas de un conjunto de rutas para el mapa.
 * Usa `parada.ubicacion` cuando viene de la API; si es null (empresas sin coordenadas), geocodifica su dirección una sola vez con caché.
 */
export async function resolverParadasMapa(
  rutas: RutaDetalle[],
  colores: string[],
): Promise<{ paradas: ParadaMapa[]; sinUbicacion: number }> {
  const items: Array<{ ruta: RutaDetalle; color: string; parada: RutaDetalle['paradas'][number] }> = []
  rutas.forEach((ruta, idx) => {
    const color = colores[idx % colores.length]
    ruta.paradas.forEach((parada) => items.push({ ruta, color, parada }))
  })

  // Geocodificar una sola vez cada dirección única de las paradas sin coordenadas
  const direccionesPendientes = Array.from(
    new Set(
      items
        .filter(({ parada }) => !parada.ubicacion && parada.direccion?.trim())
        .map(({ parada }) => parada.direccion.trim()),
    ),
  )

  const geocodificadas = new Map<string, Coordenada | null>()
  for (let i = 0; i < direccionesPendientes.length; i += GEOCODIFICAR_EN_PARALELO) {
    await Promise.all(
      direccionesPendientes.slice(i, i + GEOCODIFICAR_EN_PARALELO).map(async (dir) => {
        geocodificadas.set(normalizarDireccion(dir), await geocodificarDireccion(dir))
      }),
    )
  }

  const paradas: ParadaMapa[] = []
  let sinUbicacion = 0

  for (const { ruta, color, parada } of items) {
    if (parada.ubicacion && typeof parada.ubicacion.latitud === 'number' && typeof parada.ubicacion.longitud === 'number') {
      paradas.push({
        id: parada.id,
        orden: parada.orden,
        rutaId: ruta.id,
        repartidor: ruta.repartidor_nombre,
        zona: ruta.zona,
        destinatario: parada.nombre_destinatario,
        direccion: parada.direccion,
        estado: parada.estado,
        horaEstimada: parada.hora_estimada,
        color,
        latitud: parada.ubicacion.latitud,
        longitud: parada.ubicacion.longitud,
        origenUbicacion: 'api',
      })
      continue
    }

    const clave = normalizarDireccion(parada.direccion ?? '')
    const punto = clave ? geocodificadas.get(clave) ?? null : null
    if (punto) {
      paradas.push({
        id: parada.id,
        orden: parada.orden,
        rutaId: ruta.id,
        repartidor: ruta.repartidor_nombre,
        zona: ruta.zona,
        destinatario: parada.nombre_destinatario,
        direccion: parada.direccion,
        estado: parada.estado,
        horaEstimada: parada.hora_estimada,
        color,
        latitud: punto.latitud,
        longitud: punto.longitud,
        origenUbicacion: 'geocodificada',
      })
    } else {
      sinUbicacion += 1
    }
  }

  return { paradas, sinUbicacion }
}

// ---------------------------------------------------------------- buscador de lugares y direcciones (Search Box API de Mapbox)

export interface LugarSugerido {
  id: string
  nombre: string
  /** Segunda línea de la sugerencia: la dirección completa o, si no la hay, el municipio. */
  detalle: string
}

export interface LugarElegido {
  nombre: string
  calle: string
  colonia: string
  cp: string
  municipio: string
  latitud: number
  longitud: number
}

/** Dirección de un punto del mapa (sin el nombre del negocio ni las coordenadas). */
export type DireccionDePunto = Omit<LugarElegido, 'nombre' | 'latitud' | 'longitud'>

export type ResultadoDeBusqueda<T> = { estado: 'ok'; datos: T } | { estado: 'sin_token' } | { estado: 'cancelado' } | { estado: 'error' }

const SEARCH_BOX = 'https://api.mapbox.com/search/searchbox/v1'
const PREFIJO_CACHE_BUSQUEDA = 'easylunch.searchbox.v1:'
/** Misma zona de búsqueda que la geocodificación: el Área Metropolitana de Guadalajara. */
const PARAMETROS_DE_ZONA = { country: 'mx', language: 'es', proximity: '-103.3496,20.6597', bbox: '-103.75,20.40,-103.05,20.95' }
const MAX_SUGERENCIAS = 5
const MAX_CONSULTAS_EN_MEMORIA = 100
const cacheSugerencias = new Map<string, LugarSugerido[]>()
const cacheLugares = new Map<string, LugarElegido | null>()
const cacheDirecciones = new Map<string, DireccionDePunto | null>()

/** Identificador de una sesión de búsqueda: Mapbox cobra por sesión (varias sugerencias y una selección), así que se renueva al elegir. */
export function nuevaSesionDeBusqueda(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID()
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = Math.floor(Math.random() * 16)
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16)
  })
}

function guardarEnMemoria<T>(cache: Map<string, T>, clave: string, valor: T): void {
  if (cache.size >= MAX_CONSULTAS_EN_MEMORIA) cache.delete(cache.keys().next().value as string)
  cache.set(clave, valor)
}

function leerCacheDeSesion<T>(clave: string): { encontrado: boolean; valor: T | null } {
  if (typeof window === 'undefined') return { encontrado: false, valor: null }
  try {
    const crudo = window.sessionStorage.getItem(PREFIJO_CACHE_BUSQUEDA + clave)
    if (crudo !== null) return { encontrado: true, valor: JSON.parse(crudo) as T | null }
  } catch {
    // storage bloqueado o JSON inválido: se consulta de nuevo
  }
  return { encontrado: false, valor: null }
}

function guardarCacheDeSesion(clave: string, valor: unknown): void {
  if (typeof window === 'undefined') return
  try {
    window.sessionStorage.setItem(PREFIJO_CACHE_BUSQUEDA + clave, JSON.stringify(valor))
  } catch {
    // cuota excedida o navegación privada
  }
}

function texto(valor: unknown): string {
  return typeof valor === 'string' ? valor.trim() : ''
}

/** Algunos negocios traen la dirección completa en `address` («Av. Fray Antonio Alcalde 10, 44100 Guadalajara…»): solo interesa lo de antes de la primera coma. */
function soloCalle(direccion: string): string {
  return direccion.split(',')[0].trim()
}

/** Extrae calle y número, colonia, código postal y municipio de un resultado de la Search Box API. */
function direccionDeFeature(props: any): DireccionDePunto {
  const contexto = props?.context ?? {}
  const tipo = texto(props?.feature_type)
  const calle = soloCalle(
    texto(contexto.address?.name) ||
    (tipo === 'address' ? texto(props?.name) : '') ||
    (tipo === 'poi' ? texto(props?.address) : '') ||
    texto(contexto.street?.name) ||
    (tipo === 'street' ? texto(props?.name) : ''),
  )
  return {
    calle,
    colonia: texto(contexto.neighborhood?.name) || texto(contexto.locality?.name),
    cp: texto(contexto.postcode?.name),
    municipio: texto(contexto.place?.name) || texto(contexto.district?.name) || (tipo === 'place' ? texto(props?.name) : ''),
  }
}

/**
 * Sugerencias de lugares (negocios, direcciones, calles y municipios) mientras se escribe.
 * Nunca lanza excepciones: sin token, con la red caída o con Mapbox fallando devuelve el estado correspondiente.
 */
export async function sugerirLugares(consulta: string, sesion: string, senal?: AbortSignal): Promise<ResultadoDeBusqueda<LugarSugerido[]>> {
  const q = consulta.trim()
  if (!q) return { estado: 'ok', datos: [] }
  const token = obtenerTokenMapbox()
  if (!token) return { estado: 'sin_token' }

  const clave = normalizarDireccion(q)
  const guardadas = cacheSugerencias.get(clave)
  if (guardadas) return { estado: 'ok', datos: guardadas }

  const parametros = new URLSearchParams({ q, access_token: token, session_token: sesion, limit: String(MAX_SUGERENCIAS), types: 'poi,address,street,place', ...PARAMETROS_DE_ZONA })
  try {
    const res = await fetch(`${SEARCH_BOX}/suggest?${parametros}`, { signal: senal })
    if (!res.ok) return { estado: 'error' }
    const data = await res.json()
    const lista: LugarSugerido[] = (Array.isArray(data?.suggestions) ? data.suggestions : [])
      .filter((s: any) => texto(s?.mapbox_id) && texto(s?.name))
      .slice(0, MAX_SUGERENCIAS)
      .map((s: any) => {
        const completa = texto(s.full_address)
        const nombre = texto(s.name)
        return { id: texto(s.mapbox_id), nombre, detalle: completa && completa !== nombre ? completa : [texto(s.address), texto(s.place_formatted)].filter(Boolean).join(', ') || texto(s.place_formatted) }
      })
    guardarEnMemoria(cacheSugerencias, clave, lista)
    return { estado: 'ok', datos: lista }
  } catch (error) {
    return (error as { name?: string })?.name === 'AbortError' ? { estado: 'cancelado' } : { estado: 'error' }
  }
}

/** Coordenadas y dirección de la sugerencia elegida (cierra la sesión de búsqueda). */
export async function recuperarLugar(id: string, sesion: string): Promise<ResultadoDeBusqueda<LugarElegido | null>> {
  const token = obtenerTokenMapbox()
  if (!token) return { estado: 'sin_token' }
  if (cacheLugares.has(id)) return { estado: 'ok', datos: cacheLugares.get(id) ?? null }
  const guardado = leerCacheDeSesion<LugarElegido>(`retrieve:${id}`)
  if (guardado.encontrado) {
    guardarEnMemoria(cacheLugares, id, guardado.valor)
    return { estado: 'ok', datos: guardado.valor }
  }

  const parametros = new URLSearchParams({ access_token: token, session_token: sesion, language: 'es' })
  try {
    const res = await fetch(`${SEARCH_BOX}/retrieve/${encodeURIComponent(id)}?${parametros}`)
    if (!res.ok) return { estado: 'error' }
    const data = await res.json()
    const feature = data?.features?.[0]
    const [longitud, latitud] = Array.isArray(feature?.geometry?.coordinates) ? feature.geometry.coordinates : []
    const lugar: LugarElegido | null =
      typeof latitud === 'number' && typeof longitud === 'number'
        ? { nombre: texto(feature.properties?.name), ...direccionDeFeature(feature.properties), latitud: Math.round(latitud * 1e6) / 1e6, longitud: Math.round(longitud * 1e6) / 1e6 }
        : null
    guardarEnMemoria(cacheLugares, id, lugar)
    guardarCacheDeSesion(`retrieve:${id}`, lugar)
    return { estado: 'ok', datos: lugar }
  } catch {
    return { estado: 'error' }
  }
}

/** Dirección más cercana a un punto del mapa (cuando el pin se arrastra o se hace clic). */
export async function direccionDelPunto(latitud: number, longitud: number): Promise<ResultadoDeBusqueda<DireccionDePunto | null>> {
  const token = obtenerTokenMapbox()
  if (!token) return { estado: 'sin_token' }
  const clave = `${latitud.toFixed(5)},${longitud.toFixed(5)}`
  if (cacheDirecciones.has(clave)) return { estado: 'ok', datos: cacheDirecciones.get(clave) ?? null }
  const guardado = leerCacheDeSesion<DireccionDePunto>(`reverse:${clave}`)
  if (guardado.encontrado) {
    guardarEnMemoria(cacheDirecciones, clave, guardado.valor)
    return { estado: 'ok', datos: guardado.valor }
  }

  const parametros = new URLSearchParams({ access_token: token, longitude: String(longitud), latitude: String(latitud), language: 'es', limit: '1', types: 'address' })
  try {
    const res = await fetch(`${SEARCH_BOX}/reverse?${parametros}`)
    if (!res.ok) return { estado: 'error' }
    const data = await res.json()
    const props = data?.features?.[0]?.properties
    const direccion = props ? direccionDeFeature(props) : null
    const util = direccion && (direccion.calle || direccion.colonia || direccion.cp || direccion.municipio) ? direccion : null
    guardarEnMemoria(cacheDirecciones, clave, util)
    guardarCacheDeSesion(`reverse:${clave}`, util)
    return { estado: 'ok', datos: util }
  } catch {
    return { estado: 'error' }
  }
}

// ---------------------------------------------------------------- vista previa local de zona (la definitiva la calcula PostGIS en el servidor)

function puntoEnAnillo(lon: number, lat: number, anillo: number[][]): boolean {
  let dentro = false
  for (let i = 0, j = anillo.length - 1; i < anillo.length; j = i++) {
    const xi = anillo[i][0]
    const yi = anillo[i][1]
    const xj = anillo[j][0]
    const yj = anillo[j][1]
    const intersecta = yi > lat !== yj > lat && lon < ((xj - xi) * (lat - yi)) / (yj - yi + 1e-15) + xi
    if (intersecta) dentro = !dentro
  }
  return dentro
}

function puntoEnPoligono(lon: number, lat: number, poligono: number[][][]): boolean {
  if (!poligono.length || !puntoEnAnillo(lon, lat, poligono[0])) return false
  for (let i = 1; i < poligono.length; i++) {
    if (puntoEnAnillo(lon, lat, poligono[i])) return false // hueco interior
  }
  return true
}

function puntoEnGeometria(lon: number, lat: number, geom: GeometriaPoligono): boolean {
  if (geom.type === 'Polygon') return puntoEnPoligono(lon, lat, geom.coordinates as number[][][])
  return (geom.coordinates as number[][][][]).some((poly) => puntoEnPoligono(lon, lat, poly))
}

/**
 * Vista previa de qué zona activa cubre el punto (misma regla de empate que PostGIS: gana la más pequeña).
 * El servidor siempre calcula y valida la zona definitiva con ST_Covers al guardar.
 */
export function puntoEnZona(latitud: number, longitud: number, zonas: ZonaConPoligono[]): string | null {
  if (!Number.isFinite(latitud) || !Number.isFinite(longitud)) return null
  const ordenadas = [...zonas].filter((z) => z.activo).sort((a, b) => a.area_km2 - b.area_km2 || a.id.localeCompare(b.id, undefined, { numeric: true }))
  for (const zona of ordenadas) {
    if (puntoEnGeometria(longitud, latitud, zona.feature.geometry)) return zona.nombre
  }
  return null
}

/** Calcula el centroide aproximado (promedio del recuadro) de un polígono para colocar su etiqueta en el mapa. */
export function centroDeGeometria(geom: GeometriaPoligono): [number, number] | null {
  let minLon = Infinity
  let maxLon = -Infinity
  let minLat = Infinity
  let maxLat = -Infinity

  const visitar = (anillo: number[][]) => {
    for (const [lon, lat] of anillo) {
      if (lon < minLon) minLon = lon
      if (lon > maxLon) maxLon = lon
      if (lat < minLat) minLat = lat
      if (lat > maxLat) maxLat = lat
    }
  }

  if (geom.type === 'Polygon') {
    const coords = geom.coordinates as number[][][]
    if (coords[0]) visitar(coords[0])
  } else {
    for (const poly of geom.coordinates as number[][][][]) {
      if (poly[0]) visitar(poly[0])
    }
  }

  if (!Number.isFinite(minLon)) return null
  return [(minLon + maxLon) / 2, (minLat + maxLat) / 2]
}
