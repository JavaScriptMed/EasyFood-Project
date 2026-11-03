'use client'

import { Monitor, Moon, Sun } from 'lucide-react'
import { Toaster } from 'sonner'
import { useTema, type Tema } from '@/lib/tema'

const opciones: { valor: Tema; etiqueta: string; Icono: typeof Sun }[] = [
  { valor: 'claro', etiqueta: 'Modo claro', Icono: Sun },
  { valor: 'oscuro', etiqueta: 'Modo oscuro', Icono: Moon },
  { valor: 'auto', etiqueta: 'Automático (según tu sistema)', Icono: Monitor },
]

/** Selector de tema claro / oscuro / automático. Recuerda la elección en este navegador. */
export function SelectorTema({ className = '' }: { className?: string }) {
  const { tema, setTema } = useTema()
  return (
    <div role="radiogroup" aria-label="Tema de color" className={`inline-flex items-center gap-0.5 rounded-full border bg-muted/50 p-0.5 ${className}`}>
      {opciones.map(({ valor, etiqueta, Icono }) => (
        <button key={valor} role="radio" aria-checked={tema === valor} aria-label={etiqueta} title={etiqueta} onClick={() => setTema(valor)} className={`grid size-7 place-items-center rounded-full transition ${tema === valor ? 'bg-foreground text-background shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}>
          <Icono className="size-3.5" aria-hidden="true" />
        </button>
      ))}
    </div>
  )
}

/** Avisos (toasts) que siguen el tema elegido, no solo el del sistema. */
export function AvisosTema() {
  const { oscuro } = useTema()
  return <Toaster richColors position="bottom-right" theme={oscuro ? 'dark' : 'light'} />
}
