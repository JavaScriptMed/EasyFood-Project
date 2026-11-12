'use client'

// Ventanas reutilizables del panel: modal de formulario (crear / editar), panel lateral (ver detalle) y confirmación
// (desactivar). Cada una cierra con Esc o al hacer clic fuera, salvo mientras se guarda.

import { useEffect, type FormEvent, type ReactNode } from 'react'
import { X } from 'lucide-react'
import { Button } from '@/components/ui/button'

function useCerrarConEsc(onCerrar: () => void, bloqueado?: boolean) {
  useEffect(() => {
    const alTeclear = (e: KeyboardEvent) => { if (e.key === 'Escape' && !bloqueado) onCerrar() }
    window.addEventListener('keydown', alTeclear)
    return () => window.removeEventListener('keydown', alTeclear)
  }, [onCerrar, bloqueado])
}

/** Modal con formulario: sirve para crear y para editar. `onEnviar` recibe el submit ya prevenido. */
export function VentanaFormulario({ titulo, descripcion, textoEnviar, trabajando, deshabilitarEnvio, onCerrar, onEnviar, ancho = 'max-w-md', children }: {
  titulo: string; descripcion?: string; textoEnviar: string; trabajando?: boolean; deshabilitarEnvio?: boolean
  onCerrar: () => void; onEnviar: () => void; ancho?: string; children: ReactNode
}) {
  useCerrarConEsc(onCerrar, trabajando)
  function enviar(e: FormEvent) { e.preventDefault(); onEnviar() }
  return (
    <div className="fixed inset-0 z-[70] grid place-items-center overflow-y-auto bg-black/40 p-4" onClick={() => !trabajando && onCerrar()}>
      <form role="dialog" aria-modal="true" aria-label={titulo} onSubmit={enviar} onClick={(e) => e.stopPropagation()} className={`w-full ${ancho} rounded-2xl bg-card p-6 shadow-xl`}>
        <div className="flex items-start justify-between gap-3">
          <div><h2 className="font-display text-xl font-bold">{titulo}</h2>{descripcion && <p className="mt-1 text-sm text-muted-foreground">{descripcion}</p>}</div>
          <button type="button" onClick={onCerrar} disabled={trabajando} aria-label="Cerrar"><X /></button>
        </div>
        <div className="mt-5 flex flex-col gap-4">{children}</div>
        <div className="mt-6 flex justify-end gap-2">
          <Button type="button" variant="outline" className="h-10 px-4" onClick={onCerrar} disabled={trabajando}>Cancelar</Button>
          <Button type="submit" className="h-10 px-4" disabled={trabajando || deshabilitarEnvio}>{trabajando ? 'Guardando…' : textoEnviar}</Button>
        </div>
      </form>
    </div>
  )
}

/** Confirmación antes de desactivar (en EasyLunch nada se borra: se desactiva y conserva su historial). */
export function VentanaConfirmar({ titulo, descripcion, textoConfirmar, trabajando, peligro = true, onCerrar, onConfirmar }: {
  titulo: string; descripcion: ReactNode; textoConfirmar: string; trabajando?: boolean; peligro?: boolean; onCerrar: () => void; onConfirmar: () => void
}) {
  useCerrarConEsc(onCerrar, trabajando)
  return (
    <div className="fixed inset-0 z-[80] grid place-items-center bg-black/40 p-4" onClick={() => !trabajando && onCerrar()}>
      <div role="alertdialog" aria-modal="true" aria-label={titulo} onClick={(e) => e.stopPropagation()} className="w-full max-w-sm rounded-2xl bg-card p-6 shadow-xl">
        <h2 className="font-display text-xl font-bold">{titulo}</h2>
        <div className="mt-2 text-sm text-muted-foreground">{descripcion}</div>
        <div className="mt-6 flex justify-end gap-2">
          <Button variant="outline" className="h-10 px-4" onClick={onCerrar} disabled={trabajando}>Cancelar</Button>
          <Button variant={peligro ? 'destructive' : 'default'} className="h-10 px-4" onClick={onConfirmar} disabled={trabajando}>{trabajando ? 'Un momento…' : textoConfirmar}</Button>
        </div>
      </div>
    </div>
  )
}

/** Panel lateral de solo lectura: el "ver detalle" de cada registro. `acciones` va al pie. */
export function PanelDetalle({ etiqueta, titulo, onCerrar, acciones, children }: { etiqueta?: string; titulo: string; onCerrar: () => void; acciones?: ReactNode; children: ReactNode }) {
  useCerrarConEsc(onCerrar)
  return (
    <div className="fixed inset-0 z-50 flex justify-end bg-black/35" onClick={onCerrar}>
      <aside role="dialog" aria-modal="true" aria-label={`Detalle: ${titulo}`} className="flex h-full w-full max-w-xl flex-col overflow-y-auto bg-card p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="flex items-start justify-between gap-3">
          <div>{etiqueta && <p className="text-sm text-primary">{etiqueta}</p>}<h2 className="font-display text-2xl font-bold">{titulo}</h2></div>
          <button onClick={onCerrar} aria-label="Cerrar"><X /></button>
        </div>
        <div className="mt-6 flex-1">{children}</div>
        {acciones && <div className="mt-6 flex flex-wrap justify-end gap-2 border-t pt-4">{acciones}</div>}
      </aside>
    </div>
  )
}

/** Cuadrícula de datos "etiqueta → valor" para los paneles de detalle. */
export const GrillaDatos = ({ datos }: { datos: { etiqueta: string; valor: ReactNode }[] }) => (
  <dl className="grid gap-3 sm:grid-cols-2">
    {datos.map((d) => <div key={d.etiqueta} className="rounded-lg border p-3"><dt className="text-xs uppercase text-muted-foreground">{d.etiqueta}</dt><dd className="mt-1 text-sm font-semibold">{d.valor || '—'}</dd></div>)}
  </dl>
)

const claseCampo = 'mt-1 w-full rounded-lg border bg-background px-3 font-normal outline-none focus:border-primary'

export const CampoTexto = ({ etiqueta, ayuda, className = '', ...props }: { etiqueta: string; ayuda?: string } & React.InputHTMLAttributes<HTMLInputElement>) => (
  <label className="text-sm font-medium">{etiqueta}<input {...props} className={`${claseCampo} h-11 ${className}`} />{ayuda && <span className="mt-1 block text-xs font-normal text-muted-foreground">{ayuda}</span>}</label>
)

export const CampoParrafo = ({ etiqueta, className = '', ...props }: { etiqueta: string } & React.TextareaHTMLAttributes<HTMLTextAreaElement>) => (
  <label className="text-sm font-medium">{etiqueta}<textarea rows={3} {...props} className={`${claseCampo} py-2 ${className}`} /></label>
)

export const CampoLista = ({ etiqueta, opciones, ...props }: { etiqueta: string; opciones: { valor: string; texto: string }[] } & React.SelectHTMLAttributes<HTMLSelectElement>) => (
  <label className="text-sm font-medium">{etiqueta}<select {...props} className={`${claseCampo} h-11`}>{opciones.map((o) => <option key={o.valor} value={o.valor}>{o.texto}</option>)}</select></label>
)
