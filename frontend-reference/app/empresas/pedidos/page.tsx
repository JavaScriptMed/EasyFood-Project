'use client'

import { PageTitle } from '@/components/panel'
import { useState } from 'react'
import { Eye, MapPin, Timer, UserX } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { GrillaDatos, PanelDetalle } from '@/components/ventanas'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { BloqueEstado } from '@/components/estados'
import { useEmpresa } from '@/components/empresa-shell'
import { listarPedidosManana } from '@/lib/api'
import { useNow } from '@/lib/live'
import { useAsync } from '@/lib/use-async'

export default function EmpresaPedidos() {
  const { empresa } = useEmpresa()
  const { contrato } = empresa
  const now = useNow()
  const pedidos = useAsync(() => listarPedidosManana(empresa.id), [empresa.id])
  const groups = pedidos.data?.grupos ?? []
  const pending = pedidos.data?.pendientes ?? []
  const [verId, setVerId] = useState<string | null>(null)
  const ver = groups.find((g) => g.platillo_id === verId) ?? null

  const tomorrow = now ? new Date(now.getTime() + 86_400_000) : null
  const dateLabel = tomorrow?.toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long' })

  return (
    <div>
      <div className="mb-6 border-b pb-6">
        <PageTitle eyebrow={dateLabel ?? 'Mañana'} title="Pedidos de mañana" description={<>{pedidos.data ? pedidos.data.total : '–'} platillos elegidos por tu equipo, agrupados por platillo.</>} />
      </div>

      <Card className="mb-5">
        <CardContent className="flex flex-wrap items-center gap-x-8 gap-y-3 p-5">
          <div className="flex items-center gap-3">
            <div className="grid size-10 place-items-center rounded-xl bg-primary/15 text-primary"><Timer className="size-5" /></div>
            <div><p className="text-xs uppercase text-muted-foreground">Hora estimada de entrega</p><p className="font-display text-2xl font-extrabold">{contrato.hora_entrega} h</p></div>
          </div>
          <div className="flex items-center gap-3">
            <div className="grid size-10 place-items-center rounded-xl bg-muted text-muted-foreground"><MapPin className="size-5" /></div>
            <div><p className="text-xs uppercase text-muted-foreground">Ventana {contrato.ventana_entrega_inicio} – {contrato.ventana_entrega_fin} h</p><p className="text-sm font-semibold">{empresa.direccion_entrega}</p></div>
          </div>
        </CardContent>
      </Card>

      <BloqueEstado variante="tarjetas" cantidad={4} cargando={pedidos.loading} error={pedidos.error} vacio={!groups.length} mensajeVacio="Todavía nadie ha elegido platillo para mañana." onReintentar={pedidos.reload} className="mb-5" />
      <div className="grid gap-4 md:grid-cols-2">
        {groups.map(({ platillo_id, platillo_nombre: dish, personas: people }) => (
          <Card key={platillo_id}>
            <CardHeader className="flex flex-row items-start justify-between gap-3">
              <CardTitle className="text-base leading-snug">{dish}</CardTitle>
              <span className="grid h-9 min-w-9 shrink-0 place-items-center rounded-full bg-primary px-2 font-display font-extrabold text-primary-foreground">{people.length}</span>
            </CardHeader>
            <CardContent>
              <ul className="flex flex-wrap gap-2">
                {people.map((p) => <li key={p.empleado_id} className="rounded-full bg-muted px-3 py-1 text-xs font-medium">{p.nombre}</li>)}
              </ul>
              <Button variant="ghost" size="sm" className="mt-3" onClick={() => setVerId(platillo_id)}><Eye /> Ver detalle</Button>
            </CardContent>
          </Card>
        ))}
      </div>

      {pending.length > 0 && (
        <Card className="mt-5">
          <CardHeader><CardTitle className="flex items-center gap-2 text-base"><UserX className="size-4 text-amber-600" /> Aún sin elegir ({pending.length})</CardTitle></CardHeader>
          <CardContent>
            <ul className="flex flex-wrap gap-2">
              {pending.map((p) => <li key={p.empleado_id} className="rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1 text-xs font-medium text-amber-700">{p.nombre}</li>)}
            </ul>
            <p className="mt-3 text-xs text-muted-foreground">Pueden elegir hasta el corte de pedidos.</p>
          </CardContent>
        </Card>
      )}

      {ver && (
        <PanelDetalle etiqueta={`Pedido de mañana${dateLabel ? ` · ${dateLabel}` : ''}`} titulo={ver.platillo_nombre} onCerrar={() => setVerId(null)}>
          <GrillaDatos datos={[
            { etiqueta: 'Porciones', valor: ver.personas.length },
            { etiqueta: 'Del total de mañana', valor: pedidos.data?.total ? `${Math.round((ver.personas.length / pedidos.data.total) * 100)} %` : '—' },
            { etiqueta: 'Entrega estimada', valor: `${contrato.hora_entrega} h` },
            { etiqueta: 'Dirección', valor: empresa.direccion_entrega },
          ]} />
          <h3 className="mb-2 mt-6 font-display text-lg font-bold">Quién lo pidió</h3>
          <ul className="divide-y rounded-lg border text-sm">{ver.personas.map((p) => <li key={p.empleado_id} className="px-3 py-2">{p.nombre}</li>)}</ul>
        </PanelDetalle>
      )}
    </div>
  )
}
