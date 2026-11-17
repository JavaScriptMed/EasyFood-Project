'use client'

// Indicadores del dashboard: se usan en el panel del administrador (los 10) y en el portal de empresas (los de RR. HH.).
// Reglas de lectura: cada indicador dice qué mide y en qué periodo; el semáforo y el límite de maquila llevan ícono y texto
// (nunca solo color); la gráfica del balance trae leyenda, tooltip y una vista de tabla.

import { useState, type ReactNode } from 'react'
import { Bar, CartesianGrid, ComposedChart, Legend, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { BadgeCheck, CheckCircle2, ChefHat, CircleDashed, ClockAlert, FileSignature, PackageCheck, PiggyBank, Receipt, Scale, Star, TriangleAlert, Trophy, Truck } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { formatMXN } from '@/lib/format'
import type { BalanceSemanal, FacturaSemaforo, IndicadoresAdmin, IndicadoresEmpresa, PeriodoIndicadores, Semaforo } from '@/lib/types'

const COLOR_INGRESOS = '#3d7fe8' // azul y ámbar se distinguen también con daltonismo
const COLOR_COSTOS = '#e9b51f'
const COLOR_ESTRELLA = '#e9b51f'

/** «26 oct»: día y mes, sin año ni día de la semana. */
const etiquetaDiaCorta = (fecha: string) => new Date(`${fecha}T12:00:00`).toLocaleDateString('es-MX', { day: 'numeric', month: 'short' }).replace('.', '')
const rangoDeSemana = (p: PeriodoIndicadores) => `${etiquetaDiaCorta(p.semana_inicio)} – ${etiquetaDiaCorta(p.semana_fin)}`
const numero = (n: number) => n.toLocaleString('es-MX')
const decimal = (n: number | null) => (n === null ? '—' : n.toFixed(1))

// ---------------------------------------------------------------- piezas

/** Estrellas de solo lectura: `valor` de 0 a 5 (con decimales) y el número al lado. */
export function Estrellas({ valor, tamano = 'size-4' }: { valor: number | null; tamano?: string }) {
  if (valor === null) return <span className="text-sm text-muted-foreground">Sin calificaciones todavía</span>
  return (
    <span className="inline-flex items-center gap-1.5" role="img" aria-label={`${valor.toFixed(1)} de 5 estrellas`}>
      <span className="flex" aria-hidden="true">
        {[1, 2, 3, 4, 5].map((n) => {
          const lleno = Math.max(0, Math.min(1, valor - (n - 1)))
          return (
            <span key={n} className={`relative inline-block ${tamano}`}>
              <Star className={`absolute inset-0 ${tamano} text-muted-foreground/35`} />
              <span className="absolute inset-y-0 left-0 overflow-hidden" style={{ width: `${lleno * 100}%` }}><Star className={tamano} style={{ color: COLOR_ESTRELLA, fill: COLOR_ESTRELLA }} /></span>
            </span>
          )
        })}
      </span>
      <span className="font-display font-bold tabular-nums">{valor.toFixed(1)}</span>
    </span>
  )
}

const ESTILO_SEMAFORO: Record<Semaforo, { texto: string; icono: typeof CheckCircle2; clase: string }> = {
  verde: { texto: 'Pagada', icono: CheckCircle2, clase: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' },
  rojo: { texto: 'Pendiente de pago', icono: ClockAlert, clase: 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300' },
  gris: { texto: 'Sin emitir', icono: CircleDashed, clase: 'border-border bg-muted text-muted-foreground' },
}

/** Verde = pagada, rojo = emitida y pendiente, gris = sin emitir. Siempre con ícono y texto. */
export function PillSemaforo({ semaforo }: { semaforo: Semaforo }) {
  const { texto, icono: Icono, clase } = ESTILO_SEMAFORO[semaforo]
  return <span data-semaforo={semaforo} className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold ${clase}`}><Icono className="size-3.5" aria-hidden="true" />{texto}</span>
}

function Tarjeta({ titulo, icono: Icono, acento, periodo, children, className = '' }: { titulo: string; icono: typeof Truck; acento: string; periodo?: string; children: ReactNode; className?: string }) {
  return (
    <Card className={`overflow-hidden border-border/70 ${className}`}>
      <CardContent className="relative p-5">
        <span aria-hidden="true" className="absolute inset-x-0 top-0 h-0.5" style={{ background: `linear-gradient(90deg, ${acento}, transparent)` }} />
        <div className="flex items-start justify-between gap-3">
          <div><p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">{titulo}</p>{periodo && <p className="mt-0.5 text-[11px] text-muted-foreground">{periodo}</p>}</div>
          <div className="grid size-9 shrink-0 place-items-center rounded-xl" style={{ backgroundColor: `${acento}1f`, color: acento }}><Icono className="size-[18px]" aria-hidden="true" /></div>
        </div>
        <div className="mt-3">{children}</div>
      </CardContent>
    </Card>
  )
}

/** Barritas de las últimas semanas; la última (semana en curso) va resaltada. Es decoración: los números están en el texto. */
function MiniSerie({ valores, etiquetas, color, unidad }: { valores: (number | null)[]; etiquetas: string[]; color: string; unidad: string }) {
  const maximo = Math.max(1, ...valores.map((v) => v ?? 0))
  return (
    <div className="mt-3 flex h-10 items-end gap-1" role="img" aria-label={`Últimas ${valores.length} semanas: ${valores.map((v, i) => `${etiquetas[i]} ${v ?? 'sin dato'} ${unidad}`).join(', ')}`}>
      {valores.map((v, i) => (
        <div key={etiquetas[i]} title={`Semana del ${etiquetas[i]}: ${v ?? 'sin dato'} ${unidad}`} className="flex-1 rounded-t-sm" style={{ height: `${Math.max(8, ((v ?? 0) / maximo) * 100)}%`, backgroundColor: color, opacity: i === valores.length - 1 ? 1 : 0.4 }} />
      ))}
    </div>
  )
}

function Aspecto({ nombre, valor }: { nombre: string; valor: number | null }) {
  return (
    <div className="flex items-center gap-3 text-xs">
      <span className="w-20 shrink-0 text-muted-foreground">{nombre}</span>
      <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full" style={{ width: `${((valor ?? 0) / 5) * 100}%`, backgroundColor: COLOR_ESTRELLA }} /></div>
      <span className="w-7 text-right font-semibold tabular-nums">{decimal(valor)}</span>
    </div>
  )
}

// ---------------------------------------------------------------- 8. balance semanal (ingresos contra costos)

function GraficaBalance({ serie }: { serie: BalanceSemanal[] }) {
  const [comoTabla, setComoTabla] = useState(false)
  const datos = serie.map((s) => ({ semana: etiquetaDiaCorta(s.semana_inicio ?? ''), Ingresos: s.ingresos, Costos: s.costos, Utilidad: s.utilidad }))
  return (
    <div>
      <div className="mb-2 flex justify-end">
        <button type="button" onClick={() => setComoTabla((v) => !v)} className="text-xs font-semibold text-primary hover:underline">{comoTabla ? 'Ver gráfica' : 'Ver como tabla'}</button>
      </div>
      {comoTabla ? (
        <div className="overflow-x-auto rounded-lg border">
          <table className="w-full text-left text-sm">
            <thead className="border-b bg-muted/40 text-xs uppercase text-muted-foreground"><tr><th className="px-3 py-2">Semana del</th><th className="px-3 py-2 text-right">Ingresos</th><th className="px-3 py-2 text-right">Costos</th><th className="px-3 py-2 text-right">Utilidad</th></tr></thead>
            <tbody>{datos.map((d) => <tr key={d.semana} className="border-b last:border-0"><td className="px-3 py-2">{d.semana}</td><td className="px-3 py-2 text-right tabular-nums">{formatMXN(d.Ingresos)}</td><td className="px-3 py-2 text-right tabular-nums">{formatMXN(d.Costos)}</td><td className={`px-3 py-2 text-right font-semibold tabular-nums ${d.Utilidad < 0 ? 'text-red-600 dark:text-red-400' : ''}`}>{formatMXN(d.Utilidad)}</td></tr>)}</tbody>
          </table>
        </div>
      ) : (
        <div role="img" aria-label={`Ingresos contra costos de las últimas ${serie.length} semanas, con la utilidad de cada una`}>
          <ResponsiveContainer width="100%" height={260}>
            <ComposedChart data={datos} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
              <CartesianGrid vertical={false} strokeDasharray="3 3" opacity={0.3} />
              <XAxis dataKey="semana" axisLine={false} tickLine={false} fontSize={12} />
              <YAxis axisLine={false} tickLine={false} width={52} fontSize={12} tickFormatter={(v: number) => `${Math.round(v / 1000)}k`} />
              <Tooltip formatter={(valor) => formatMXN(Number(valor))} labelFormatter={(semana) => `Semana del ${semana}`} />
              <Legend verticalAlign="top" height={28} iconType="circle" />
              <Bar dataKey="Ingresos" fill={COLOR_INGRESOS} radius={[4, 4, 0, 0]} maxBarSize={28} isAnimationActive={false} />
              <Bar dataKey="Costos" fill={COLOR_COSTOS} radius={[4, 4, 0, 0]} maxBarSize={28} isAnimationActive={false} />
              <Line type="monotone" dataKey="Utilidad" stroke="currentColor" strokeWidth={2} dot={{ r: 4, strokeWidth: 2, fill: 'var(--background)' }} isAnimationActive={false} />
            </ComposedChart>
          </ResponsiveContainer>
        </div>
      )}
    </div>
  )
}

// ---------------------------------------------------------------- administrador: los 10 indicadores

export function IndicadoresAdministrador({ datos }: { datos: IndicadoresAdmin }) {
  const { periodo } = datos
  const semanas = (serie: { semana_inicio: string }[]) => serie.map((s) => etiquetaDiaCorta(s.semana_inicio))
  const semana = `Semana del ${rangoDeSemana(periodo)}`
  const entrega = datos.calificacion_entrega
  const menu = datos.calificacion_menu
  const popular = datos.paquete_popular
  const ahorro = datos.ahorro_estimado
  const quejas = datos.incidencias.semana
  const contratos = datos.contratos_nuevos
  const balance = datos.balance.semana
  const maquila = datos.costo_maquila
  const facturacion = datos.facturacion_b2b
  const masPedidos = Math.max(1, ...popular.paquetes.map((p) => p.pedidos))

  return (
    <section aria-labelledby="indicadores-titulo" className="mt-10">
      <div className="mb-4 flex flex-wrap items-end justify-between gap-2">
        <div><h2 id="indicadores-titulo" className="font-display text-2xl font-bold tracking-tight">Indicadores de la operación</h2><p className="text-sm text-muted-foreground">{semana} · se calculan al abrir el panel</p></div>
        <Badge variant="secondary" className="gap-1"><span className="size-2 animate-pulse rounded-full bg-success" />En tiempo real</Badge>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Tarjeta titulo="Platillos entregados" icono={PackageCheck} acento="#e9b51f" periodo={semana}>
          <p className="font-display text-[2rem] font-bold leading-none tabular-nums">{numero(datos.platillos_entregados.semana)}</p>
          <p className="mt-1 text-xs text-muted-foreground">comidas servidas a las empresas (lo que concilia RR. HH.)</p>
          <MiniSerie valores={datos.platillos_entregados.serie.map((s) => s.platillos)} etiquetas={semanas(datos.platillos_entregados.serie)} color="#e9b51f" unidad="platillos" />
          <ul className="mt-3 space-y-1 text-xs">{datos.platillos_entregados.por_empresa.slice(0, 3).map((e) => <li key={e.empresa_id} className="flex justify-between gap-2"><span className="truncate text-muted-foreground">{e.empresa}</span><strong className="tabular-nums">{numero(e.platillos)}</strong></li>)}</ul>
        </Tarjeta>

        <Tarjeta titulo="Calificación de la entrega" icono={Truck} acento="#3d7fe8" periodo={`${semana} · última milla`}>
          <Estrellas valor={entrega.promedio} tamano="size-5" />
          <p className="mt-1 text-xs text-muted-foreground">{entrega.calificaciones} calificaciones · promedio de puntualidad, trato y empaque</p>
          <div className="mt-3 space-y-1.5"><Aspecto nombre="Puntualidad" valor={entrega.puntualidad} /><Aspecto nombre="Trato del chofer" valor={entrega.trato_chofer} /><Aspecto nombre="Empaque" valor={entrega.empaque} /></div>
        </Tarjeta>

        <Tarjeta titulo="Calificación del menú" icono={Star} acento="#43a86b" periodo={`${semana} · sabor y calidad`}>
          <Estrellas valor={menu.promedio} tamano="size-5" />
          <p className="mt-1 text-xs text-muted-foreground">{menu.calificaciones} calificaciones de empleados y clientes</p>
          <MiniSerie valores={menu.serie.map((s) => s.promedio)} etiquetas={semanas(menu.serie)} color="#43a86b" unidad="estrellas" />
          {menu.por_comedor.length > 0 && <ul className="mt-3 space-y-1 text-xs">{menu.por_comedor.slice(0, 3).map((c) => <li key={c.comedor} className="flex justify-between gap-2"><span className="truncate text-muted-foreground">{c.comedor}</span><strong className="tabular-nums">{c.promedio.toFixed(1)} ★</strong></li>)}</ul>}
        </Tarjeta>

        <Tarjeta titulo="Paquete más popular" icono={Trophy} acento="#a98bc4" periodo={`Pedidos pagados de ${periodo.mes}`}>
          {popular.paquete ? <><p className="font-display text-2xl font-bold leading-tight">{popular.paquete.nombre}</p><p className="mt-1 text-xs text-muted-foreground">{popular.paquete.dias === 1 ? '1 día' : `${popular.paquete.dias} días`} · {popular.paquete.pedidos} pedidos este mes</p></> : <p className="text-sm text-muted-foreground">Todavía no hay pedidos pagados este mes.</p>}
          <ul className="mt-3 space-y-2">{popular.paquetes.map((p) => <li key={p.id} className="text-xs"><div className="mb-1 flex justify-between"><span>{p.nombre} <span className="text-muted-foreground">({p.dias} {p.dias === 1 ? 'día' : 'días'})</span></span><strong className="tabular-nums">{p.pedidos}</strong></div><div className="h-1.5 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-[#a98bc4]" style={{ width: `${(p.pedidos / masPedidos) * 100}%` }} /></div></li>)}</ul>
        </Tarjeta>
      </div>

      <div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Tarjeta titulo="Ahorro estimado por empleado" icono={PiggyBank} acento="#43a86b" periodo={`Mes de ${periodo.mes}`}>
          <p className="font-display text-[2rem] font-bold leading-none tabular-nums">{formatMXN(ahorro.por_empleado_promedio)}</p>
          <p className="mt-1 text-xs text-muted-foreground">en promedio por empleado, frente a las apps de entrega (sobreprecio estimado de {ahorro.porcentaje_sobreprecio} %)</p>
          <p className="mt-3 text-xs"><strong className="tabular-nums">{formatMXN(ahorro.ahorro_total)}</strong> <span className="text-muted-foreground">entre {ahorro.empleados} empleados</span></p>
        </Tarjeta>

        <Tarjeta titulo="Incidencias o quejas" icono={TriangleAlert} acento="#d9822b" periodo={semana}>
          <p className="font-display text-[2rem] font-bold leading-none tabular-nums">{quejas.total}</p>
          <p className="mt-1 text-xs text-muted-foreground">{quejas.abiertas > 0 ? `${quejas.abiertas} sin resolver` : 'ninguna sin resolver'}</p>
          <ul className="mt-3 space-y-1 text-xs">{quejas.por_tipo.map((t) => <li key={t.tipo} className="flex justify-between gap-2"><span className="text-muted-foreground">{t.etiqueta}</span><strong className="tabular-nums">{t.cantidad}</strong></li>)}</ul>
          <MiniSerie valores={datos.incidencias.serie.map((s) => s.total)} etiquetas={semanas(datos.incidencias.serie)} color="#d9822b" unidad="quejas" />
        </Tarjeta>

        <Tarjeta titulo="Nuevos contratos B2B" icono={FileSignature} acento="#3d7fe8" periodo={`Firmados en ${periodo.mes}`}>
          <p className="font-display text-[2rem] font-bold leading-none tabular-nums">{contratos.cantidad}</p>
          <p className="mt-1 text-xs text-muted-foreground">corporativos nuevos · el mes pasado: {contratos.mes_anterior}</p>
          <ul className="mt-3 space-y-2 text-xs">{contratos.contratos.map((c) => <li key={c.empresa_id}><p className="font-semibold">{c.empresa}</p><p className="text-muted-foreground">{c.meses} meses desde {etiquetaDiaCorta(c.vigencia_inicio)} · mínimo {numero(c.compra_minima_mensual)} platillos al mes</p></li>)}{!contratos.contratos.length && <li className="text-muted-foreground">Ningún contrato nuevo este mes.</li>}</ul>
        </Tarjeta>

        <Tarjeta titulo="Costo directo de maquila" icono={ChefHat} acento={maquila.en_limite ? '#43a86b' : '#d93b2b'} periodo={`${semana} · tope de ${formatMXN(maquila.limite)} por platillo`}>
          <p className="font-display text-[2rem] font-bold leading-none tabular-nums">{maquila.costo_promedio_semana === null ? '—' : formatMXN(maquila.costo_promedio_semana)}</p>
          <p className="mt-1 text-xs text-muted-foreground">promedio pagado a las cocinas por platillo ({numero(maquila.platillos_semana)} esta semana)</p>
          <p className={`mt-2 inline-flex items-center gap-1.5 text-xs font-semibold ${maquila.en_limite ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'}`}>
            {maquila.en_limite ? <BadgeCheck className="size-4" aria-hidden="true" /> : <TriangleAlert className="size-4" aria-hidden="true" />}{maquila.en_limite ? 'Dentro del límite' : 'Excede el límite'}
          </p>
          <ul className="mt-3 space-y-1 text-xs">{maquila.cocinas.map((c) => <li key={c.comedor_id} className="flex items-center justify-between gap-2"><span className="truncate text-muted-foreground">{c.comedor}</span><span className={`inline-flex items-center gap-1 font-semibold tabular-nums ${c.en_limite ? '' : 'text-red-700 dark:text-red-300'}`}>{c.en_limite ? <CheckCircle2 className="size-3.5 text-emerald-600" aria-label="Dentro del límite" /> : <TriangleAlert className="size-3.5" aria-label="Excede el límite" />}{formatMXN(c.precio_por_platillo)}</span></li>)}</ul>
        </Tarjeta>
      </div>

      <div className="mt-4 grid gap-4 xl:grid-cols-[1.5fr_1fr]">
        <Card className="overflow-hidden border-border/70">
          <CardHeader>
            <CardTitle className="flex items-center gap-2"><Scale className="size-5 text-primary" aria-hidden="true" />Balance semanal de utilidad</CardTitle>
            <p className="text-sm text-muted-foreground">Ingresos por suscripciones contra el pago a cocinas y los costos operativos, de las últimas {datos.balance.serie.length} semanas (por entrega).</p>
          </CardHeader>
          <CardContent>
            <dl className="mb-4 grid grid-cols-3 gap-3 text-sm">
              <div className="rounded-xl bg-muted/50 p-3"><dt className="text-xs text-muted-foreground">Ingresos</dt><dd className="font-display text-lg font-bold tabular-nums" style={{ color: COLOR_INGRESOS }}>{formatMXN(balance.ingresos)}</dd></div>
              <div className="rounded-xl bg-muted/50 p-3"><dt className="text-xs text-muted-foreground">Costos</dt><dd className="font-display text-lg font-bold tabular-nums">{formatMXN(balance.costos)}</dd><p className="text-[11px] text-muted-foreground">cocinas {formatMXN(balance.costo_maquila)} · operativos {formatMXN(balance.costos_operativos)}</p></div>
              <div className="rounded-xl bg-muted/50 p-3"><dt className="text-xs text-muted-foreground">Ganancia limpia</dt><dd className={`font-display text-lg font-bold tabular-nums ${balance.utilidad < 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400'}`}>{formatMXN(balance.utilidad)}</dd></div>
            </dl>
            <GraficaBalance serie={datos.balance.serie} />
          </CardContent>
        </Card>

        <Card className="overflow-hidden border-border/70">
          <CardHeader>
            <CardTitle className="flex items-center gap-2"><Receipt className="size-5 text-primary" aria-hidden="true" />Estatus de facturación B2B</CardTitle>
            <p className="text-sm text-muted-foreground">Cobros de {facturacion.mes}: verde pagado, rojo pendiente.</p>
          </CardHeader>
          <CardContent>
            <dl className="mb-3 grid grid-cols-3 gap-2 text-center text-xs">
              <div className="rounded-lg bg-emerald-500/10 p-2"><dd className="font-display text-xl font-bold tabular-nums text-emerald-700 dark:text-emerald-300">{facturacion.pagadas}</dd><dt className="text-muted-foreground">pagadas</dt></div>
              <div className="rounded-lg bg-red-500/10 p-2"><dd className="font-display text-xl font-bold tabular-nums text-red-700 dark:text-red-300">{facturacion.pendientes}</dd><dt className="text-muted-foreground">pendientes</dt></div>
              <div className="rounded-lg bg-muted p-2"><dd className="font-display text-xl font-bold tabular-nums">{facturacion.sin_emitir}</dd><dt className="text-muted-foreground">sin emitir</dt></div>
            </dl>
            <p className="mb-3 text-xs text-muted-foreground">Cobrado <strong className="tabular-nums text-foreground">{formatMXN(facturacion.monto_cobrado)}</strong> · por cobrar <strong className="tabular-nums text-foreground">{formatMXN(facturacion.monto_pendiente)}</strong></p>
            <ListaFacturas facturas={facturacion.facturas} conEmpresa />
          </CardContent>
        </Card>
      </div>
    </section>
  )
}

function ListaFacturas({ facturas, conEmpresa }: { facturas: FacturaSemaforo[]; conEmpresa?: boolean }) {
  if (!facturas.length) return <p className="py-4 text-center text-sm text-muted-foreground">Todavía no hay facturas.</p>
  return (
    <ul className="divide-y rounded-lg border">
      {facturas.map((f) => (
        <li key={`${f.empresa_id ?? ''}${f.folio ?? f.mes ?? ''}`} className="flex items-center justify-between gap-3 px-3 py-2.5 text-sm">
          <div className="min-w-0"><p className="truncate font-semibold">{conEmpresa ? f.empresa : f.mes}</p><p className="text-xs text-muted-foreground">{f.folio ? `Folio ${f.folio}` : 'Todavía sin factura'}{f.total !== null ? ` · ${formatMXN(f.total)}` : ''}</p></div>
          <PillSemaforo semaforo={f.semaforo} />
        </li>
      ))}
    </ul>
  )
}

// ---------------------------------------------------------------- portal de empresas: los indicadores de RR. HH.

export function IndicadoresEmpresaPanel({ datos }: { datos: IndicadoresEmpresa }) {
  const { periodo } = datos
  const semana = `Semana del ${rangoDeSemana(periodo)}`
  const semanas = (serie: { semana_inicio: string }[]) => serie.map((s) => etiquetaDiaCorta(s.semana_inicio))
  const ahorro = datos.ahorro_estimado
  const quejas = datos.incidencias.semana

  return (
    <section aria-labelledby="indicadores-empresa" className="mt-10">
      <div className="mb-4"><h2 id="indicadores-empresa" className="font-display text-2xl font-bold tracking-tight">Indicadores de tu empresa</h2><p className="text-sm text-muted-foreground">{semana} · para conciliar la factura y ver cómo va el servicio</p></div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <Tarjeta titulo="Platillos entregados" icono={PackageCheck} acento="#e9b51f" periodo={semana}>
          <p className="font-display text-[2rem] font-bold leading-none tabular-nums">{numero(datos.platillos_entregados.semana)}</p>
          <p className="mt-1 text-xs text-muted-foreground">comidas servidas a tu empresa; con esto concilias la factura del mes</p>
          <MiniSerie valores={datos.platillos_entregados.serie.map((s) => s.platillos)} etiquetas={semanas(datos.platillos_entregados.serie)} color="#e9b51f" unidad="platillos" />
          <ul className="mt-3 space-y-1 text-xs">{datos.platillos_entregados.por_dia.map((d) => <li key={d.fecha} className="flex justify-between gap-2"><span className="text-muted-foreground">{etiquetaDiaCorta(d.fecha)}</span><strong className="tabular-nums">{d.platillos}</strong></li>)}{!datos.platillos_entregados.por_dia.length && <li className="text-muted-foreground">Todavía no hay entregas esta semana.</li>}</ul>
        </Tarjeta>

        <Tarjeta titulo="Ahorro estimado por empleado" icono={PiggyBank} acento="#43a86b" periodo={`Mes de ${periodo.mes}`}>
          <p className="font-display text-[2rem] font-bold leading-none tabular-nums">{formatMXN(ahorro.por_empleado_promedio)}</p>
          <p className="mt-1 text-xs text-muted-foreground">por empleado, frente a las apps de entrega ({ahorro.porcentaje_sobreprecio} % de sobreprecio estimado)</p>
          <p className="mt-3 text-xs"><strong className="tabular-nums">{formatMXN(ahorro.ahorro_total)}</strong> <span className="text-muted-foreground">entre {ahorro.empleados} empleados</span></p>
          <ul className="mt-3 space-y-1 text-xs">{(ahorro.por_empleado ?? []).slice(0, 4).map((e) => <li key={e.empleado_id} className="flex justify-between gap-2"><span className="truncate text-muted-foreground">{e.empleado}</span><strong className="tabular-nums">{formatMXN(e.ahorro)}</strong></li>)}</ul>
        </Tarjeta>

        <Tarjeta titulo="Estatus de facturación" icono={Receipt} acento="#3d7fe8" periodo="Tus cobros mensuales">
          <p className="text-sm"><strong className="font-display text-2xl tabular-nums">{datos.facturacion.pendientes}</strong> <span className="text-muted-foreground">pendiente{datos.facturacion.pendientes === 1 ? '' : 's'} de pago{datos.facturacion.pendientes ? ` · ${formatMXN(datos.facturacion.monto_pendiente)}` : ''}</span></p>
          <div className="mt-3"><ListaFacturas facturas={datos.facturacion.facturas.slice(0, 4)} /></div>
        </Tarjeta>

        <Tarjeta titulo="Calificación del menú" icono={Star} acento="#43a86b" periodo={`${semana} · sabor y calidad`}>
          <Estrellas valor={datos.calificacion_menu.promedio} tamano="size-5" />
          <p className="mt-1 text-xs text-muted-foreground">{datos.calificacion_menu.calificaciones} calificaciones de tu equipo</p>
          <MiniSerie valores={datos.calificacion_menu.serie.map((s) => s.promedio)} etiquetas={semanas(datos.calificacion_menu.serie)} color="#43a86b" unidad="estrellas" />
        </Tarjeta>

        <Tarjeta titulo="Calificación de la entrega" icono={Truck} acento="#3d7fe8" periodo={`${semana} · última milla`}>
          <Estrellas valor={datos.calificacion_entrega.promedio} tamano="size-5" />
          <p className="mt-1 text-xs text-muted-foreground">{datos.calificacion_entrega.calificaciones} calificaciones · puntualidad, trato y empaque</p>
          <div className="mt-3 space-y-1.5"><Aspecto nombre="Puntualidad" valor={datos.calificacion_entrega.puntualidad} /><Aspecto nombre="Trato del chofer" valor={datos.calificacion_entrega.trato_chofer} /><Aspecto nombre="Empaque" valor={datos.calificacion_entrega.empaque} /></div>
        </Tarjeta>

        <Tarjeta titulo="Incidencias o quejas" icono={TriangleAlert} acento="#d9822b" periodo={semana}>
          <p className="font-display text-[2rem] font-bold leading-none tabular-nums">{quejas.total}</p>
          <p className="mt-1 text-xs text-muted-foreground">{quejas.abiertas > 0 ? `${quejas.abiertas} sin resolver` : 'ninguna sin resolver'}</p>
          <ul className="mt-3 space-y-1 text-xs">{quejas.por_tipo.map((t) => <li key={t.tipo} className="flex justify-between gap-2"><span className="text-muted-foreground">{t.etiqueta}</span><strong className="tabular-nums">{t.cantidad}</strong></li>)}</ul>
          <MiniSerie valores={datos.incidencias.serie.map((s) => s.total)} etiquetas={semanas(datos.incidencias.serie)} color="#d9822b" unidad="quejas" />
        </Tarjeta>
      </div>
    </section>
  )
}
