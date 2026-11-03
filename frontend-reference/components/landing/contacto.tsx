'use client'

import { useState, type FormEvent } from 'react'
import { Check, CircleAlert, Clock3, Mail, MapPin, MessageCircle, Phone } from 'lucide-react'
import { enviarMensajeContacto } from '@/lib/api'
import { Encabezado, Magnetico, Reveal } from './ui'

type Campos = { nombre: string; correo: string; empresa: string; mensaje: string }
type Errores = Partial<Record<keyof Campos, string>>

const vacio: Campos = { nombre: '', correo: '', empresa: '', mensaje: '' }

function validar(c: Campos): Errores {
  const e: Errores = {}
  if (c.nombre.trim().length < 2) e.nombre = 'Escribe tu nombre.'
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(c.correo.trim())) e.correo = 'Escribe un correo válido.'
  if (c.mensaje.trim().length < 10) e.mensaje = 'Cuéntanos un poco más (mínimo 10 caracteres).'
  return e
}

const base = 'mt-2 w-full rounded-xl border bg-white/[0.06] px-4 py-3.5 text-base text-lcream placeholder:text-lcream/60 transition-colors focus:border-lgold'

function Campo({ id, etiqueta, error, opcional, children }: { id: string; etiqueta: string; error?: string; opcional?: boolean; children: React.ReactNode }) {
  return (
    <div>
      <label htmlFor={id} className="text-sm font-semibold text-lcream">{etiqueta}{opcional && <span className="ml-2 font-normal text-lcream/60">Opcional</span>}</label>
      {children}
      {error && <p id={`${id}-error`} role="alert" className="mt-1.5 flex items-center gap-1.5 text-sm text-red-300"><CircleAlert className="size-4 shrink-0" aria-hidden="true" />{error}</p>}
    </div>
  )
}

function Formulario() {
  const [campos, setCampos] = useState<Campos>(vacio)
  const [errores, setErrores] = useState<Errores>({})
  const [tocados, setTocados] = useState<Partial<Record<keyof Campos, boolean>>>({})
  const [enviando, setEnviando] = useState(false)
  const [falla, setFalla] = useState('')
  const [enviadoA, setEnviadoA] = useState<string | null>(null)

  const cambiar = (k: keyof Campos, v: string) => {
    const siguiente = { ...campos, [k]: v }
    setCampos(siguiente)
    if (tocados[k]) setErrores(validar(siguiente))
  }
  const salir = (k: keyof Campos) => { setTocados((t) => ({ ...t, [k]: true })); setErrores(validar(campos)) }
  const atributos = (k: keyof Campos) => ({ 'aria-invalid': !!errores[k], 'aria-describedby': errores[k] ? `${k}-error` : undefined, className: `${base} ${errores[k] ? 'border-red-300' : 'border-white/15'}` })

  async function enviar(e: FormEvent) {
    e.preventDefault()
    const errs = validar(campos)
    setErrores(errs)
    setTocados({ nombre: true, correo: true, mensaje: true })
    if (Object.keys(errs).length) return
    setFalla('')
    setEnviando(true)
    try {
      await enviarMensajeContacto(campos)
      setEnviadoA(campos.nombre.trim().split(' ')[0])
      setCampos(vacio)
      setTocados({})
    } catch (error) {
      setFalla(error instanceof Error ? error.message : 'No pudimos enviar tu mensaje. Intenta de nuevo.')
    } finally {
      setEnviando(false)
    }
  }

  if (enviadoA) {
    return (
      <div role="status" className="flex h-full min-h-[24rem] flex-col items-start justify-center gap-5 rounded-3xl border border-lgold/40 bg-lgold/[0.07] p-8">
        <span className="grid size-14 place-items-center rounded-2xl bg-lgold text-lbg"><Check className="size-7" aria-hidden="true" /></span>
        <h3 className="font-heading text-3xl text-lcream">¡Gracias, {enviadoA}!</h3>
        <p className="text-lg leading-7 text-lcream/80">Recibimos tu mensaje. Te respondemos en un día hábil.</p>
        <button onClick={() => setEnviadoA(null)} className="rounded-full border border-lcream/35 px-6 py-3 text-sm font-semibold text-lcream transition-colors hover:border-lgold hover:text-lgold">Enviar otro mensaje</button>
      </div>
    )
  }

  return (
    <form onSubmit={enviar} noValidate className="flex flex-col gap-5 rounded-3xl border border-white/10 bg-white/[0.04] p-6 sm:p-8">
      <Campo id="nombre" etiqueta="Nombre" error={errores.nombre}><input id="nombre" name="nombre" autoComplete="name" value={campos.nombre} onChange={(e) => cambiar('nombre', e.target.value)} onBlur={() => salir('nombre')} placeholder="Tu nombre" {...atributos('nombre')} /></Campo>
      <Campo id="correo" etiqueta="Correo" error={errores.correo}><input id="correo" name="correo" type="email" autoComplete="email" value={campos.correo} onChange={(e) => cambiar('correo', e.target.value)} onBlur={() => salir('correo')} placeholder="tu@empresa.mx" {...atributos('correo')} /></Campo>
      <Campo id="empresa" etiqueta="Empresa" opcional><input id="empresa" name="empresa" autoComplete="organization" value={campos.empresa} onChange={(e) => cambiar('empresa', e.target.value)} placeholder="Nombre de tu empresa" className={`${base} border-white/15`} /></Campo>
      <Campo id="mensaje" etiqueta="Mensaje" error={errores.mensaje}><textarea id="mensaje" name="mensaje" rows={4} value={campos.mensaje} onChange={(e) => cambiar('mensaje', e.target.value)} onBlur={() => salir('mensaje')} placeholder="¿Cuántas personas son y para cuándo lo necesitas?" {...atributos('mensaje')} /></Campo>
      {falla && <p role="alert" className="flex items-start gap-2 rounded-xl border border-red-300/40 bg-red-400/10 px-4 py-3 text-sm text-red-200"><CircleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />{falla}</p>}
      <Magnetico className="inline-block self-start"><button type="submit" disabled={enviando} className="inline-flex items-center justify-center rounded-full bg-lgold px-9 py-4 text-base font-semibold text-lbg shadow-[0_14px_40px_-12px_rgba(224,168,19,0.8)] transition-colors hover:bg-[#f0bb2c] disabled:cursor-wait disabled:opacity-70">{enviando ? 'Enviando…' : 'Enviar'}</button></Magnetico>
    </form>
  )
}

// Lo que va entre corchetes es un dato provisional por confirmar.
const datos = [
  { Icono: Mail, etiqueta: 'Correo', valor: '[correo@easylunch.mx]' },
  { Icono: Phone, etiqueta: 'Teléfono', valor: '[33 0000 0000]' },
  { Icono: MessageCircle, etiqueta: 'WhatsApp', valor: '[pon el número real]' },
  { Icono: MapPin, etiqueta: 'Ubicación', valor: 'Guadalajara, Jalisco' },
  { Icono: Clock3, etiqueta: 'Horario', valor: 'Lunes a viernes, [9:00 – 18:00]' },
]

export function Contacto() {
  return (
    <section id="contacto" className="relative mx-auto max-w-7xl px-5 py-24 md:py-32 lg:px-8">
      <div aria-hidden="true" className="pointer-events-none absolute left-1/2 top-1/3 -z-10 size-[40rem] -translate-x-1/2 rounded-full bg-[radial-gradient(closest-side,rgba(224,168,19,0.10),transparent)]" />
      <Encabezado eyebrow="Contacto" titulo={['Hablemos.']} />
      <div className="mt-12 grid gap-10 lg:grid-cols-[1.1fr_0.9fr] lg:gap-16">
        <Reveal><Formulario /></Reveal>
        <Reveal delay={0.12} className="lg:pt-4">
          <ul className="flex flex-col gap-7">
            {datos.map(({ Icono, etiqueta, valor }) => (
              <li key={etiqueta} className="flex items-start gap-4">
                <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-lgold/15 text-lgold"><Icono className="size-5" aria-hidden="true" /></span>
                <div><p className="text-sm font-semibold uppercase tracking-[0.16em] text-lcream/65">{etiqueta}</p><p className="mt-1 text-lg text-lcream">{valor}</p></div>
              </li>
            ))}
          </ul>
          <p className="mt-8 text-sm text-lcream/65">Los datos entre corchetes son provisionales.</p>
        </Reveal>
      </div>
    </section>
  )
}
