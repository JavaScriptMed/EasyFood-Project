'use client'

import type { ComponentProps, ReactNode } from 'react'
import { useState } from 'react'
import { Building2, Check, CircleAlert, Eye, EyeOff, Info, LockKeyhole, ShieldCheck, Truck, Utensils, UtensilsCrossed } from 'lucide-react'
import { evaluarContrasena } from '@/lib/contrasena'
import type { Portal } from '@/lib/types'

const marca = {
  admin: { Icono: Truck, eyebrow: 'Operación inteligente', titulo: 'La comida de tu equipo, siempre en movimiento.', texto: 'Administra pedidos, rutas y entregas desde un solo lugar. Más control para tu operación y una mejor experiencia para cada persona.', pie: '© 2026 EasyLunch · Hecho para operar mejor' },
  comedores: { Icono: Utensils, eyebrow: 'Portal de comedores', titulo: 'Tu cocina, conectada a más clientes.', texto: 'Publica tus platillos, organiza la producción y recibe tus órdenes diarias sin perseguir mensajes.', pie: '© 2026 EasyLunch · Operación para comedores' },
  empleados: { Icono: UtensilsCrossed, eyebrow: 'Portal de empleados', titulo: 'Elige tu comida en un minuto.', texto: 'Mira el menú de mañana, escoge tu platillo antes del corte y revisa cuánto cubre tu empresa.', pie: '© 2026 EasyLunch · Tu comida de cada día' },
  empresas: { Icono: Building2, eyebrow: 'Portal de empresas', titulo: 'Un almuerzo resuelto para todo tu equipo.', texto: 'Consulta los pedidos de mañana, administra a tus empleados y revisa tu consumo y facturas en un solo lugar.', pie: '© 2026 EasyLunch · Comida corporativa' },
} satisfies Record<Portal, { Icono: typeof Truck; eyebrow: string; titulo: string; texto: string; pie: string }>

/** Pantalla dividida de las cuentas: marca a la izquierda y el formulario a la derecha, igual que el login. */
export function AuthShell({ portal, eyebrow, titulo, descripcion, children }: { portal: Portal; eyebrow: string; titulo: string; descripcion?: string; children: ReactNode }) {
  const { Icono, ...texto } = marca[portal]
  return (
    <main className="min-h-screen bg-[#111c25] text-white lg:grid lg:grid-cols-[1.05fr_.95fr]">
      <section className="relative hidden min-h-screen overflow-hidden bg-[#e7b31c] p-10 lg:flex lg:flex-col lg:justify-between">
        <div aria-hidden="true" className="absolute -right-24 -top-24 size-[28rem] rounded-full bg-white/10" />
        <div aria-hidden="true" className="absolute -bottom-32 -left-20 size-[26rem] rounded-full bg-[#101b24]/[0.06]" />
        <div className="relative z-10 flex items-center gap-3 text-[#101b24]">
          <div className="grid size-11 place-items-center rounded-2xl bg-[#101b24] text-[#e7b31c] shadow-lg"><Icono /></div>
          <span className="font-display text-2xl font-extrabold tracking-tight">EasyLunch</span>
        </div>
        <div className="relative z-10 max-w-xl pb-12 text-[#101b24]">
          <p className="mb-5 text-sm font-bold uppercase tracking-[0.28em]">{texto.eyebrow}</p>
          <h1 className="font-display text-5xl font-extrabold leading-[1.03] xl:text-6xl">{texto.titulo}</h1>
          <p className="mt-6 max-w-md text-lg leading-8 text-[#34424a]">{texto.texto}</p>
        </div>
        <p className="relative z-10 text-xs font-semibold uppercase tracking-widest text-[#34424a]">{texto.pie}</p>
      </section>

      <section className="flex min-h-screen items-center justify-center px-6 py-12 sm:px-10">
        <div className="w-full max-w-md">
          <div className="mb-10 flex items-center gap-3 lg:hidden">
            <div className="grid size-10 place-items-center rounded-xl bg-[#e7b31c] text-[#111c25]"><Icono /></div>
            <span className="font-display text-2xl font-extrabold">EasyLunch</span>
          </div>
          <div className="mb-8">
            <p className="mb-3 text-sm font-semibold uppercase tracking-[0.2em] text-[#e7b31c]">{eyebrow}</p>
            <h2 className="font-display text-4xl font-extrabold tracking-tight">{titulo}</h2>
            {descripcion && <p className="mt-3 text-base leading-7 text-slate-400">{descripcion}</p>}
          </div>
          {children}
          <div className="mt-10 flex items-start gap-3 border-t border-white/10 pt-6 text-sm leading-6 text-slate-500"><ShieldCheck className="mt-1 size-4 shrink-0 text-[#e7b31c]" /><p>Tu información está protegida con controles de acceso seguros. Solo personal autorizado puede ingresar.</p></div>
        </div>
      </section>
    </main>
  )
}

const entrada = 'h-13 w-full rounded-xl border border-white/10 bg-white/[0.06] pl-11 pr-4 text-white outline-none transition placeholder:text-slate-600 focus:border-[#e7b31c] focus:ring-2 focus:ring-[#e7b31c]/20 read-only:cursor-default read-only:text-slate-300'

export function CampoAuth({ id, label, icono: Icono, ...props }: { id: string; label: string; icono: typeof LockKeyhole } & ComponentProps<'input'>) {
  return (
    <label className="flex flex-col gap-2 text-sm font-semibold" htmlFor={id}>
      {label}
      <span className="relative"><Icono className="absolute left-4 top-1/2 size-4 -translate-y-1/2 text-slate-500" aria-hidden="true" /><input id={id} className={entrada} {...props} /></span>
    </label>
  )
}

export function CampoContrasena({ id, label, conMedidor, ...props }: { id: string; label: string; conMedidor?: boolean } & Omit<ComponentProps<'input'>, 'type'>) {
  const [ver, setVer] = useState(false)
  return (
    <div className="flex flex-col gap-2">
      <label className="flex flex-col gap-2 text-sm font-semibold" htmlFor={id}>
        {label}
        <span className="relative">
          <LockKeyhole className="absolute left-4 top-1/2 size-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
          <input id={id} type={ver ? 'text' : 'password'} className={`${entrada} pr-12`} {...props} />
          <button type="button" onClick={() => setVer(!ver)} className="absolute right-3 top-1/2 grid size-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:bg-white/10 hover:text-white" aria-label={ver ? 'Ocultar contraseña' : 'Mostrar contraseña'}>{ver ? <EyeOff /> : <Eye />}</button>
        </span>
      </label>
      {conMedidor && <MedidorSeguridad valor={String(props.value ?? '')} />}
    </div>
  )
}

const coloresMedidor = ['bg-red-400', 'bg-red-400', 'bg-amber-400', 'bg-lime-400', 'bg-emerald-400']

export function MedidorSeguridad({ valor }: { valor: string }) {
  const { puntaje, etiqueta, requisitos } = evaluarContrasena(valor)
  return (
    <div className="mt-1" aria-live="polite">
      <div className="flex items-center gap-3">
        <div className="flex flex-1 gap-1.5" role="meter" aria-label="Seguridad de la contraseña" aria-valuemin={0} aria-valuemax={4} aria-valuenow={puntaje} aria-valuetext={valor ? etiqueta : 'Sin contraseña'}>
          {[1, 2, 3, 4].map((n) => <span key={n} className={`h-1.5 flex-1 rounded-full transition-colors ${valor && puntaje >= n ? coloresMedidor[puntaje] : 'bg-white/10'}`} />)}
        </div>
        <span className="w-20 text-right text-xs font-semibold text-slate-400">{valor ? etiqueta : ''}</span>
      </div>
      <ul className="mt-3 grid grid-cols-1 gap-1.5 text-xs sm:grid-cols-2">
        {requisitos.map((r) => <li key={r.texto} className={`flex items-center gap-2 ${r.ok ? 'text-emerald-300' : 'text-slate-500'}`}><Check className={`size-3.5 ${r.ok ? '' : 'opacity-30'}`} aria-hidden="true" />{r.texto}</li>)}
      </ul>
    </div>
  )
}

export function BotonAuth({ cargando, textoCargando, children, ...props }: { cargando?: boolean; textoCargando?: string } & ComponentProps<'button'>) {
  return <button {...props} disabled={cargando || props.disabled} className="flex h-13 w-full items-center justify-center gap-2 rounded-xl bg-[#e7b31c] px-5 font-bold text-[#111c25] transition hover:bg-[#f4ca4d] disabled:cursor-not-allowed disabled:opacity-60">{cargando ? textoCargando ?? 'Procesando…' : children}</button>
}

export function AvisoAuth({ tono = 'error', children }: { tono?: 'error' | 'exito' | 'info'; children: ReactNode }) {
  const estilos = { error: 'border-red-400/30 bg-red-400/10 text-red-200', exito: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-200', info: 'border-sky-400/30 bg-sky-400/10 text-sky-200' }[tono]
  const Icono = tono === 'error' ? CircleAlert : tono === 'exito' ? Check : Info
  return <p role={tono === 'error' ? 'alert' : 'status'} className={`flex items-start gap-2.5 rounded-xl border px-4 py-3 text-sm ${estilos}`}><Icono className="mt-0.5 size-4 shrink-0" aria-hidden="true" />{children}</p>
}

/** Bloque de estado final (éxito, enlace vencido, 403, sesión expirada) con icono y acciones. */
export function EstadoAuth({ icono: Icono, tono = 'neutral', children }: { icono: typeof LockKeyhole; tono?: 'neutral' | 'exito' | 'alerta'; children: ReactNode }) {
  const chip = { neutral: 'bg-white/10 text-white', exito: 'bg-emerald-400/15 text-emerald-300', alerta: 'bg-[#e7b31c]/15 text-[#e7b31c]' }[tono]
  return <div className="flex flex-col gap-5"><div className={`grid size-14 place-items-center rounded-2xl ${chip}`}><Icono className="size-7" aria-hidden="true" /></div>{children}</div>
}

export const enlaceSecundario = 'text-sm font-semibold text-[#e7b31c] hover:text-[#f4ca4d]'
export const botonSecundario = 'flex h-13 w-full items-center justify-center rounded-xl border border-white/15 px-5 font-semibold text-white transition hover:bg-white/10'
