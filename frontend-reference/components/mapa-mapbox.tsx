"use client"

import { useEffect, useMemo, useRef, useState } from "react"
import { AlertTriangle, MapPin } from "lucide-react"
import type { EstadoParada } from "@/lib/types"
import {
  CENTRO_GUADALAJARA,
  COLORES_ZONAS,
  centroDeGeometria,
  obtenerTokenMapbox,
  type ParadaMapa,
  type ZonaConPoligono,
} from "@/lib/geocodificar-mapbox"

type MapboxModule = typeof import("mapbox-gl")
type MapboxMap = import("mapbox-gl").Map
type MapboxMarker = import("mapbox-gl").Marker

const ETIQUETA_ESTADO: Record<EstadoParada, string> = {
  pendiente: "Pendiente",
  en_camino: "En camino",
  entregado: "Entregado",
  no_localizado: "No localizado",
}

function estiloMapboxActual(): string {
  if (typeof document !== "undefined" && document.documentElement.classList.contains("dark")) {
    return "mapbox://styles/mapbox/dark-v11"
  }
  return "mapbox://styles/mapbox/streets-v12"
}

function escaparHtml(texto: string): string {
  return texto
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;")
}

function aplicarPoligonosZonas(map: MapboxMap, zonas: ZonaConPoligono[]) {
  const featuresPoligonos = zonas.map((z, i) => ({
    ...z.feature,
    properties: {
      nombre: z.nombre,
      color: COLORES_ZONAS[i % COLORES_ZONAS.length],
    },
  }))

  const featuresEtiquetas = zonas
    .map((z) => {
      const centro = centroDeGeometria(z.feature.geometry)
      if (!centro) return null
      return {
        type: "Feature" as const,
        properties: { nombre: z.nombre },
        geometry: { type: "Point" as const, coordinates: centro },
      }
    })
    .filter((f): f is NonNullable<typeof f> => f !== null)

  const geoZonas = { type: "FeatureCollection" as const, features: featuresPoligonos }
  const geoEtiquetas = { type: "FeatureCollection" as const, features: featuresEtiquetas }

  const srcZonas = map.getSource("easylunch-zonas") as import("mapbox-gl").GeoJSONSource | undefined
  if (srcZonas) {
    srcZonas.setData(geoZonas as any)
  } else {
    map.addSource("easylunch-zonas", { type: "geojson", data: geoZonas as any })
    map.addLayer({
      id: "easylunch-zonas-fill",
      type: "fill",
      source: "easylunch-zonas",
      paint: {
        "fill-color": ["get", "color"],
        "fill-opacity": 0.16,
      },
    })
    map.addLayer({
      id: "easylunch-zonas-line",
      type: "line",
      source: "easylunch-zonas",
      paint: {
        "line-color": ["get", "color"],
        "line-width": 2,
        "line-opacity": 0.85,
      },
    })
  }

  const srcEtiquetas = map.getSource("easylunch-zonas-etiquetas") as import("mapbox-gl").GeoJSONSource | undefined
  if (srcEtiquetas) {
    srcEtiquetas.setData(geoEtiquetas as any)
  } else {
    map.addSource("easylunch-zonas-etiquetas", { type: "geojson", data: geoEtiquetas as any })
    map.addLayer({
      id: "easylunch-zonas-texto",
      type: "symbol",
      source: "easylunch-zonas-etiquetas",
      layout: {
        "text-field": ["get", "nombre"],
        "text-size": 12,
        "text-font": ["Open Sans Semibold", "Arial Unicode MS Bold"],
        "text-allow-overlap": false,
      },
      paint: {
        "text-color": "#111827",
        "text-halo-color": "#ffffff",
        "text-halo-width": 1.5,
      },
    })
  }
}

function extenderLimitesConGeometria(bounds: import("mapbox-gl").LngLatBounds, zona: ZonaConPoligono) {
  const geom = zona.feature.geometry
  const visitar = (anillo: number[][]) => {
    for (const [lon, lat] of anillo) bounds.extend([lon, lat])
  }
  if (geom.type === "Polygon") {
    const coords = geom.coordinates as number[][][]
    if (coords[0]) visitar(coords[0])
  } else {
    for (const poly of geom.coordinates as number[][][][]) {
      if (poly[0]) visitar(poly[0])
    }
  }
}

function AvisoSinToken({
  titulo,
  detalle,
  resumen,
  alturaMinima,
}: {
  titulo: string
  detalle: string
  resumen?: string
  alturaMinima: string
}) {
  return (
    <div
      className={`flex ${alturaMinima} flex-col items-center justify-center gap-3 rounded-2xl border border-dashed border-amber-500/40 bg-amber-500/5 p-6 text-center`}
      role="status"
    >
      <div className="flex size-11 items-center justify-center rounded-full bg-amber-500/15 text-amber-600 dark:text-amber-400">
        <AlertTriangle className="size-5" />
      </div>
      <div className="max-w-md space-y-1">
        <p className="font-semibold text-foreground">{titulo}</p>
        <p className="text-xs leading-relaxed text-muted-foreground">{detalle}</p>
        {resumen && <p className="pt-1 text-xs font-medium text-foreground/80">{resumen}</p>}
      </div>
    </div>
  )
}

export interface MapaOperacionProps {
  zonas: ZonaConPoligono[]
  paradas: ParadaMapa[]
  sinUbicacion: number
  rutaSeleccionadaId?: string | null
  mostrarLineasRuta?: boolean
  alturaMinima?: string
  subtitulo?: string
  enVivo?: boolean
}

/**
 * Mapa operativo de Mapbox (B.1 Dashboard y Rutas) cargado dinámicamente solo en cliente.
 * Muestra los polígonos activos de las zonas y las paradas del día centradas en Guadalajara.
 */
export function MapaOperacion({
  zonas,
  paradas,
  sinUbicacion,
  rutaSeleccionadaId = null,
  mostrarLineasRuta = false,
  alturaMinima = "min-h-[360px]",
  subtitulo,
  enVivo = false,
}: MapaOperacionProps) {
  const token = obtenerTokenMapbox()
  const contenedorRef = useRef<HTMLDivElement>(null)
  const mapRef = useRef<MapboxMap | null>(null)
  const mapboxRef = useRef<MapboxModule["default"] | null>(null)
  const marcadoresRef = useRef<MapboxMarker[]>([])
  const ajustadoRef = useRef(false)
  const [listo, setListo] = useState(false)
  const [errorMapa, setErrorMapa] = useState<string | null>(null)

  const lineasRuta = useMemo(() => {
    if (!mostrarLineasRuta) return { type: "FeatureCollection" as const, features: [] }
    const porRuta = new Map<string, ParadaMapa[]>()
    for (const p of paradas) {
      const arr = porRuta.get(p.rutaId) ?? []
      arr.push(p)
      porRuta.set(p.rutaId, arr)
    }
    const features = Array.from(porRuta.entries())
      .map(([rutaId, lista]) => {
        const ordenadas = [...lista].sort((a, b) => a.orden - b.orden)
        if (ordenadas.length < 2) return null
        return {
          type: "Feature" as const,
          properties: {
            rutaId,
            color: ordenadas[0].color,
            seleccionada: rutaSeleccionadaId === rutaId ? 1 : 0,
          },
          geometry: {
            type: "LineString" as const,
            coordinates: ordenadas.map((p) => [p.longitud, p.latitud]),
          },
        }
      })
      .filter((f): f is NonNullable<typeof f> => f !== null)
    return { type: "FeatureCollection" as const, features }
  }, [mostrarLineasRuta, paradas, rutaSeleccionadaId])

  // Inicialización dinámica solo en cliente
  useEffect(() => {
    if (!token || !contenedorRef.current) return
    let cancelado = false
    let mapaLocal: MapboxMap | null = null

    ;(async () => {
      try {
        const [mod] = await Promise.all([import("mapbox-gl"), import("mapbox-gl/dist/mapbox-gl.css")])
        if (cancelado || !contenedorRef.current) return
        const mapboxgl = mod.default
        mapboxgl.accessToken = token
        mapboxRef.current = mapboxgl

        mapaLocal = new mapboxgl.Map({
          container: contenedorRef.current,
          style: estiloMapboxActual(),
          center: CENTRO_GUADALAJARA,
          zoom: 11.2,
          attributionControl: true,
        })
        mapaLocal.addControl(new mapboxgl.NavigationControl({ showCompass: false }), "top-right")
        mapaLocal.on("load", () => {
          if (cancelado) return
          mapRef.current = mapaLocal
          setListo(true)
        })
        mapaLocal.on("error", () => {
          if (!cancelado && !mapRef.current) {
            setErrorMapa("No se pudo cargar el mapa de Mapbox. Verifica que el token público (pk.) sea válido.")
          }
        })
      } catch {
        if (!cancelado) {
          setErrorMapa("Este navegador no pudo inicializar WebGL para mostrar el mapa interactivo.")
        }
      }
    })()

    return () => {
      cancelado = true
      marcadoresRef.current.forEach((m) => m.remove())
      marcadoresRef.current = []
      mapRef.current = null
      setListo(false)
      ajustadoRef.current = false
      mapaLocal?.remove()
    }
  }, [token])

  // Sincronizar polígonos, líneas de ruta y marcadores de paradas
  useEffect(() => {
    const map = mapRef.current
    const mapboxgl = mapboxRef.current
    if (!listo || !map || !mapboxgl) return

    aplicarPoligonosZonas(map, zonas)

    const srcLineas = map.getSource("easylunch-rutas-lineas") as import("mapbox-gl").GeoJSONSource | undefined
    if (srcLineas) {
      srcLineas.setData(lineasRuta as any)
    } else if (mostrarLineasRuta) {
      map.addSource("easylunch-rutas-lineas", { type: "geojson", data: lineasRuta as any })
      map.addLayer({
        id: "easylunch-rutas-lineas-layer",
        type: "line",
        source: "easylunch-rutas-lineas",
        paint: {
          "line-color": ["get", "color"],
          "line-width": ["case", ["==", ["get", "seleccionada"], 1], 4, 2],
          "line-opacity": ["case", ["==", ["get", "seleccionada"], 1], 0.95, 0.4],
          "line-dasharray": [2, 1.5],
        },
      })
    }

    marcadoresRef.current.forEach((m) => m.remove())
    marcadoresRef.current = []

    for (const p of paradas) {
      const esActiva = !rutaSeleccionadaId || p.rutaId === rutaSeleccionadaId
      const el = document.createElement("button")
      el.type = "button"
      el.ariaLabel = `Parada ${p.orden}: ${p.destinatario}`
      el.style.width = esActiva ? "28px" : "22px"
      el.style.height = esActiva ? "28px" : "22px"
      el.style.borderRadius = "9999px"
      el.style.backgroundColor = p.color
      el.style.color = "#111827"
      el.style.border = "2px solid #ffffff"
      el.style.boxShadow = "0 2px 8px rgba(0,0,0,0.35)"
      el.style.fontWeight = "700"
      el.style.fontSize = esActiva ? "12px" : "10px"
      el.style.opacity = esActiva ? "1" : "0.55"
      el.style.display = "flex"
      el.style.alignItems = "center"
      el.style.justifyContent = "center"
      el.style.cursor = "pointer"
      el.textContent = String(p.orden)

      const popupHtml = `
        <div style="font-size:12px;line-height:1.4;color:#111827;max-width:220px;">
          <p style="font-weight:700;margin:0 0 2px 0;">#${p.orden} · ${escaparHtml(p.destinatario)}</p>
          <p style="margin:0 0 4px 0;color:#4b5563;">${escaparHtml(p.direccion)}</p>
          <p style="margin:0;font-size:11px;color:#374151;">
            <strong>${escaparHtml(p.zona)}</strong> · ${escaparHtml(p.repartidor)}<br/>
            ${escaparHtml(p.horaEstimada)} · ${escaparHtml(ETIQUETA_ESTADO[p.estado])}
          </p>
        </div>
      `

      const marker = new mapboxgl.Marker({ element: el })
        .setLngLat([p.longitud, p.latitud])
        .setPopup(new mapboxgl.Popup({ offset: 18 }).setHTML(popupHtml))
        .addTo(map)
      marcadoresRef.current.push(marker)
    }

    if (!ajustadoRef.current && (zonas.length > 0 || paradas.length > 0)) {
      const bounds = new mapboxgl.LngLatBounds()
      zonas.forEach((z) => extenderLimitesConGeometria(bounds, z))
      paradas.forEach((p) => bounds.extend([p.longitud, p.latitud]))
      if (!bounds.isEmpty()) {
        map.fitBounds(bounds, { padding: 48, maxZoom: 13.5, duration: 500 })
        ajustadoRef.current = true
      }
    }
  }, [listo, zonas, paradas, lineasRuta, mostrarLineasRuta, rutaSeleccionadaId])

  if (!token) {
    return (
      <AvisoSinToken
        alturaMinima={alturaMinima}
        titulo="Mapa interactivo no configurado"
        detalle="Configura tu token público (pk.) en NEXT_PUBLIC_MAPBOX_TOKEN dentro de .env.local (y en Vercel) para visualizar el mapa de Mapbox con las zonas y paradas en Guadalajara."
        resumen={`${zonas.length} zona(s) cargada(s) · ${paradas.length} parada(s) con ubicación${sinUbicacion > 0 ? ` · ${sinUbicacion} parada${sinUbicacion === 1 ? "" : "s"} sin ubicación` : ""}`}
      />
    )
  }

  if (errorMapa) {
    return (
      <AvisoSinToken
        alturaMinima={alturaMinima}
        titulo="No se pudo iniciar Mapbox"
        detalle={errorMapa}
        resumen={`${zonas.length} zona(s) cargada(s) · ${paradas.length} parada(s) con ubicación${sinUbicacion > 0 ? ` · ${sinUbicacion} parada${sinUbicacion === 1 ? "" : "s"} sin ubicación` : ""}`}
      />
    )
  }

  return (
    <div className={`relative ${alturaMinima} w-full overflow-hidden rounded-2xl border border-border/70 bg-muted/40`}>
      <div ref={contenedorRef} className={`h-full ${alturaMinima} w-full`} />
      <div className="pointer-events-none absolute left-3 top-3 flex flex-wrap items-center gap-2">
        <div className="rounded-lg border bg-background/90 px-3 py-1.5 text-xs shadow-sm backdrop-blur-sm">
          <p className="font-semibold text-foreground">Guadalajara</p>
          <p className="text-[11px] text-muted-foreground">
            {subtitulo ?? `${zonas.length} zona(s) · ${paradas.length} parada(s) en mapa`}
          </p>
        </div>
        {sinUbicacion > 0 && (
          <div className="flex items-center gap-1.5 rounded-lg border border-amber-500/40 bg-amber-500/15 px-3 py-1.5 text-xs font-semibold text-amber-800 shadow-sm backdrop-blur-sm dark:text-amber-300">
            <AlertTriangle className="size-3.5" />
            {sinUbicacion} {sinUbicacion === 1 ? "parada sin ubicación" : "paradas sin ubicación"}
          </div>
        )}
      </div>
      {enVivo && (
        <div className="pointer-events-none absolute bottom-3 left-3 flex items-center gap-2 rounded-full bg-slate-900/85 px-3 py-1.5 text-xs font-semibold text-white shadow-sm">
          <span className="size-2 animate-pulse rounded-full bg-emerald-400" />
          Monitoreo activo
        </div>
      )}
    </div>
  )
}

export interface MapaSelectorPuntoProps {
  zonas: ZonaConPoligono[]
  latitud: number | null
  longitud: number | null
  /** Solo se llama cuando la persona mueve el punto (clic en el mapa o arrastrar el pin), no cuando el punto llega de afuera. */
  onChange: (punto: { latitud: number; longitud: number }) => void
  /** Cada vez que cambia este número el mapa se acerca al punto (por ejemplo al elegir una sugerencia del buscador). */
  enfoque?: number
  /** Avisa cuando el mapa no se pudo iniciar (mensaje) o cuando ya está disponible (null). */
  onFalla?: (mensaje: string | null) => void
}

/**
 * Mapa del selector de punto de entrega: pin arrastrable y clic para colocarlo (Nueva empresa, paso 3, y Editar empresa).
 * Si falta el token público de Mapbox o el mapa no arranca, muestra un aviso y avisa con `onFalla`.
 */
export function MapaSelectorPunto({ zonas, latitud, longitud, onChange, enfoque, onFalla }: MapaSelectorPuntoProps) {
  const token = obtenerTokenMapbox()
  const onFallaRef = useRef(onFalla)
  onFallaRef.current = onFalla
  const contenedorRef = useRef<HTMLDivElement>(null)
  const mapRef = useRef<MapboxMap | null>(null)
  const mapboxRef = useRef<MapboxModule["default"] | null>(null)
  const markerRef = useRef<MapboxMarker | null>(null)
  const onChangeRef = useRef(onChange)
  onChangeRef.current = onChange
  const [listo, setListo] = useState(false)
  const [errorMapa, setErrorMapa] = useState<string | null>(null)

  useEffect(() => {
    if (!token || !contenedorRef.current) return
    let cancelado = false
    let mapaLocal: MapboxMap | null = null

    ;(async () => {
      try {
        const [mod] = await Promise.all([import("mapbox-gl"), import("mapbox-gl/dist/mapbox-gl.css")])
        if (cancelado || !contenedorRef.current) return
        const mapboxgl = mod.default
        mapboxgl.accessToken = token
        mapboxRef.current = mapboxgl

        const centroInicial: [number, number] =
          latitud !== null && longitud !== null ? [longitud, latitud] : CENTRO_GUADALAJARA

        mapaLocal = new mapboxgl.Map({
          container: contenedorRef.current,
          style: estiloMapboxActual(),
          center: centroInicial,
          zoom: latitud !== null && longitud !== null ? 15 : 11.2,
        })
        mapaLocal.addControl(new mapboxgl.NavigationControl({ showCompass: false }), "top-right")

        const crearOMoverPin = (lng: number, lat: number, notificar: boolean) => {
          if (!mapaLocal) return
          const latRed = Math.round(lat * 1e6) / 1e6
          const lngRed = Math.round(lng * 1e6) / 1e6
          if (!markerRef.current) {
            const m = new mapboxgl.Marker({ color: "#d69f0d", draggable: true }).setLngLat([lngRed, latRed]).addTo(mapaLocal)
            m.on("dragend", () => {
              const pos = m.getLngLat()
              onChangeRef.current({
                latitud: Math.round(pos.lat * 1e6) / 1e6,
                longitud: Math.round(pos.lng * 1e6) / 1e6,
              })
            })
            markerRef.current = m
          } else {
            markerRef.current.setLngLat([lngRed, latRed])
          }
          if (notificar) {
            onChangeRef.current({ latitud: latRed, longitud: lngRed })
          }
        }

        mapaLocal.on("click", (e) => {
          crearOMoverPin(e.lngLat.lng, e.lngLat.lat, true)
        })

        mapaLocal.on("load", () => {
          if (cancelado) return
          mapRef.current = mapaLocal
          setListo(true)
        })

        mapaLocal.on("error", () => {
          if (!cancelado && !mapRef.current) {
            setErrorMapa("No se pudo cargar el mapa de Mapbox. Revisa tu conexión y que el token sea válido para este sitio.")
          }
        })
      } catch {
        if (!cancelado) {
          setErrorMapa("Este navegador no puede mostrar el mapa (necesita WebGL).")
        }
      }
    })()

    return () => {
      cancelado = true
      markerRef.current?.remove()
      markerRef.current = null
      mapRef.current = null
      setListo(false)
      mapaLocal?.remove()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [token])

  // Avisar a quien lo usa si el mapa no está disponible
  useEffect(() => {
    onFallaRef.current?.(errorMapa)
  }, [errorMapa])

  // Dibujar polígonos de zonas activas (sin mover el mapa: sin punto abre centrado en Guadalajara)
  useEffect(() => {
    const map = mapRef.current
    if (!listo || !map) return
    aplicarPoligonosZonas(map, zonas)
  }, [listo, zonas])

  // Sincronizar pin cuando el usuario escribe latitud/longitud manualmente
  useEffect(() => {
    const map = mapRef.current
    const mapboxgl = mapboxRef.current
    if (!listo || !map || !mapboxgl) return
    if (latitud === null || longitud === null || !Number.isFinite(latitud) || !Number.isFinite(longitud)) return
    if (latitud < -90 || latitud > 90 || longitud < -180 || longitud > 180) return

    if (!markerRef.current) {
      const m = new mapboxgl.Marker({ color: "#d69f0d", draggable: true }).setLngLat([longitud, latitud]).addTo(map)
      m.on("dragend", () => {
        const pos = m.getLngLat()
        onChangeRef.current({
          latitud: Math.round(pos.lat * 1e6) / 1e6,
          longitud: Math.round(pos.lng * 1e6) / 1e6,
        })
      })
      markerRef.current = m
    } else {
      markerRef.current.setLngLat([longitud, latitud])
    }
    map.easeTo({ center: [longitud, latitud], duration: 300 })
  }, [listo, latitud, longitud])

  // Acercar el mapa al punto cuando llega del buscador
  useEffect(() => {
    const map = mapRef.current
    if (!listo || !map || !enfoque || latitud === null || longitud === null) return
    map.easeTo({ center: [longitud, latitud], zoom: 16, duration: 600 })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [listo, enfoque])

  if (!token) {
    return (
      <AvisoSinToken
        alturaMinima="min-h-44"
        titulo="Mapa no disponible (falta NEXT_PUBLIC_MAPBOX_TOKEN)"
        detalle="Configura el token público de Mapbox (NEXT_PUBLIC_MAPBOX_TOKEN en .env.local y en Vercel) para buscar la dirección y colocar el punto de entrega en el mapa."
      />
    )
  }

  if (errorMapa) {
    return <AvisoSinToken alturaMinima="min-h-44" titulo="Mapa no disponible" detalle={errorMapa} />
  }

  return (
    <div className="relative h-64 w-full overflow-hidden rounded-lg border border-border bg-muted/40">
      <div ref={contenedorRef} className="h-full w-full" data-mapa-selector />
      <div className="pointer-events-none absolute left-2.5 top-2.5 flex items-center gap-1.5 rounded-md border bg-background/90 px-2.5 py-1 text-[11px] font-medium text-foreground shadow-sm backdrop-blur-sm">
        <MapPin className="size-3.5 text-primary" />
        Haz clic en el mapa o arrastra el pin dentro de una zona activa
      </div>
    </div>
  )
}
