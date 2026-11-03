'use client'

import { PageTitle } from '@/components/panel'
import Link from 'next/link'
import { ArrowRight, CalendarCheck, CalendarDays, Clock3, Percent, ShoppingBasket, Timer, UserCheck, UtensilsCrossed } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useEmpresa } from '@/components/empresa-shell'
import { MES_ACTUAL } from '@/lib/config'
import { etiquetaMes, formatDate, formatMXN } from '@/lib/format'
import { formatCountdown, secondsUntilHour, useCountUp, useNow } from '@/lib/live'

export default function EmpresaInicio() {
  const { empresa, empleados, horaCorte } = useEmpresa()
  const { contrato } = empresa
  const now = useNow()

  const active = empleados.filter((e) => e.activo).length
  const chosen = empleados.filter((e) => e.eleccion_manana).length
  const consumed = empleados.reduce((sum, e) => sum + (e.platillos_por_mes[MES_ACTUAL] ?? 0), 0)
  const minimumPercent = Math.min(100, Math.round((consumed / contrato.compra_minima_mensual) * 100))

  const dishes = useCountUp(chosen)
  const picked = useCountUp(chosen)
  const month = useCountUp(consumed, 1400)

  const seconds = now ? secondsUntilHour(now, Number(horaCorte.slice(0, 2)), Number(horaCorte.slice(3, 5))) : null
  const urgent = seconds !== null && seconds < 3600

  return (
    <div>
      <div className="mb-8 flex flex-wrap items-end justify-between gap-4 border-b pb-6">
        <div>
          <PageTitle eyebrow="Portal de empresas" title={empresa.nombre} description={<>Así va el almuerzo de tu equipo.</>} />
        </div>
        <Badge variant="outline" className={`h-auto gap-2 px-3 py-2 ${urgent ? 'border-amber-500 text-amber-600' : ''}`}>
          <Clock3 className="size-4" aria-hidden="true" />
          <span>Corte de pedidos ({horaCorte} h):</span>
          <span className="font-mono font-bold tabular-nums" aria-live="off">{seconds === null ? '--:--:--' : formatCountdown(seconds)}</span>
        </Badge>
      </div>

      <section className="grid gap-4 md:grid-cols-3">
        <Counter icon={UtensilsCrossed} label="Platillos de mañana" value={String(dishes)} helper="Se entregan en tu empresa" />
        <Counter icon={UserCheck} label="Empleados que ya eligieron" value={`${picked} / ${active}`} helper={active - chosen > 0 ? `Faltan ${active - chosen} por elegir antes del corte` : 'Todos eligieron'} />
        <Counter icon={ShoppingBasket} label="Consumo del mes" value={`${month} / ${contrato.compra_minima_mensual}`} helper={`${etiquetaMes(MES_ACTUAL)} · mínimo contratado`}>
          <div className="mt-3 h-2 overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuenow={minimumPercent} aria-valuemin={0} aria-valuemax={100} aria-label="Consumo contra el mínimo">
            <div className={`h-full rounded-full transition-all duration-1000 ${consumed < contrato.compra_minima_mensual ? 'bg-amber-500' : 'bg-emerald-500'}`} style={{ width: `${minimumPercent}%` }} />
          </div>
        </Counter>
      </section>

      {consumed < contrato.compra_minima_mensual && (
        <p className="mt-4 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-700">
          Faltan {contrato.compra_minima_mensual - consumed} platillos para alcanzar la compra mínima. Si no se alcanza, la diferencia se factura al cierre del mes.
        </p>
      )}

      <section className="mt-5 grid gap-5 lg:grid-cols-[1.2fr_.8fr]">
        <Card>
          <CardHeader><CardTitle>Tu contrato</CardTitle></CardHeader>
          <CardContent>
            <dl className="grid gap-4 sm:grid-cols-3">
              <Fact icon={CalendarDays} label="Vigencia" value={`${formatDate(contrato.vigencia_inicio)} – ${formatDate(contrato.vigencia_fin)}`} />
              <Fact icon={ShoppingBasket} label="Compra mínima" value={`${contrato.compra_minima_mensual} platillos al mes`} />
              <Fact icon={Percent} label="Subsidio de la empresa" value={`${contrato.porcentaje_subsidio}% del platillo`} />
            </dl>
            <div className="mt-5 rounded-xl bg-muted/60 p-4 text-sm">
              <p>Precio por platillo <strong>{formatMXN(contrato.precio_por_platillo)}</strong>: la empresa cubre <strong>{formatMXN((contrato.precio_por_platillo * contrato.porcentaje_subsidio) / 100)}</strong> y a cada empleado le corresponden <strong>{formatMXN(contrato.precio_por_platillo - (contrato.precio_por_platillo * contrato.porcentaje_subsidio) / 100)}</strong>.</p>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader><CardTitle>Entrega de mañana</CardTitle></CardHeader>
          <CardContent>
            <div className="flex items-start gap-3">
              <div className="grid size-10 shrink-0 place-items-center rounded-xl bg-primary/15 text-primary"><Timer className="size-5" /></div>
              <div>
                <p className="font-display text-2xl font-extrabold">{contrato.hora_entrega} h</p>
                <p className="text-sm text-muted-foreground">Ventana {contrato.ventana_entrega_inicio} – {contrato.ventana_entrega_fin} h</p>
                <p className="mt-1 text-sm">{empresa.direccion_entrega}</p>
              </div>
            </div>
            <Link href="/empresas/pedidos" className="mt-5 inline-flex items-center gap-2 text-sm font-semibold text-primary">Ver pedidos de mañana <ArrowRight className="size-4" /></Link>
          </CardContent>
        </Card>
      </section>

      <p className="mt-5 flex items-center gap-2 text-xs text-muted-foreground"><CalendarCheck className="size-4" /> Los empleados pueden elegir su platillo hasta el corte de pedidos.</p>
    </div>
  )
}

function Counter({ icon: Icon, label, value, helper, children }: { icon: typeof UtensilsCrossed; label: string; value: string; helper: string; children?: React.ReactNode }) {
  return (
    <div className="relative overflow-hidden rounded-2xl border bg-card p-5">
      <div className="flex items-start justify-between gap-3">
        <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">{label}</p>
        <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-primary/15"><Icon className="size-[18px]" /></span>
      </div>
      <p className="mt-3 font-display text-[2rem] font-bold leading-none tracking-tight tabular-nums">{value}</p>
      <p className="mt-2 text-xs text-muted-foreground">{helper}</p>
      {children}
    </div>
  )
}

function Fact({ icon: Icon, label, value }: { icon: typeof UtensilsCrossed; label: string; value: string }) {
  return (
    <div>
      <dt className="flex items-center gap-2 text-xs uppercase text-muted-foreground"><Icon className="size-3.5" /> {label}</dt>
      <dd className="mt-1.5 font-semibold">{value}</dd>
    </div>
  )
}
