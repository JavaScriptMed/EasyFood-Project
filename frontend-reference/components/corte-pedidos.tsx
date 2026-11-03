'use client'

import { useState } from 'react'
import { toast } from 'sonner'
import { CircleAlert, Info, Scissors, X } from 'lucide-react'
import { ErrorEstado, FilasEstado } from '@/components/estados'
import { Pill } from '@/components/panel'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { ejecutarCorte, listarCortes, obtenerVistaPreviaCorte } from '@/lib/api'
import { formatDate, formatFechaLarga, formatHora12, horaDe } from '@/lib/format'
import type { EstadoCorte, ReglaSinEleccion, ResultadoCorte } from '@/lib/types'
import { useAsync } from '@/lib/use-async'

const etiquetasEstado: Record<EstadoCorte, string> = { correcto: 'Correcto', con_advertencias: 'Con advertencias', fallido: 'Fallido' }
const etiquetasRegla: Record<ReglaSinEleccion, string> = { ultimo_platillo: 'se les asignará su último platillo', platillo_del_dia: 'se les asignará el platillo del día', no_entregar: 'no se les entregará' }

const tonoCorte = { correcto: 'success', con_advertencias: 'warning', fallido: 'danger' } as const
export const EstadoCortePill = ({ estado }: { estado: EstadoCorte }) => <Pill tono={tonoCorte[estado]}>{etiquetasEstado[estado]}</Pill>

/** Botón "Ejecutar corte ahora": confirmación, ejecución y pantalla de resultado. */
export function EjecutarCorte({ onEjecutado }: { onEjecutado?: () => void }) {
  const [paso, setPaso] = useState<'cerrado' | 'confirmar' | 'resultado'>('cerrado')
  const [ejecutando, setEjecutando] = useState(false)
  const [resultado, setResultado] = useState<ResultadoCorte | null>(null)
  const vista = useAsync(async () => (paso === 'confirmar' ? obtenerVistaPreviaCorte() : undefined), [paso])

  async function ejecutar() {
    setEjecutando(true)
    try {
      setResultado(await ejecutarCorte())
      setPaso('resultado')
      onEjecutado?.()
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo ejecutar el corte')
    } finally {
      setEjecutando(false)
    }
  }

  return (
    <>
      <button onClick={() => setPaso('confirmar')} className="flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground hover:bg-primary/80"><Scissors size={16} /> Ejecutar corte ahora</button>

      {paso === 'confirmar' && (
        <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" onClick={() => !ejecutando && setPaso('cerrado')}>
          <div role="dialog" aria-modal="true" aria-label="Ejecutar corte ahora" className="w-full max-w-md rounded-xl bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-start justify-between gap-3"><h2 className="font-display text-xl font-bold">¿Ejecutar el corte ahora?</h2><button onClick={() => setPaso('cerrado')} disabled={ejecutando} aria-label="Cerrar"><X /></button></div>
            {vista.loading && <div className="mt-4 flex flex-col gap-2" aria-hidden="true"><Skeleton className="h-4 w-full" /><Skeleton className="h-4 w-4/5" /><Skeleton className="h-4 w-3/5" /></div>}
            {vista.error && <ErrorEstado error={vista.error} onReintentar={vista.reload} />}
            {vista.data && (
              <div className="mt-3 flex flex-col gap-3 text-sm">
                <p>Se cerrarán las elecciones para el <strong>{formatFechaLarga(vista.data.fecha_entrega)}</strong>.</p>
                <ul className="flex flex-col gap-1 rounded-lg bg-muted/60 p-3">
                  <li>Empleados activos: <strong>{vista.data.empleados_activos}</strong></li>
                  <li>Todavía sin elegir: <strong>{vista.data.sin_elegir}</strong>{vista.data.sin_elegir > 0 && <> ({etiquetasRegla[vista.data.regla_sin_eleccion]})</>}</li>
                </ul>
                {vista.data.ya_cerrado && <p className="flex items-start gap-2 rounded-lg border border-sky-500/30 bg-sky-500/10 p-3 text-sky-700"><Info className="mt-0.5 size-4 shrink-0" /> Ese día ya está cerrado. Ejecutar el corte de nuevo no cambiará nada.</p>}
                <p className="text-xs text-muted-foreground">La hora de corte configurada es {formatHora12(vista.data.hora_corte)}; este corte se ejecuta de inmediato.</p>
              </div>
            )}
            <div className="mt-6 flex justify-end gap-2">
              <Button variant="outline" className="h-10 px-4" onClick={() => setPaso('cerrado')} disabled={ejecutando}>Cancelar</Button>
              <Button className="h-10 px-4" onClick={ejecutar} disabled={ejecutando || !vista.data}>{ejecutando ? 'Ejecutando corte…' : 'Ejecutar corte'}</Button>
            </div>
          </div>
        </div>
      )}

      {paso === 'resultado' && resultado && <PantallaResultado resultado={resultado} onCerrar={() => setPaso('cerrado')} />}
    </>
  )
}

function Seccion({ titulo, nota, children }: { titulo: string; nota?: string; children: React.ReactNode }) {
  return <section className="rounded-xl border bg-card p-5"><div className="mb-3"><h2 className="font-display text-lg font-bold">{titulo}</h2>{nota && <p className="text-sm text-muted-foreground">{nota}</p>}</div>{children}</section>
}

function PantallaResultado({ resultado: r, onCerrar }: { resultado: ResultadoCorte; onCerrar: () => void }) {
  const fallido = r.corte.estado === 'fallido'
  const cifras: [string, number][] = [['Elecciones cerradas', r.elecciones_cerradas], ['Con platillo por defecto', r.empleados_por_defecto.length], ['Órdenes de producción', r.corte.ordenes_generadas], ['Entregas consolidadas', r.corte.entregas_consolidadas], ['Rutas creadas', r.corte.rutas_creadas]]
  return (
    <div role="dialog" aria-modal="true" aria-label="Resultado del corte" className="fixed inset-0 z-[60] overflow-y-auto bg-background">
      <div className="mx-auto flex max-w-5xl flex-col gap-5 p-5 md:p-8">
        <header className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <p className="text-sm font-semibold text-primary">Corte de pedidos</p>
            <h1 className="font-display text-3xl font-bold tracking-tight">Resultado del corte</h1>
            <p className="mt-1 text-muted-foreground">Entregas del {formatFechaLarga(r.fecha_entrega)} · ejecutado el {formatDate(r.corte.ejecutado_en.slice(0, 10))} a las {horaDe(r.corte.ejecutado_en)}</p>
          </div>
          <div className="flex items-center gap-3"><EstadoCortePill estado={r.corte.estado} /><Button variant="outline" className="h-10 px-4" onClick={onCerrar}>Cerrar</Button></div>
        </header>

        {r.sin_cambios && <div role="status" className="flex items-start gap-3 rounded-xl border border-sky-500/30 bg-sky-500/10 p-4 text-sky-700"><Info className="mt-0.5 size-5 shrink-0" /><div><p className="font-semibold">Sin cambios; ya estaba cerrado.</p><p className="text-sm">Este día ya se había cerrado antes. Abajo se muestra el resultado de ese corte.</p></div></div>}
        {fallido && <div role="alert" className="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/10 p-4 text-destructive"><CircleAlert className="mt-0.5 size-5 shrink-0" /><div><p className="font-semibold">El corte falló</p><p className="text-sm">{r.corte.mensaje}</p></div></div>}

        {!fallido && <>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-5">{cifras.map(([etiqueta, valor]) => <div key={etiqueta} className="rounded-xl border bg-card p-4"><p className="text-xs text-muted-foreground">{etiqueta}</p><p className="mt-2 font-display text-3xl font-bold tabular-nums">{valor}</p></div>)}</div>

          {r.advertencias.length > 0 && <div role="status" className="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-700"><p className="mb-1 font-semibold">Advertencias</p><ul className="list-disc pl-5">{r.advertencias.map((a) => <li key={a}>{a}</li>)}</ul></div>}

          <Seccion titulo="Empleados con platillo por defecto" nota={`${r.empleados_por_defecto.length} empleados no eligieron antes del corte${r.empleados_sin_entrega ? ` · ${r.empleados_sin_entrega} quedaron sin entrega` : ''}.`}>
            <div className="max-h-72 overflow-auto rounded-lg border"><table className="w-full min-w-[560px] text-left text-sm"><thead className="sticky top-0 border-b bg-muted text-xs uppercase text-muted-foreground"><tr><th className="px-4 py-3">Empleado</th><th className="px-4 py-3">Empresa</th><th className="px-4 py-3">Platillo asignado</th></tr></thead><tbody>
              {r.empleados_por_defecto.map((e) => <tr key={e.empleado_id} className="border-b last:border-0"><td className="px-4 py-2.5 font-medium">{e.nombre}</td><td className="px-4 py-2.5 text-muted-foreground">{e.empresa_nombre}</td><td className="px-4 py-2.5">{e.platillo_nombre}</td></tr>)}
              {!r.empleados_por_defecto.length && <tr><td colSpan={3} className="px-4 py-8 text-center text-muted-foreground">Ningún empleado necesitó platillo por defecto.</td></tr>}
            </tbody></table></div>
          </Seccion>

          <Seccion titulo="Órdenes de producción por comedor">
            <div className="overflow-x-auto rounded-lg border"><table className="w-full min-w-[480px] text-left text-sm"><thead className="border-b bg-muted text-xs uppercase text-muted-foreground"><tr><th className="px-4 py-3">Comedor</th><th className="px-4 py-3 text-right">Órdenes</th><th className="px-4 py-3 text-right">Porciones</th></tr></thead><tbody>
              {r.ordenes_por_comedor.map((c) => <tr key={c.comedor_id} className="border-b last:border-0"><td className="px-4 py-2.5 font-medium">{c.comedor_nombre}</td><td className="px-4 py-2.5 text-right tabular-nums">{c.ordenes}</td><td className="px-4 py-2.5 text-right tabular-nums">{c.porciones}</td></tr>)}
              {!r.ordenes_por_comedor.length && <tr><td colSpan={3} className="px-4 py-8 text-center text-muted-foreground">No se generaron órdenes de producción.</td></tr>}
            </tbody></table></div>
          </Seccion>

          <Seccion titulo="Entregas consolidadas por empresa">
            <div className="overflow-x-auto rounded-lg border"><table className="w-full min-w-[560px] text-left text-sm"><thead className="border-b bg-muted text-xs uppercase text-muted-foreground"><tr><th className="px-4 py-3">Empresa</th><th className="px-4 py-3 text-right">Platillos</th><th className="px-4 py-3">Hora de entrega</th><th className="px-4 py-3">Ruta</th></tr></thead><tbody>
              {r.entregas_por_empresa.map((e) => <tr key={e.empresa_id} className="border-b last:border-0"><td className="px-4 py-2.5 font-medium">{e.empresa_nombre}</td><td className="px-4 py-2.5 text-right tabular-nums">{e.platillos}</td><td className="px-4 py-2.5">{e.hora_entrega} h</td><td className="px-4 py-2.5 font-mono text-xs">{e.ruta_id ?? <span className="font-sans text-amber-600">Sin ruta</span>}</td></tr>)}
              {!r.entregas_por_empresa.length && <tr><td colSpan={4} className="px-4 py-8 text-center text-muted-foreground">No hay entregas para consolidar.</td></tr>}
            </tbody></table></div>
          </Seccion>

          <Seccion titulo="Rutas creadas">
            {r.rutas.length ? <div className="grid gap-3 md:grid-cols-3">{r.rutas.map((ruta) => <div key={ruta.id} className="rounded-xl border p-4"><div className="flex items-center justify-between"><span className="font-mono text-xs font-bold">{ruta.id}</span><span className="text-xs text-muted-foreground">{ruta.entregas} entregas · {ruta.platillos} platillos</span></div><p className="mt-2 font-semibold">{ruta.repartidor_nombre}</p><p className="text-sm text-muted-foreground">{ruta.zona}</p></div>)}</div> : <p className="py-4 text-center text-sm text-muted-foreground">No se crearon rutas.</p>}
          </Seccion>
        </>}

        <Seccion titulo="Historial de cortes"><HistorialCortes version={r.corte.id} /></Seccion>
      </div>
    </div>
  )
}

/** Cortes pasados con fecha, hora y estado. `version` fuerza una recarga cuando cambia. */
export function HistorialCortes({ version = '' }: { version?: string }) {
  const cortes = useAsync(listarCortes, [version])
  return (
    <div className="overflow-x-auto rounded-lg border">
      <table className="w-full min-w-[820px] text-left text-sm">
        <thead className="border-b bg-muted text-xs uppercase text-muted-foreground"><tr><th className="px-4 py-3">Ejecutado</th><th className="px-4 py-3">Entrega del</th><th className="px-4 py-3">Estado</th><th className="px-4 py-3 text-right">Elecciones</th><th className="px-4 py-3 text-right">Por defecto</th><th className="px-4 py-3 text-right">Órdenes · Entregas · Rutas</th><th className="px-4 py-3">Origen</th></tr></thead>
        <tbody>
          <FilasEstado columnas={7} filas={4} cargando={cortes.loading} error={cortes.error} vacio={!cortes.data?.length} mensajeVacio="Todavía no se ha ejecutado ningún corte." onReintentar={cortes.reload} />
          {cortes.data?.map((c) => (
            <tr key={c.id} className="border-b last:border-0">
              <td className="px-4 py-3"><p className="font-medium">{formatDate(c.ejecutado_en.slice(0, 10))}</p><p className="text-xs text-muted-foreground">{horaDe(c.ejecutado_en)} h</p></td>
              <td className="px-4 py-3">{formatDate(c.fecha_entrega)}</td>
              <td className="px-4 py-3"><EstadoCortePill estado={c.estado} />{c.mensaje && <p className="mt-1 max-w-64 text-xs text-muted-foreground">{c.mensaje}</p>}</td>
              <td className="px-4 py-3 text-right tabular-nums">{c.elecciones_cerradas}</td>
              <td className="px-4 py-3 text-right tabular-nums">{c.empleados_por_defecto}</td>
              <td className="px-4 py-3 text-right tabular-nums">{c.ordenes_generadas} · {c.entregas_consolidadas} · {c.rutas_creadas}</td>
              <td className="px-4 py-3 text-muted-foreground">{c.ejecutado_por ? 'Manual' : 'Automático'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
