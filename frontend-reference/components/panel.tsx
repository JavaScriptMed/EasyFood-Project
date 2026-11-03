import type { ReactNode } from 'react'

/** Encabezado de página: raya dorada, antetítulo, título y descripción. Las acciones van a su lado en el contenedor. */
export function PageTitle({ eyebrow, title, description }: { eyebrow?: ReactNode; title: ReactNode; description?: ReactNode }) {
  return (
    <div className="min-w-0">
      {eyebrow && <p className="flex items-center gap-2.5 text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground"><span aria-hidden="true" className="h-px w-6 bg-primary" />{eyebrow}</p>}
      <h1 className="mt-2.5 font-display text-[1.75rem] font-bold leading-tight tracking-tight md:text-[2rem]">{title}</h1>
      {description && <p className="mt-1.5 max-w-2xl text-sm text-muted-foreground md:text-[0.9375rem]">{description}</p>}
    </div>
  )
}

type Tono = 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'brand'

const tonos: Record<Tono, { pill: string; punto: string }> = {
  neutral: { pill: 'bg-muted text-muted-foreground', punto: 'bg-muted-foreground/60' },
  success: { pill: 'bg-emerald-500/10 text-emerald-700', punto: 'bg-emerald-500' },
  warning: { pill: 'bg-amber-500/10 text-amber-700', punto: 'bg-amber-500' },
  danger: { pill: 'bg-destructive/10 text-destructive', punto: 'bg-destructive' },
  info: { pill: 'bg-sky-500/10 text-sky-700', punto: 'bg-sky-500' },
  brand: { pill: 'bg-primary/20 text-foreground', punto: 'bg-primary' },
}

/** Etiqueta de estado con punto de color. */
export function Pill({ tono = 'neutral', children, className = '' }: { tono?: Tono; children: ReactNode; className?: string }) {
  return (
    <span className={`inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold ${tonos[tono].pill} ${className}`}>
      <span aria-hidden="true" className={`size-1.5 rounded-full ${tonos[tono].punto}`} />{children}
    </span>
  )
}

/** Tarjeta de métrica: etiqueta en versalitas, cifra grande con números tabulares y nota. */
export function StatCard({ label, value, helper, tono, icon }: { label: string; value: ReactNode; helper?: ReactNode; tono?: 'success' | 'warning'; icon?: ReactNode }) {
  return (
    <div className="relative overflow-hidden rounded-2xl border bg-card p-5">
      <div className="flex items-start justify-between gap-3">
        <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">{label}</p>
        {icon && <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-primary/15 text-foreground [&_svg]:size-4">{icon}</span>}
      </div>
      <p className={`mt-3 font-display text-[1.75rem] font-bold leading-none tracking-tight tabular-nums ${tono === 'success' ? 'text-emerald-700' : tono === 'warning' ? 'text-amber-700' : ''}`}>{value}</p>
      {helper && <p className="mt-2 text-xs text-muted-foreground">{helper}</p>}
    </div>
  )
}
