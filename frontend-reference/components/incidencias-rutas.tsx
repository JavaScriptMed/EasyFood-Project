'use client'

import { useState } from 'react'
import { toast } from 'sonner'
import { Camera, ChevronRight, ImageOff, UserRoundCheck, X } from 'lucide-react'
import { BloqueEstado, FilasEstado } from '@/components/estados'
import { Pill } from '@/components/panel'
import { Button } from '@/components/ui/button'
import { listarCandidatosReasignacion, listarIncidencias, reasignarIncidencia, resolverIncidencia } from '@/lib/api'
import { etiquetasEstadoIncidencia, etiquetasTipoIncidencia, etiquetasVehiculoCorto, tiposIncidenciaRuta } from '@/lib/etiquetas'
import type { EstadoIncidencia, IncidenciaDetalle } from '@/lib/types'
import { useAsync } from '@/lib/use-async'

const filtros: { valor: EstadoIncidencia | 'todas'; etiqueta: string }[] = [{ valor: 'todas', etiqueta: 'Todas' }, { valor: 'abierta', etiqueta: 'Abiertas' }, { valor: 'reasignada', etiqueta: 'Reasignadas' }, { valor: 'resuelta', etiqueta: 'Resueltas' }]

export function IncidenciasRutas({ fecha, onCambio }: { fecha: string; onCambio?: () => void }) {
  const incidencias = useAsync(() => listarIncidencias({ fecha, tipos: tiposIncidenciaRuta }), [fecha])
  const [filtro, setFiltro] = useState<EstadoIncidencia | 'todas'>('todas')
  const [seleccionId, setSeleccionId] = useState<string | null>(null)
  const lista = incidencias.data ?? []
  const visibles = lista.filter((i) => filtro === 'todas' || i.estado === filtro)
  const seleccionada = lista.find((i) => i.id === seleccionId) ?? null
  const cuenta = (estado: EstadoIncidencia) => lista.filter((i) => i.estado === estado).length

  const cambio = () => { incidencias.reload({ silent: true }); onCambio?.() }

  return (
    <section>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-2" role="tablist" aria-label="Filtrar por estado">
          {filtros.map((f) => (
            <button key={f.valor} role="tab" aria-selected={filtro === f.valor} onClick={() => setFiltro(f.valor)} className={`rounded-full border px-3.5 py-1.5 text-xs font-semibold transition ${filtro === f.valor ? 'border-foreground bg-foreground text-background' : 'bg-card text-muted-foreground hover:text-foreground'}`}>
              {f.etiqueta}{f.valor !== 'todas' && !incidencias.loading && <span className="ml-1.5 opacity-70">{cuenta(f.valor)}</span>}
            </button>
          ))}
        </div>
        <p className="text-xs text-muted-foreground">Reportadas por repartidores y empresas durante las rutas del día.</p>
      </div>

      <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[820px] text-left text-sm">
            <thead className="border-b bg-muted/40 text-xs uppercase text-muted-foreground">
              <tr><th className="px-5 py-3.5">Tipo</th><th className="px-5 py-3.5">Ruta</th><th className="px-5 py-3.5">Hora</th><th className="px-5 py-3.5">Estado</th><th className="px-5 py-3.5">Evidencia</th><th className="px-5 py-3.5"><span className="sr-only">Abrir</span></th></tr>
            </thead>
            <tbody>
              <FilasEstado columnas={6} cargando={incidencias.loading} error={incidencias.error} vacio={!visibles.length} mensajeVacio={lista.length ? 'No hay incidencias con ese estado.' : 'No hay incidencias en las rutas de esta fecha.'} onReintentar={incidencias.reload} />
              {visibles.map((i) => (
                <tr key={i.id} onClick={() => setSeleccionId(i.id)} className={`cursor-pointer border-b transition-colors last:border-0 hover:bg-muted/40 ${seleccionId === i.id ? 'bg-primary/5' : ''}`}>
                  <td className="px-5 py-4"><p className="font-semibold">{etiquetasTipoIncidencia[i.tipo]}</p><p className="mt-0.5 line-clamp-1 max-w-72 text-xs text-muted-foreground">{i.descripcion}</p></td>
                  <td className="px-5 py-4"><span className="font-mono text-xs font-bold">{i.ruta_id ?? '—'}</span><p className="text-xs text-muted-foreground">{i.repartidor_nombre}</p></td>
                  <td className="px-5 py-4 tabular-nums">{i.hora ?? '—'}</td>
                  <td className="px-5 py-4"><EstadoPill estado={i.estado} /></td>
                  <td className="px-5 py-4">{i.foto_url ? <img src={i.foto_url} alt={`Evidencia de ${etiquetasTipoIncidencia[i.tipo]}`} className="size-11 rounded-lg object-cover ring-1 ring-foreground/10" /> : <span className="flex size-11 items-center justify-center rounded-lg border border-dashed text-muted-foreground" title="Sin foto"><ImageOff className="size-4" aria-label="Sin foto" /></span>}</td>
                  <td className="px-5 py-4 text-right text-muted-foreground"><ChevronRight className="size-4" /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      {seleccionada && <PanelIncidencia key={seleccionada.id} incidencia={seleccionada} onCerrar={() => setSeleccionId(null)} onCambio={cambio} />}
    </section>
  )
}

const tonoIncidencia = { abierta: 'danger', reasignada: 'info', resuelta: 'success' } as const
const EstadoPill = ({ estado }: { estado: EstadoIncidencia }) => <Pill tono={tonoIncidencia[estado]}>{etiquetasEstadoIncidencia[estado]}</Pill>

function PanelIncidencia({ incidencia: i, onCerrar, onCambio }: { incidencia: IncidenciaDetalle; onCerrar: () => void; onCambio: () => void }) {
  const [nota, setNota] = useState(i.nota ?? '')
  const [eligiendo, setEligiendo] = useState(false)
  const [trabajando, setTrabajando] = useState(false)
  const cerrada = i.estado === 'resuelta'

  async function ejecutar(accion: () => Promise<unknown>, exito: string) {
    setTrabajando(true)
    try {
      await accion()
      toast.success(exito)
      setEligiendo(false)
      onCambio()
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo guardar el cambio')
    } finally {
      setTrabajando(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/35" onClick={onCerrar}>
      <aside role="dialog" aria-label={`Incidencia ${i.id}`} className="flex h-full w-full max-w-lg flex-col overflow-y-auto bg-card shadow-2xl" onClick={(e) => e.stopPropagation()}>
        <header className="flex items-start justify-between gap-4 border-b p-6">
          <div><p className="font-mono text-xs text-muted-foreground">{i.id}</p><h2 className="font-display text-2xl font-bold tracking-tight">{etiquetasTipoIncidencia[i.tipo]}</h2><div className="mt-2"><EstadoPill estado={i.estado} /></div></div>
          <button onClick={onCerrar} aria-label="Cerrar"><X /></button>
        </header>

        <div className="flex flex-1 flex-col gap-6 p-6">
          <p className="text-sm leading-relaxed">{i.descripcion}</p>

          <dl className="grid grid-cols-3 gap-3 text-sm">
            {[['Ruta', i.ruta_id ?? '—'], ['Hora', i.hora ?? '—'], ['Zona', i.ruta_zona ?? '—']].map(([k, v]) => <div key={k} className="rounded-xl bg-muted/60 p-3"><dt className="text-xs text-muted-foreground">{k}</dt><dd className="mt-1 font-semibold">{v}</dd></div>)}
            <div className="col-span-3 rounded-xl bg-muted/60 p-3"><dt className="text-xs text-muted-foreground">Repartidor de la ruta</dt><dd className="mt-1 font-semibold">{i.repartidor_nombre ?? '—'}</dd></div>
          </dl>

          <div>
            <p className="mb-2 flex items-center gap-2 text-sm font-semibold"><Camera className="size-4" /> Evidencia</p>
            {i.foto_url ? <img src={i.foto_url} alt={`Evidencia de ${etiquetasTipoIncidencia[i.tipo]}`} className="h-52 w-full rounded-xl object-cover ring-1 ring-foreground/10" /> : <div className="grid h-24 place-items-center rounded-xl border border-dashed text-sm text-muted-foreground">Sin foto de evidencia</div>}
          </div>

          <label className="text-sm font-semibold">Nota
            <textarea value={nota} onChange={(e) => setNota(e.target.value)} disabled={cerrada} placeholder="Qué pasó y qué se decidió" className="mt-2 min-h-24 w-full rounded-xl border bg-background p-3 text-sm font-normal outline-none focus:border-primary disabled:opacity-70" />
          </label>

          {eligiendo && <Candidatos incidenciaId={i.id} trabajando={trabajando} onElegir={(repartidorId, nombre) => ejecutar(() => reasignarIncidencia({ id: i.id, repartidor_id: repartidorId, nota }), `Ruta reasignada a ${nombre}`)} />}
        </div>

        {!cerrada && (
          <footer className="flex flex-wrap gap-2 border-t bg-card p-6">
            {i.ruta_id && <Button className="h-10 flex-1 px-4" variant={eligiendo ? 'outline' : 'default'} onClick={() => setEligiendo(!eligiendo)} disabled={trabajando}><UserRoundCheck /> Reasignar a otro repartidor</Button>}
            <Button className="h-10 px-4" variant="outline" onClick={() => ejecutar(() => resolverIncidencia(i.id, nota), 'Incidencia resuelta')} disabled={trabajando}>Marcar como resuelta</Button>
          </footer>
        )}
      </aside>
    </div>
  )
}

function Candidatos({ incidenciaId, trabajando, onElegir }: { incidenciaId: string; trabajando: boolean; onElegir: (id: string, nombre: string) => void }) {
  const candidatos = useAsync(() => listarCandidatosReasignacion(incidenciaId), [incidenciaId])
  return (
    <div>
      <p className="mb-2 text-sm font-semibold">Repartidores con capacidad libre</p>
      <div className="flex flex-col gap-2">
        <BloqueEstado cargando={candidatos.loading} error={candidatos.error} vacio={!candidatos.data?.length} mensajeVacio="Ningún repartidor tiene capacidad libre ese día." onReintentar={candidatos.reload} cantidad={3} />
        {candidatos.data?.map((c) => (
          <div key={c.repartidor.id} className="flex items-center justify-between gap-3 rounded-xl border p-3">
            <div className="min-w-0"><p className="truncate text-sm font-semibold">{c.repartidor.nombre}</p><p className="text-xs text-muted-foreground">{etiquetasVehiculoCorto[c.repartidor.tipo_vehiculo]} · {c.capacidad_libre} entregas libres</p></div>
            {c.alcanza ? <Button size="sm" disabled={trabajando} onClick={() => onElegir(c.repartidor.id, c.repartidor.nombre)}>Asignar</Button> : <span className="rounded-full bg-muted px-2.5 py-1 text-[11px] font-semibold text-muted-foreground">No alcanza para la ruta</span>}
          </div>
        ))}
      </div>
    </div>
  )
}
