"use client"

import { useEffect, useId, useMemo, useRef, useState } from "react"
import { Loader2, MapPin, Search, X } from "lucide-react"
import { MapaSelectorPunto } from "@/components/mapa-mapbox"
import {
  direccionDelPunto,
  nuevaSesionDeBusqueda,
  obtenerTokenMapbox,
  puntoEnZona,
  recuperarLugar,
  sugerirLugares,
  type LugarSugerido,
  type ZonaConPoligono,
} from "@/lib/geocodificar-mapbox"

/**
 * Punto de entrega de una empresa. Las coordenadas solo viven en el estado (nunca se muestran ni se editan a mano):
 * lo que manda para la entrega es el pin, no el texto de la dirección.
 */
export interface DatosDePunto {
  latitud: number | null
  longitud: number | null
  calle: string
  colonia: string
  cp: string
  municipio: string
  referencias: string
}

export const PUNTO_VACIO: DatosDePunto = { latitud: null, longitud: null, calle: "", colonia: "", cp: "", municipio: "", referencias: "" }

export const MENSAJE_SIN_TOKEN = "Configura el token de Mapbox para elegir el punto de entrega"
export const MENSAJE_FUERA_DE_COBERTURA = "Mueve el pin dentro de una zona de cobertura"

/** El texto que se manda en `direccion_entrega`: «calle y número, colonia, C.P., municipio». */
export function componerDireccion(d: Pick<DatosDePunto, "calle" | "colonia" | "cp" | "municipio">): string {
  return [d.calle.trim(), d.colonia.trim(), d.cp.trim() ? `C.P. ${d.cp.trim()}` : "", d.municipio.trim()].filter(Boolean).join(", ")
}

/**
 * Reparte una dirección ya guardada en los campos. Si trae el formato de `componerDireccion` (con el C.P. en medio) se separa;
 * si no, todo el texto queda en «Calle y número» para no perder nada.
 */
export function datosDeDireccionGuardada(direccion: string): Pick<DatosDePunto, "calle" | "colonia" | "cp" | "municipio"> {
  const partes = direccion.split(",").map((p) => p.trim()).filter(Boolean)
  const i = partes.findIndex((p) => /^(c\.?\s?p\.?\s*)?\d{5}$/i.test(p))
  if (i < 0) return { calle: direccion.trim(), colonia: "", cp: "", municipio: "" }
  return { calle: partes[0] && i > 0 ? partes[0] : "", colonia: partes.slice(1, i).join(", "), cp: partes[i].replace(/\D/g, ""), municipio: partes.slice(i + 1).join(", ") }
}

/** Zona activa que cubre el punto (la misma regla del servidor: gana la más pequeña); null si cae fuera de todas. */
export function zonaDelPunto(d: Pick<DatosDePunto, "latitud" | "longitud">, zonas: ZonaConPoligono[]): string | null {
  if (d.latitud === null || d.longitud === null) return null
  return puntoEnZona(d.latitud, d.longitud, zonas)
}

export interface OpcionesDeBloqueo {
  /** Nueva empresa exige punto; al editar se puede guardar el resto de los datos sin tocar el punto. */
  exigirPunto: boolean
  /** Al editar, un punto que no cambió no se revalida (el servidor no lo recalcula). */
  puntoCambio: boolean
  mapaFalla?: string | null
}

/** Por qué no se puede continuar o guardar (null si se puede). */
export function motivoDeBloqueo(d: DatosDePunto, zonas: ZonaConPoligono[], { exigirPunto, puntoCambio, mapaFalla }: OpcionesDeBloqueo): string | null {
  if (exigirPunto && (!obtenerTokenMapbox() || mapaFalla)) return MENSAJE_SIN_TOKEN
  if (d.latitud === null || d.longitud === null) return exigirPunto ? "Busca la dirección o haz clic en el mapa para colocar el punto de entrega" : null
  if (puntoCambio && zonas.length > 0 && zonaDelPunto(d, zonas) === null) return MENSAJE_FUERA_DE_COBERTURA
  if (!componerDireccion(d)) return "Escribe la calle y número del punto de entrega"
  return null
}

type EstadoDeBusqueda = "inactivo" | "buscando" | "ok" | "sin_resultados" | "error"

const clasesCampo = "mt-1 w-full rounded-lg border bg-background p-3 text-sm font-normal"

interface Props {
  valor: DatosDePunto
  /** Recibe solo lo que cambió; quien lo usa lo mezcla con su estado. */
  onChange: (parche: Partial<DatosDePunto>) => void
  zonas: ZonaConPoligono[]
  /** Mensaje del servidor (por ejemplo 422 fuera_de_cobertura) para mostrarlo debajo del mapa. */
  errorDelServidor?: string | null
  /** «edicion»: los campos de dirección vienen de lo guardado y no se sobrescriben solos. */
  modo: "alta" | "edicion"
  /** Zona ya guardada, para mostrarla mientras el punto no cambie. */
  zonaGuardada?: string | null
  puntoCambio: boolean
  /** Se llama cada vez que cambia el motivo por el que no se puede continuar o guardar. */
  onBloqueo?: (motivo: string | null) => void
}

/** Buscador tipo Uber (negocio o dirección) + mapa con pin + dirección editable + zona detectada en solo lectura. */
export function SelectorDePunto({ valor, onChange, zonas, errorDelServidor, modo, zonaGuardada, puntoCambio, onBloqueo }: Props) {
  const idLista = useId()
  const token = obtenerTokenMapbox()
  const raiz = useRef<HTMLDivElement>(null)
  const sesion = useRef(nuevaSesionDeBusqueda())
  const omitirBusqueda = useRef(false)
  const peticionInversa = useRef(0)
  // Campos de dirección que la persona escribió a mano: al mover el pin no se sobrescriben. Al editar, lo guardado cuenta como escrito.
  const guardada = modo === "edicion" && componerDireccion(valor) !== ""
  const tocados = useRef({ calle: guardada, colonia: guardada, cp: guardada })
  const [consulta, setConsulta] = useState("")
  const [sugerencias, setSugerencias] = useState<LugarSugerido[]>([])
  const [estado, setEstado] = useState<EstadoDeBusqueda>("inactivo")
  const [abierto, setAbierto] = useState(false)
  const [activa, setActiva] = useState(-1)
  const [eligiendo, setEligiendo] = useState(false)
  const [avisoDeLugar, setAvisoDeLugar] = useState<string | null>(null)
  const [enfoque, setEnfoque] = useState(0)
  const [mapaFalla, setMapaFalla] = useState<string | null>(null)

  const onChangeRef = useRef(onChange)
  onChangeRef.current = onChange

  const bloqueo = useMemo(
    () => motivoDeBloqueo(valor, zonas, { exigirPunto: modo === "alta", puntoCambio, mapaFalla }),
    [valor, zonas, modo, puntoCambio, mapaFalla],
  )
  useEffect(() => {
    onBloqueo?.(bloqueo)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [bloqueo])

  // Sugerencias mientras se escribe, con un retraso de ~400 ms entre teclas.
  useEffect(() => {
    if (omitirBusqueda.current) {
      omitirBusqueda.current = false
      return
    }
    const q = consulta.trim()
    if (q.length < 3 || !token) {
      setSugerencias([])
      setEstado("inactivo")
      return
    }
    setEstado("buscando")
    const control = new AbortController()
    const espera = setTimeout(async () => {
      const r = await sugerirLugares(q, sesion.current, control.signal)
      if (control.signal.aborted || r.estado === "cancelado") return
      if (r.estado !== "ok") {
        setSugerencias([])
        setEstado("error")
        return
      }
      setSugerencias(r.datos)
      setActiva(-1)
      setEstado(r.datos.length ? "ok" : "sin_resultados")
    }, 400)
    return () => {
      clearTimeout(espera)
      control.abort()
    }
  }, [consulta, token])

  // Cerrar la lista al hacer clic fuera del buscador
  useEffect(() => {
    const fuera = (e: MouseEvent) => {
      if (raiz.current && !raiz.current.contains(e.target as Node)) setAbierto(false)
    }
    document.addEventListener("mousedown", fuera)
    return () => document.removeEventListener("mousedown", fuera)
  }, [])

  async function elegir(lugar: LugarSugerido) {
    setAbierto(false)
    setAvisoDeLugar(null)
    setEligiendo(true)
    if (lugar.nombre !== consulta) {
      omitirBusqueda.current = true
      setConsulta(lugar.nombre)
    }
    const r = await recuperarLugar(lugar.id, sesion.current)
    sesion.current = nuevaSesionDeBusqueda() // la sesión de búsqueda termina al elegir
    setEligiendo(false)
    if (r.estado !== "ok" || !r.datos) {
      setAvisoDeLugar("No pudimos obtener ese lugar. Prueba con otro o haz clic en el mapa para colocar el punto.")
      return
    }
    // Elegir una sugerencia es una decisión explícita: llena de nuevo los campos de dirección.
    tocados.current = { calle: false, colonia: false, cp: false }
    onChangeRef.current({ latitud: r.datos.latitud, longitud: r.datos.longitud, calle: r.datos.calle, colonia: r.datos.colonia, cp: r.datos.cp, municipio: r.datos.municipio })
    setEnfoque((n) => n + 1)
  }

  function alMoverElPunto({ latitud, longitud }: { latitud: number; longitud: number }) {
    setAvisoDeLugar(null)
    onChangeRef.current({ latitud, longitud })
    const numero = ++peticionInversa.current
    void direccionDelPunto(latitud, longitud).then((r) => {
      if (numero !== peticionInversa.current || r.estado !== "ok" || !r.datos) return
      const parche: Partial<DatosDePunto> = { municipio: r.datos.municipio }
      if (!tocados.current.calle) parche.calle = r.datos.calle
      if (!tocados.current.colonia) parche.colonia = r.datos.colonia
      if (!tocados.current.cp) parche.cp = r.datos.cp
      onChangeRef.current(parche)
    })
  }

  function alTeclear(e: React.KeyboardEvent<HTMLInputElement>) {
    if (e.key === "Escape") return setAbierto(false)
    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      if (!sugerencias.length) return
      e.preventDefault()
      setAbierto(true)
      setActiva((i) => (e.key === "ArrowDown" ? (i + 1) % sugerencias.length : (i <= 0 ? sugerencias.length : i) - 1))
    } else if (e.key === "Enter") {
      // Enter no debe enviar el formulario de la ventana
      e.preventDefault()
      const elegida = sugerencias[activa >= 0 ? activa : 0]
      if (abierto && elegida) void elegir(elegida)
    }
  }

  const campoDeDireccion = (clave: "calle" | "colonia" | "cp") => ({
    value: valor[clave],
    onChange: (e: React.ChangeEvent<HTMLInputElement>) => {
      tocados.current[clave] = true
      onChange({ [clave]: e.target.value })
    },
  })

  const hayPunto = valor.latitud !== null && valor.longitud !== null
  const zonaEncontrada = zonaDelPunto(valor, zonas)
  const sinZonas = zonas.length === 0
  const mostrarGuardada = Boolean(zonaGuardada) && !puntoCambio
  const fueraDeCobertura = hayPunto && !mostrarGuardada && !sinZonas && zonaEncontrada === null
  const textoZona = mostrarGuardada
    ? (zonaGuardada as string)
    : !hayPunto
      ? ""
      : sinZonas
        ? "Se calculará en el servidor al guardar"
        : zonaEncontrada ?? "Fuera de cobertura"
  const direccionFinal = componerDireccion(valor)
  const listaVisible = abierto && consulta.trim().length >= 3 && estado !== "inactivo"

  return (
    <div className="space-y-3 sm:col-span-2" ref={raiz}>
      <div className="relative">
        <label className="text-sm font-medium" htmlFor={`${idLista}-buscar`}>
          Punto de entrega
        </label>
        <div className="relative mt-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
          <input
            id={`${idLista}-buscar`}
            type="text"
            role="combobox"
            aria-expanded={listaVisible}
            aria-controls={idLista}
            aria-autocomplete="list"
            aria-activedescendant={activa >= 0 ? `${idLista}-${activa}` : undefined}
            autoComplete="off"
            disabled={!token}
            value={consulta}
            onChange={(e) => {
              setConsulta(e.target.value)
              setAbierto(true)
              setAvisoDeLugar(null)
            }}
            onFocus={() => setAbierto(true)}
            onKeyDown={alTeclear}
            placeholder="Busca la empresa o su dirección (calle y número, colonia, municipio)"
            className="w-full rounded-lg border bg-background py-3 pl-9 pr-10 text-sm disabled:cursor-not-allowed disabled:opacity-60"
          />
          <span className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground">
            {estado === "buscando" || eligiendo ? (
              <Loader2 className="size-4 animate-spin" aria-label="Buscando" />
            ) : consulta ? (
              <button
                type="button"
                aria-label="Borrar la búsqueda"
                onClick={() => {
                  setConsulta("")
                  setSugerencias([])
                  setEstado("inactivo")
                }}
                className="rounded p-0.5 hover:text-foreground"
              >
                <X className="size-4" />
              </button>
            ) : null}
          </span>
        </div>

        {listaVisible && (
          <ul id={idLista} role="listbox" aria-label="Sugerencias de lugares" className="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-lg border bg-card p-1 shadow-lg">
            {estado === "buscando" && !sugerencias.length && <li className="px-3 py-2.5 text-sm text-muted-foreground">Buscando…</li>}
            {estado === "sin_resultados" && <li role="status" className="px-3 py-2.5 text-sm text-muted-foreground">No encontramos el lugar: haz clic en el mapa para colocar el punto</li>}
            {estado === "error" && <li role="status" className="px-3 py-2.5 text-sm text-amber-700 dark:text-amber-400">No se pudo buscar en Mapbox en este momento. Haz clic en el mapa para colocar el punto.</li>}
            {sugerencias.map((s, i) => (
              <li key={s.id} id={`${idLista}-${i}`} role="option" aria-selected={i === activa}>
                <button
                  type="button"
                  onMouseDown={(e) => e.preventDefault()}
                  onClick={() => void elegir(s)}
                  onMouseEnter={() => setActiva(i)}
                  className={`flex w-full items-start gap-2.5 rounded-md px-3 py-2 text-left ${i === activa ? "bg-muted" : "hover:bg-muted/60"}`}
                >
                  <MapPin className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden />
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-semibold">{s.nombre}</span>
                    {s.detalle && <span className="block truncate text-xs text-muted-foreground">{s.detalle}</span>}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

      {avisoDeLugar && <p role="alert" className="rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs font-medium text-amber-800 dark:text-amber-300">{avisoDeLugar}</p>}

      <MapaSelectorPunto zonas={zonas} latitud={valor.latitud} longitud={valor.longitud} onChange={alMoverElPunto} enfoque={enfoque} onFalla={setMapaFalla} />

      {errorDelServidor && <p role="alert" className="rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs font-semibold text-destructive">{errorDelServidor}</p>}

      <label className="block text-sm font-medium">
        Zona de entrega
        <input
          readOnly
          value={textoZona}
          aria-label="Zona de entrega"
          placeholder="Aparece al colocar el punto"
          aria-invalid={fueraDeCobertura || undefined}
          className={`mt-1 w-full rounded-lg border bg-muted p-3 text-sm font-normal ${fueraDeCobertura ? "border-destructive/50 font-semibold text-destructive" : "text-muted-foreground"}`}
        />
        {fueraDeCobertura ? (
          <span role="alert" className="mt-1 block text-xs font-semibold text-destructive">{MENSAJE_FUERA_DE_COBERTURA}</span>
        ) : (
          <span className="mt-1 block text-xs font-normal text-muted-foreground">
            Solo lectura: se detecta con el punto del mapa; el servidor la confirma al guardar.
            {modo === "edicion" && " Editar un polígono de zona nunca mueve a una empresa."}
          </span>
        )}
      </label>

      <div className="grid gap-4 sm:grid-cols-2">
        <label className="text-sm font-medium sm:col-span-2">
          Calle y número
          <input className={clasesCampo} placeholder="Av. Vallarta 1234" autoComplete="off" {...campoDeDireccion("calle")} />
        </label>
        <label className="text-sm font-medium">
          Colonia
          <input className={clasesCampo} placeholder="Colonia" autoComplete="off" {...campoDeDireccion("colonia")} />
        </label>
        <label className="text-sm font-medium">
          Código postal
          <input className={clasesCampo} placeholder="44100" inputMode="numeric" maxLength={5} autoComplete="off" {...campoDeDireccion("cp")} />
        </label>
        <label className="text-sm font-medium sm:col-span-2">
          Referencias
          <input
            className={clasesCampo}
            placeholder="Piso, acceso, entre qué calles (entre X y Y)"
            value={valor.referencias}
            onChange={(e) => onChange({ referencias: e.target.value })}
          />
          <span className="mt-1 block text-xs font-normal text-muted-foreground">Texto libre para quien entrega; no se usa para buscar el punto.</span>
        </label>
      </div>
      {direccionFinal && <p className="text-xs text-muted-foreground">Dirección que se guarda: <span className="font-medium text-foreground">{direccionFinal}</span></p>}

      {bloqueo && bloqueo !== MENSAJE_FUERA_DE_COBERTURA && <p role="status" className="text-xs font-medium text-amber-700 dark:text-amber-400">{bloqueo}</p>}
      {modo === "edicion" && !token && <p role="status" className="text-xs font-medium text-amber-700 dark:text-amber-400">Sin el token de Mapbox no se puede cambiar el punto de entrega; el resto de los datos sí se guardan.</p>}
    </div>
  )
}
