'use client'

// El empleado califica lo que ya recibió: la entrega (puntualidad, trato del chofer y empaque) y cada platillo (sabor y calidad), de 1 a 5.
// Solo aparecen los días ya entregados de los últimos 14 días que todavía no calificó.

import { useState } from 'react'
import { toast } from 'sonner'
import { Star } from 'lucide-react'
import { BloqueEstado } from '@/components/estados'
import { CampoParrafo, VentanaFormulario } from '@/components/ventanas'
import { Button } from '@/components/ui/button'
import { calificarEntrega, calificarPlatillo, listarCalificacionesPendientes } from '@/lib/api'
import { EMPLEADO_PORTAL_ID } from '@/lib/config'
import { etiquetasTiempoComida } from '@/lib/etiquetas'
import { formatFechaLarga } from '@/lib/format'
import type { CalificacionPendiente } from '@/lib/types'
import { useAsync } from '@/lib/use-async'

const NOMBRES = ['Muy mal', 'Mal', 'Regular', 'Bien', 'Excelente']

/** Cinco estrellas para elegir (un solo valor de 1 a 5). Se maneja con el teclado como un grupo de opciones. */
export function EntradaDeEstrellas({ etiqueta, valor, onChange }: { etiqueta: string; valor: number; onChange: (n: number) => void }) {
  return (
    <div role="radiogroup" aria-label={etiqueta} className="flex items-center justify-between gap-3">
      <span className="text-sm font-medium">{etiqueta}</span>
      <span className="flex items-center gap-0.5">
        {[1, 2, 3, 4, 5].map((n) => (
          <button key={n} type="button" role="radio" aria-checked={valor === n} aria-label={`${n} de 5: ${NOMBRES[n - 1]}`} title={NOMBRES[n - 1]} onClick={() => onChange(n)} className="rounded p-0.5 outline-none focus-visible:ring-2 focus-visible:ring-primary">
            <Star className="size-7" style={{ color: n <= valor ? '#e9b51f' : undefined, fill: n <= valor ? '#e9b51f' : 'transparent' }} aria-hidden="true" />
          </button>
        ))}
      </span>
    </div>
  )
}

type Calificando = { tipo: 'entrega'; dia: CalificacionPendiente } | { tipo: 'platillo'; dia: CalificacionPendiente; platillo: CalificacionPendiente['platillos'][number] }

export function CalificarLoRecibido() {
  const pendientes = useAsync(() => listarCalificacionesPendientes(EMPLEADO_PORTAL_ID))
  const [calificando, setCalificando] = useState<Calificando | null>(null)
  const dias = pendientes.data ?? []

  return (
    <section className="mt-10" aria-labelledby="calificar-titulo">
      <h2 id="calificar-titulo" className="font-display text-xl font-bold tracking-tight">Califica lo que recibiste</h2>
      <p className="mt-1 text-sm text-muted-foreground">Tu opinión ayuda a mejorar el menú y el reparto. Puedes calificar hasta 14 días después de recibir tu comida.</p>
      <div className="mt-4">
        <BloqueEstado variante="tarjetas" cantidad={2} cargando={pendientes.loading} error={pendientes.error} vacio={!dias.length} mensajeVacio="No tienes nada pendiente por calificar. ¡Gracias!" onReintentar={pendientes.reload} />
        <ul className="grid gap-3 md:grid-cols-2">
          {dias.map((dia) => (
            <li key={dia.fecha} className="rounded-2xl border bg-card p-4 shadow-sm">
              <p className="font-semibold">{formatFechaLarga(dia.fecha)}</p>
              <div className="mt-3 flex flex-col gap-2">
                {!dia.entrega_calificada && <div className="flex items-center justify-between gap-3 text-sm"><span>La entrega</span><Button size="sm" variant="outline" onClick={() => setCalificando({ tipo: 'entrega', dia })}>Calificar entrega</Button></div>}
                {dia.platillos.filter((p) => !p.calificado).map((p) => (
                  <div key={`${p.tiempo_comida}-${p.platillo_id}`} className="flex items-center justify-between gap-3 text-sm"><span className="min-w-0 truncate"><span className="text-muted-foreground">{etiquetasTiempoComida[p.tiempo_comida]}:</span> {p.platillo_nombre}</span><Button size="sm" variant="outline" onClick={() => setCalificando({ tipo: 'platillo', dia, platillo: p })}>Calificar platillo</Button></div>
                ))}
              </div>
            </li>
          ))}
        </ul>
      </div>
      {calificando && <VentanaCalificar calificando={calificando} onCerrar={() => setCalificando(null)} onListo={() => { setCalificando(null); pendientes.reload({ silent: true }) }} />}
    </section>
  )
}

function VentanaCalificar({ calificando, onCerrar, onListo }: { calificando: Calificando; onCerrar: () => void; onListo: () => void }) {
  const esEntrega = calificando.tipo === 'entrega'
  const [puntualidad, setPuntualidad] = useState(0)
  const [trato, setTrato] = useState(0)
  const [empaque, setEmpaque] = useState(0)
  const [estrellas, setEstrellas] = useState(0)
  const [comentario, setComentario] = useState('')
  const [guardando, setGuardando] = useState(false)
  const completo = esEntrega ? puntualidad > 0 && trato > 0 && empaque > 0 : estrellas > 0

  async function guardar() {
    setGuardando(true)
    try {
      if (calificando.tipo === 'entrega') {
        await calificarEntrega(EMPLEADO_PORTAL_ID, { fecha: calificando.dia.fecha, puntualidad, trato_chofer: trato, empaque, comentario: comentario.trim() || undefined })
        toast.success('Gracias por calificar la entrega')
      } else {
        await calificarPlatillo(EMPLEADO_PORTAL_ID, { fecha: calificando.dia.fecha, tiempo_comida: calificando.platillo.tiempo_comida, estrellas, comentario: comentario.trim() || undefined })
        toast.success('Gracias por calificar el platillo')
      }
      onListo()
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo guardar tu calificación')
    } finally {
      setGuardando(false)
    }
  }

  return (
    <VentanaFormulario titulo={esEntrega ? 'Califica la entrega' : `Califica ${calificando.platillo.platillo_nombre}`} descripcion={esEntrega ? `La entrega del ${formatFechaLarga(calificando.dia.fecha)}: puntualidad, trato del chofer y estado del empaque.` : 'Sabor y calidad del platillo, de 1 a 5 estrellas.'} textoEnviar="Enviar calificación" trabajando={guardando} deshabilitarEnvio={!completo} onCerrar={onCerrar} onEnviar={guardar}>
      {esEntrega ? (
        <>
          <EntradaDeEstrellas etiqueta="Puntualidad" valor={puntualidad} onChange={setPuntualidad} />
          <EntradaDeEstrellas etiqueta="Trato del chofer" valor={trato} onChange={setTrato} />
          <EntradaDeEstrellas etiqueta="Estado del empaque" valor={empaque} onChange={setEmpaque} />
        </>
      ) : <EntradaDeEstrellas etiqueta="Sabor y calidad" valor={estrellas} onChange={setEstrellas} />}
      <CampoParrafo etiqueta="Comentario (opcional)" maxLength={500} value={comentario} onChange={(e) => setComentario(e.target.value)} placeholder="¿Algo que quieras contarnos?" />
    </VentanaFormulario>
  )
}
