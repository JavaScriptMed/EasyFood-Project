"use client"

import { useRef, useState } from "react"
import { toast } from "sonner"
import { FileUp, MapPin, Pencil, Plus, Power, Upload } from "lucide-react"
import { AdminShell } from "@/components/admin-shell"
import { FilasEstado } from "@/components/estados"
import { PageTitle, Pill } from "@/components/panel"
import { anillosDe, leerGeoJson, VistaPreviaPoligono } from "@/components/poligono"
import { Button } from "@/components/ui/button"
import { CampoTexto, VentanaConfirmar, VentanaFormulario } from "@/components/ventanas"
import { cambiarEstadoZona, crearZona, importarZonas, listarZonas, reemplazarPoligonoZona, renombrarZona } from "@/lib/api"
import type { ZonaDetalle } from "@/lib/types"
import { useAsync } from "@/lib/use-async"

/** Zonas de entrega: el polígono de cada una se carga desde un archivo GeoJSON. El nombre se puede corregir: el servidor lo actualiza en empresas, comedores, repartidores y rutas. */
export default function ZonasPage() {
  const zonas = useAsync(listarZonas)
  const [creando, setCreando] = useState(false)
  const [cambiando, setCambiando] = useState<ZonaDetalle | null>(null)
  const [renombrando, setRenombrando] = useState<ZonaDetalle | null>(null)
  const [desactivando, setDesactivando] = useState<ZonaDetalle | null>(null)
  const [trabajando, setTrabajando] = useState(false)
  const [importando, setImportando] = useState(false)
  const entradaImportar = useRef<HTMLInputElement>(null)
  const lista = zonas.data ?? []

  async function importar(archivo: File | undefined) {
    if (entradaImportar.current) entradaImportar.current.value = ""
    if (!archivo) return
    setImportando(true)
    try {
      const coleccion = await leerGeoJson(archivo)
      const r = await importarZonas(coleccion)
      toast.success(`${r.creadas} zona(s) creada(s) y ${r.actualizadas} actualizada(s)`, { description: r.zonas.map((z) => z.nombre).join(", ") })
      zonas.reload({ silent: true })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo importar el archivo")
    } finally {
      setImportando(false)
    }
  }

  async function desactivar(zona: ZonaDetalle) {
    setTrabajando(true)
    try {
      await cambiarEstadoZona(zona.id, false)
      toast.success(`${zona.nombre} quedó inactiva`, { description: "Las direcciones que cubría quedan sin cobertura hasta que la reactives." })
      setDesactivando(null)
      zonas.reload({ silent: true })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo desactivar la zona")
    } finally {
      setTrabajando(false)
    }
  }

  async function reactivar(zona: ZonaDetalle) {
    try {
      await cambiarEstadoZona(zona.id, true)
      toast.success(`${zona.nombre} quedó activa`)
      zonas.reload({ silent: true })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo reactivar la zona")
    }
  }

  return <AdminShell active="Zonas"><div className="min-h-screen bg-background p-5 md:p-8"><div className="mx-auto max-w-6xl">
    <div className="mb-7 flex flex-wrap items-end justify-between gap-4 border-b pb-6">
      <div><PageTitle eyebrow="Operación" title="Zonas de entrega" description="Dónde se entrega. Una dirección cae en una zona si su punto está dentro del polígono; sin zona, no tiene cobertura. Si dos zonas se traslapan, gana la más pequeña." /></div>
      <div className="flex flex-wrap gap-2">
        <input ref={entradaImportar} type="file" accept=".geojson,.json,application/geo+json,application/json" className="sr-only" tabIndex={-1} aria-label="Elegir el archivo con varias zonas" onChange={(e) => importar(e.target.files?.[0])} />
        <Button variant="outline" disabled={importando} onClick={() => entradaImportar.current?.click()}><FileUp /> {importando ? "Importando…" : "Importar varias"}</Button>
        <Button onClick={() => setCreando(true)}><Plus /> Nueva zona</Button>
      </div>
    </div>
    <p className="mb-4 text-sm text-muted-foreground">«Importar varias» recibe un solo archivo GeoJSON (FeatureCollection) con un polígono por zona y el nombre de cada una en <code>properties.nombre</code>. Si una trae error, no se guarda ninguna. Las coordenadas van como [longitud, latitud].</p>
    <div className="overflow-hidden rounded-xl border bg-card"><div className="overflow-x-auto">
      <table className="w-full min-w-[820px] text-left text-sm">
        <thead className="border-b bg-muted/40 text-xs uppercase text-muted-foreground"><tr>{["Zona", "Área", "Direcciones", "Empresas", "Comedores", "Repartidores", "Estado", ""].map((x) => <th key={x} className="px-5 py-3">{x}</th>)}</tr></thead>
        <tbody>
          <FilasEstado columnas={8} cargando={zonas.loading} error={zonas.error} vacio={!lista.length} mensajeVacio="Todavía no hay zonas: sin ellas nadie tiene cobertura. Crea la primera con «Nueva zona» o importa un archivo." onReintentar={zonas.reload} />
          {lista.map((z) => <tr key={z.id} className={`border-b last:border-0 ${z.activo ? "" : "text-muted-foreground"}`}>
            <td className="px-5 py-3.5 font-semibold"><span className="inline-flex items-center gap-2"><MapPin className="size-4 text-primary" aria-hidden="true" />{z.nombre}</span></td>
            <td className="px-5 py-3.5 tabular-nums">{z.area_km2.toLocaleString("es-MX")} km²</td>
            <td className="px-5 py-3.5 tabular-nums">{z.direcciones}</td>
            <td className="px-5 py-3.5 tabular-nums">{z.empresas}</td>
            <td className="px-5 py-3.5 tabular-nums">{z.comedores}</td>
            <td className="px-5 py-3.5 tabular-nums">{z.repartidores}</td>
            <td className="px-5 py-3.5"><Pill tono={z.activo ? "success" : "neutral"}>{z.activo ? "Activa" : "Inactiva"}</Pill></td>
            <td className="px-5 py-3.5"><div className="flex justify-end gap-1">
              <Button variant="ghost" size="sm" onClick={() => setRenombrando(z)} aria-label={`Cambiar el nombre de ${z.nombre}`}><Pencil /> Cambiar nombre</Button>
              <Button variant="ghost" size="sm" onClick={() => setCambiando(z)} aria-label={`Cambiar el polígono de ${z.nombre}`}><Upload /> Cambiar polígono</Button>
              {z.activo
                ? <Button variant="ghost" size="sm" className="text-destructive hover:bg-destructive/10" onClick={() => setDesactivando(z)} aria-label={`Desactivar ${z.nombre}`}><Power /> Desactivar</Button>
                : <Button variant="ghost" size="sm" onClick={() => reactivar(z)} aria-label={`Reactivar ${z.nombre}`}><Power /> Reactivar</Button>}
            </div></td>
          </tr>)}
        </tbody>
      </table>
    </div></div>
  </div></div>
    {creando && <VentanaDePoligono titulo="Nueva zona" descripcion="Elige el archivo GeoJSON con el polígono de la zona." textoEnviar="Crear zona" pideNombre onCerrar={() => setCreando(false)}
      onEnviar={async (nombre, geojson) => { await crearZona(nombre, geojson); toast.success(`Zona «${nombre}» creada`); setCreando(false); zonas.reload({ silent: true }) }} />}
    {renombrando && <VentanaDeNombre zona={renombrando} onCerrar={() => setRenombrando(null)}
      onGuardado={(nombre) => { toast.success(`La zona ahora se llama «${nombre}»`); setRenombrando(null); zonas.reload({ silent: true }) }} />}
    {cambiando && <VentanaDePoligono titulo={`Cambiar el polígono de ${cambiando.nombre}`} descripcion="El nombre no cambia. Las direcciones se recalculan con el polígono nuevo." textoEnviar="Reemplazar polígono" onCerrar={() => setCambiando(null)}
      onEnviar={async (_nombre, geojson) => { await reemplazarPoligonoZona(cambiando.id, geojson); toast.success(`Polígono de «${cambiando.nombre}» actualizado`); setCambiando(null); zonas.reload({ silent: true }) }} />}
    {desactivando && <VentanaConfirmar titulo={`¿Desactivar ${desactivando.nombre}?`} descripcion={<>Las <strong className="text-foreground">{desactivando.direcciones}</strong> dirección(es) que cubre quedarán sin cobertura hasta que la reactives. No se puede si hay empresas, comedores o repartidores activos en ella.</>} textoConfirmar="Desactivar" trabajando={trabajando} onCerrar={() => setDesactivando(null)} onConfirmar={() => desactivar(desactivando)} />}
  </AdminShell>
}

/** Corregir el nombre de una zona (por ejemplo, una falta de ortografía). Se actualiza también donde ya está escrito. */
function VentanaDeNombre({ zona, onCerrar, onGuardado }: { zona: ZonaDetalle; onCerrar: () => void; onGuardado: (nombre: string) => void }) {
  const [nombre, setNombre] = useState(zona.nombre)
  const [guardando, setGuardando] = useState(false)
  const usos = zona.empresas + zona.comedores + zona.repartidores
  async function guardar() {
    setGuardando(true)
    try {
      const actualizada = await renombrarZona(zona.id, nombre.trim())
      onGuardado(actualizada.nombre)
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo cambiar el nombre")
    } finally {
      setGuardando(false)
    }
  }
  return <VentanaFormulario titulo={`Cambiar el nombre de ${zona.nombre}`} descripcion={usos ? `Se actualiza también en ${zona.empresas} empresa(s), ${zona.comedores} comedor(es) y ${zona.repartidores} repartidor(es) que ya la usan.` : "Ninguna empresa, comedor ni repartidor la usa todavía."} textoEnviar="Guardar nombre" trabajando={guardando} deshabilitarEnvio={!nombre.trim() || nombre.trim() === zona.nombre} onCerrar={onCerrar} onEnviar={guardar}>
    <CampoTexto etiqueta="Nombre de la zona" required autoFocus maxLength={100} value={nombre} onChange={(e) => setNombre(e.target.value)} />
  </VentanaFormulario>
}

/** Pide el archivo GeoJSON (y el nombre si hace falta), lo dibuja para que lo revises y lo manda. */
function VentanaDePoligono({ titulo, descripcion, textoEnviar, pideNombre = false, onCerrar, onEnviar }: {
  titulo: string; descripcion: string; textoEnviar: string; pideNombre?: boolean; onCerrar: () => void; onEnviar: (nombre: string, geojson: unknown) => Promise<void>
}) {
  const [nombre, setNombre] = useState("")
  const [geojson, setGeojson] = useState<unknown>(null)
  const [archivo, setArchivo] = useState("")
  const [guardando, setGuardando] = useState(false)
  const poligono = geojson ? anillosDe(geojson) : null

  async function elegido(f: File | undefined) {
    if (!f) return
    try {
      setGeojson(await leerGeoJson(f))
      setArchivo(f.name)
    } catch (error) {
      setGeojson(null)
      setArchivo("")
      toast.error(error instanceof Error ? error.message : "No se pudo leer el archivo")
    }
  }
  async function guardar() {
    setGuardando(true)
    try {
      await onEnviar(nombre.trim(), geojson)
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "No se pudo guardar la zona")
    } finally {
      setGuardando(false)
    }
  }
  return <VentanaFormulario titulo={titulo} descripcion={descripcion} textoEnviar={textoEnviar} trabajando={guardando} deshabilitarEnvio={!geojson || (pideNombre && !nombre.trim())} onCerrar={onCerrar} onEnviar={guardar}>
    {pideNombre && <CampoTexto etiqueta="Nombre de la zona" required autoFocus value={nombre} onChange={(e) => setNombre(e.target.value)} ayuda="Es el mismo texto que se usa en empresas, comedores y repartidores. No se podrá cambiar." />}
    <label className="block cursor-pointer rounded-xl border-2 border-dashed p-6 text-center text-sm text-muted-foreground focus-within:border-primary">
      <Upload className="mx-auto mb-2" aria-hidden="true" />{archivo || "Haz clic para elegir el archivo .geojson"}
      <input type="file" accept=".geojson,.json,application/geo+json,application/json" className="sr-only" onChange={(e) => elegido(e.target.files?.[0])} />
    </label>
    {geojson !== null && (poligono
      ? <div><VistaPreviaPoligono poligono={poligono} etiqueta="Vista previa del polígono" /><p className="mt-1.5 text-center text-xs text-muted-foreground">{poligono.anillos.length} polígono(s), {poligono.puntos} puntos. Revisa la forma antes de guardar.</p></div>
      : <p role="alert" className="text-sm text-destructive">Ese archivo no trae un polígono (Polygon, MultiPolygon, Feature o FeatureCollection de uno).</p>)}
  </VentanaFormulario>
}
