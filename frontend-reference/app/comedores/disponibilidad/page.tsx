'use client'

import { PageTitle } from '@/components/panel'
import { useState, type DragEvent } from 'react'
import { toast } from 'sonner'
import { AlertTriangle, ChevronLeft, ChevronRight, GripVertical, Lock, Save } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { BloqueEstado } from '@/components/estados'
import { guardarDisponibilidad, listarDisponibilidad, listarPlatillos } from '@/lib/api'
import { COMEDOR_PORTAL_ID, FECHA_OPERACION, HORA_CORTE, USAR_MOCK } from '@/lib/config'
import { etiquetaDiaCorta, formatRangoFechas, sumarDias } from '@/lib/format'
import type { DisponibilidadConPlatillo } from '@/lib/types'
import { useAsync } from '@/lib/use-async'

/** Porciones con las que entra un platillo que se suelta en un día donde todavía no estaba. */
const PORCIONES_POR_DEFECTO = 50
const TIPO_ARRASTRE = 'application/x-easylunch-platillo'

const clave = (platilloId: string, fecha: string) => `${platilloId}:${fecha}`
const partesDeClave = (k: string) => ({ platillo_id: k.slice(0, k.lastIndexOf(':')), fecha: k.slice(k.lastIndexOf(':') + 1) })

/** El lunes de la semana de una fecha (AAAA-MM-DD). */
const lunesDe = (fecha: string) => sumarDias(fecha, -((new Date(`${fecha}T00:00:00Z`).getUTCDay() + 6) % 7))

/**
 * ¿Todavía se pueden cambiar las porciones de ese día? El corte de un día cierra a la hora de corte del día anterior (el servidor
 * rechaza lo que ya cerró). En la maqueta no hay corte: siempre se puede.
 */
function diaEditable(fecha: string): boolean {
  if (USAR_MOCK) return true
  const [anio, mes, dia] = fecha.split('-').map(Number)
  return new Date() < new Date(anio, mes - 1, dia - 1, HORA_CORTE, 0, 0)
}

/** La maqueta guarda su semana de ejemplo en octubre; con el servidor se abre la semana del día de operación. */
const SEMANA_INICIAL = USAR_MOCK ? '2026-10-19' : lunesDe(FECHA_OPERACION)

export default function Disponibilidad() {
  const [inicioSemana, setInicioSemana] = useState(SEMANA_INICIAL)
  const dias = Array.from({ length: 6 }, (_, i) => sumarDias(inicioSemana, i))
  const [saved, setSaved] = useState(false)
  const [saving, setSaving] = useState(false)
  /** Porciones editadas o nuevas, por platillo:fecha (lo que se manda al guardar). */
  const [maximos, setMaximos] = useState<Record<string, number>>({})
  /** Platillos que se movieron a otro día: dejan de verse en su día de origen y se guardan con 0 porciones. */
  const [quitados, setQuitados] = useState<Set<string>>(new Set())
  const [diaSobre, setDiaSobre] = useState<string | null>(null)

  const semana = useAsync(async () => {
    const [filas, platillos] = await Promise.all([
      listarDisponibilidad(COMEDOR_PORTAL_ID, dias[0], dias[dias.length - 1]),
      listarPlatillos({ comedor_id: COMEDOR_PORTAL_ID }),
    ])
    return { filas, platillos: platillos.filter((p) => p.estado_revision === 'publicado' || p.estado_revision === 'en_revision') }
  }, [inicioSemana])

  const filas = semana.data?.filas ?? []
  const platillos = semana.data?.platillos ?? []
  const persistida = (k: string) => filas.find((f) => clave(f.platillo_id, f.fecha) === k)

  /** Lo que se ve en un día: lo guardado (menos lo movido y lo que ya está en 0) más lo que se acaba de soltar ahí. */
  function tarjetasDe(dia: string) {
    const guardadas = filas
      .filter((f) => f.fecha === dia)
      .filter((f) => !quitados.has(clave(f.platillo_id, f.fecha)))
      .filter((f) => f.porciones_maximas > 0 || f.porciones_tomadas > 0 || clave(f.platillo_id, f.fecha) in maximos)
      .map((f) => ({ platillo_id: f.platillo_id, nombre: f.platillo_nombre, tomadas: f.porciones_tomadas, porciones: maximos[clave(f.platillo_id, f.fecha)] ?? f.porciones_maximas }))
    const nuevas = Object.entries(maximos)
      .map(([k, porciones]) => ({ k, porciones, ...partesDeClave(k) }))
      .filter((n) => n.fecha === dia && !persistida(n.k) && !quitados.has(n.k))
      .map((n) => ({ platillo_id: n.platillo_id, nombre: platillos.find((p) => p.id === n.platillo_id)?.nombre ?? 'Platillo', tomadas: 0, porciones: n.porciones }))
    return [...guardadas, ...nuevas]
  }

  /** Pone un platillo en otro día (viene de la lista de platillos si `origen` es null, o de otro día). */
  function mover(platilloId: string, origen: string | null, destino: string) {
    if (origen === destino) return
    if (!diaEditable(destino)) {
      toast.error('Ese día ya cerró su corte: ya no se pueden cambiar sus porciones.')
      return
    }
    if (tarjetasDe(destino).some((t) => t.platillo_id === platilloId)) {
      toast.info('Ese platillo ya está ese día.')
      return
    }
    let porciones = PORCIONES_POR_DEFECTO
    if (origen) {
      const tarjeta = tarjetasDe(origen).find((t) => t.platillo_id === platilloId)
      if (!tarjeta) return
      if (!diaEditable(origen)) {
        toast.error('El día de origen ya cerró su corte: ese platillo ya no se puede mover.')
        return
      }
      if (tarjeta.tomadas > 0) {
        toast.error(`Ya hay ${tarjeta.tomadas} pedido(s) de este platillo ese día: no se puede mover.`)
        return
      }
      porciones = tarjeta.porciones
    }
    const kDestino = clave(platilloId, destino)
    const kOrigen = origen ? clave(platilloId, origen) : null
    setMaximos((m) => {
      const siguiente = { ...m, [kDestino]: porciones }
      if (kOrigen) {
        if (persistida(kOrigen)) siguiente[kOrigen] = 0 // el servidor no borra filas: «0 porciones» es «no se ofrece ese día»
        else delete siguiente[kOrigen] // era una fila nueva sin guardar: simplemente deja de existir
      }
      return siguiente
    })
    setQuitados((q) => {
      const siguiente = new Set(q)
      siguiente.delete(kDestino)
      if (kOrigen && persistida(kOrigen)) siguiente.add(kOrigen)
      return siguiente
    })
    setSaved(false)
  }

  function alSoltar(destino: string, e: DragEvent) {
    e.preventDefault()
    setDiaSobre(null)
    try {
      const { platillo_id, origen } = JSON.parse(e.dataTransfer.getData(TIPO_ARRASTRE)) as { platillo_id: string; origen: string | null }
      mover(platillo_id, origen, destino)
    } catch {
      // no era un platillo de esta pantalla
    }
  }

  const empezarArrastre = (platilloId: string, origen: string | null) => (e: DragEvent) => {
    e.dataTransfer.setData(TIPO_ARRASTRE, JSON.stringify({ platillo_id: platilloId, origen }))
    e.dataTransfer.effectAllowed = 'move'
  }

  async function guardar() {
    setSaving(true)
    try {
      const cambios = Object.entries(maximos)
        .filter(([k, valor]) => persistida(k)?.porciones_maximas !== valor)
        .map(([k, valor]) => ({ ...partesDeClave(k), porciones_maximas: valor }))
      if (!cambios.length) {
        toast.info('No hay cambios por guardar.')
        return
      }
      await guardarDisponibilidad(cambios)
      setMaximos({})
      setQuitados(new Set())
      setSaved(true)
      semana.reload({ silent: true })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo guardar la disponibilidad')
    } finally {
      setSaving(false)
    }
  }

  const hayCambios = Object.keys(maximos).length > 0

  return (
    <div>
      <div className="mb-8 flex flex-wrap items-end justify-between gap-4 border-b pb-6">
        <div>
          <PageTitle eyebrow="Operación" title="Disponibilidad" description={<>Define qué platillos ofreces y cuántas porciones puedes preparar. Arrastra un platillo de la lista a un día, o una tarjeta a otro día para moverla.</>} />
        </div>
        <Button onClick={guardar} disabled={saving || !semana.data}>
          <Save /> {saving ? 'Guardando…' : hayCambios ? 'Guardar cambios' : 'Guardar'}
        </Button>
      </div>
      {saved && <div className="mb-5 rounded-xl bg-success/10 p-4 text-sm font-semibold text-success">Disponibilidad guardada. Los cambios ya están visibles para empresas y empleados.</div>}
      <div className="grid gap-5 lg:grid-cols-[240px_1fr]">
        <Card>
          <CardHeader><CardTitle className="text-base">Mis platillos</CardTitle></CardHeader>
          <CardContent className="flex flex-col gap-2">
            <BloqueEstado cargando={semana.loading} error={semana.error} vacio={!platillos.length} mensajeVacio="Todavía no tienes platillos publicados." onReintentar={semana.reload} cantidad={3} />
            {platillos.map((item) => {
              const publicado = item.estado_revision === 'publicado'
              return (
                <div
                  key={item.id}
                  draggable={publicado}
                  onDragStart={publicado ? empezarArrastre(item.id, null) : undefined}
                  title={publicado ? 'Arrástralo a un día' : 'En revisión: todavía no se puede ofrecer'}
                  className={`flex items-center gap-2 rounded-lg border p-3 text-sm ${publicado ? 'cursor-grab active:cursor-grabbing' : 'opacity-60'}`}
                >
                  <GripVertical className="size-4 text-muted-foreground" />
                  <span className="flex-1">{item.nombre}</span>
                  {!publicado && <Badge variant="outline" className="text-[10px]">En revisión</Badge>}
                </div>
              )
            })}
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="flex-row items-center justify-between gap-3">
            <CardTitle>Semana del {formatRangoFechas(dias[0], dias[dias.length - 1])}</CardTitle>
            <div className="flex items-center gap-1">
              <Button variant="outline" size="icon" aria-label="Semana anterior" onClick={() => setInicioSemana((s) => sumarDias(s, -7))}><ChevronLeft /></Button>
              <Button variant="outline" size="icon" aria-label="Semana siguiente" onClick={() => setInicioSemana((s) => sumarDias(s, 7))}><ChevronRight /></Button>
            </div>
          </CardHeader>
          <CardContent className="overflow-x-auto">
            <BloqueEstado cargando={semana.loading} error={semana.error} vacio={false} mensajeVacio="" onReintentar={semana.reload} cantidad={2} />
            {semana.data && (
              <div className="grid min-w-[850px] grid-cols-6 gap-3">
                {dias.map((dia) => {
                  const editable = diaEditable(dia)
                  const tarjetas = tarjetasDe(dia)
                  return (
                    <div
                      key={dia}
                      data-dia={dia}
                      onDragOver={editable ? (e) => { e.preventDefault(); setDiaSobre(dia) } : undefined}
                      onDragLeave={() => setDiaSobre((d) => (d === dia ? null : d))}
                      onDrop={editable ? (e) => alSoltar(dia, e) : undefined}
                      className={`min-h-48 rounded-xl border p-3 transition-colors ${!editable ? 'bg-muted/30 opacity-70' : diaSobre === dia ? 'border-primary bg-primary/10' : ''}`}
                    >
                      <p className="flex items-center justify-center gap-1 border-b pb-3 text-center text-sm font-bold">
                        {etiquetaDiaCorta(dia)}
                        {!editable && <Lock className="size-3 text-muted-foreground" aria-label="Día cerrado" />}
                      </p>
                      <div className="mt-3 flex flex-col gap-3">
                        {tarjetas.map((t) => (
                          <Dish
                            key={t.platillo_id}
                            name={t.nombre}
                            portions={t.porciones}
                            taken={t.tomadas}
                            editable={editable}
                            destinos={dias.filter((d) => d !== dia && diaEditable(d))}
                            onDragStart={empezarArrastre(t.platillo_id, dia)}
                            onMoverA={(destino) => mover(t.platillo_id, dia, destino)}
                            onChange={(value) => { setSaved(false); setMaximos((m) => ({ ...m, [clave(t.platillo_id, dia)]: value })) }}
                          />
                        ))}
                        {!tarjetas.length && <p className="py-6 text-center text-xs text-muted-foreground">{editable ? 'Suelta aquí un platillo' : 'Día cerrado'}</p>}
                      </div>
                    </div>
                  )
                })}
              </div>
            )}
            <p className="mt-5 flex items-center gap-2 text-xs text-muted-foreground"><AlertTriangle className="size-4 text-warning" /> Una alerta aparece cuando el máximo queda por debajo de lo ya pedido. Los días con candado ya cerraron su corte.</p>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}

function Dish({ name, portions, taken, editable, destinos, onDragStart, onMoverA, onChange }: {
  name: string
  portions: number
  taken: number
  editable: boolean
  destinos: string[]
  onDragStart: (e: DragEvent) => void
  onMoverA: (destino: string) => void
  onChange: (value: number) => void
}) {
  return (
    <div draggable={editable && taken === 0} onDragStart={onDragStart} className={`rounded-lg bg-muted/60 p-3 text-xs ${editable && taken === 0 ? 'cursor-grab active:cursor-grabbing' : ''}`}>
      <p className="font-semibold">{name}</p>
      <label className="mt-2 flex items-center justify-between gap-2 text-muted-foreground">
        Máx.
        <input type="number" min={0} disabled={!editable} value={portions} onChange={(e) => onChange(Number(e.target.value))} className="h-8 w-16 rounded border bg-background px-2 text-right text-foreground disabled:opacity-60" />
      </label>
      {portions < taken && <Badge variant="destructive" className="mt-2 text-[10px]">{taken} pedidos</Badge>}
      {editable && taken === 0 && destinos.length > 0 && (
        <select
          aria-label={`Mover ${name} a otro día`}
          value=""
          onChange={(e) => e.target.value && onMoverA(e.target.value)}
          className="mt-2 h-8 w-full rounded border bg-background px-1 text-[11px] text-foreground"
        >
          <option value="">Mover a…</option>
          {destinos.map((d) => <option key={d} value={d}>{etiquetaDiaCorta(d)}</option>)}
        </select>
      )}
    </div>
  )
}
