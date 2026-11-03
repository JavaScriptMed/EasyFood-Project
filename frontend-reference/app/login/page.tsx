'use client'

import { FormEvent, useState } from 'react'
import Link from 'next/link'
import { iniciarSesion } from '@/lib/sesion'
import { ArrowRight, Eye, EyeOff, LockKeyhole, Mail, ShieldCheck, Truck } from 'lucide-react'

export default function LoginPage() {
  const [showPassword, setShowPassword] = useState(false)
  const [remember, setRemember] = useState(true)
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [status, setStatus] = useState<'idle' | 'loading' | 'error' | 'locked'>('idle')

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setStatus('loading')
    window.setTimeout(() => {
      const normalizedEmail = email.toLowerCase()
      if (normalizedEmail.includes('bloqueado')) setStatus('locked')
      else if (normalizedEmail.includes('admin') || normalizedEmail.includes('soporte')) { iniciarSesion(normalizedEmail.includes('admin') ? 'administrador' : 'soporte'); window.location.href = '/admin' }
      else setStatus('error')
    }, 700)
  }

  return (
    <main className="min-h-screen bg-[#111c25] text-white lg:grid lg:grid-cols-[1.05fr_.95fr]">
      <section className="relative hidden min-h-screen overflow-hidden bg-[#e7b31c] p-10 lg:flex lg:flex-col lg:justify-between">
        <div className="absolute inset-0 opacity-30" aria-hidden="true">
          <span className="speed-line speed-line-one" />
          <span className="speed-line speed-line-two" />
          <span className="speed-line speed-line-three" />
          <span className="speed-line speed-line-four" />
        </div>
        <div className="relative z-10 flex items-center gap-3 text-[#101b24]">
          <div className="grid size-11 place-items-center rounded-2xl bg-[#101b24] text-[#e7b31c] shadow-lg"><Truck /></div>
          <span className="font-display text-2xl font-extrabold tracking-tight">EasyLunch</span>
        </div>
        <div className="relative z-10 max-w-xl pb-12 text-[#101b24]">
          <p className="mb-5 text-sm font-bold uppercase tracking-[0.28em]">Operación inteligente</p>
          <h1 className="font-display text-5xl font-extrabold leading-[1.03] xl:text-6xl">La comida de tu equipo, siempre en movimiento.</h1>
          <p className="mt-6 max-w-md text-lg leading-8 text-[#34424a]">Administra pedidos, rutas y entregas desde un solo lugar. Más control para tu operación y una mejor experiencia para cada persona.</p>
          <div className="mt-10 flex flex-wrap gap-3 text-sm font-semibold">
            <span className="rounded-full border border-[#101b24]/20 bg-white/20 px-4 py-2">Rutas en tiempo real</span>
            <span className="rounded-full border border-[#101b24]/20 bg-white/20 px-4 py-2">Menos desperdicio</span>
          </div>
        </div>
        <p className="relative z-10 text-xs font-semibold uppercase tracking-widest text-[#34424a]">© 2026 EasyLunch · Hecho para operar mejor</p>
      </section>

      <section className="flex min-h-screen items-center justify-center px-6 py-12 sm:px-10">
        <div className="w-full max-w-md">
          <div className="mb-10 flex items-center gap-3 lg:hidden">
            <div className="grid size-10 place-items-center rounded-xl bg-[#e7b31c] text-[#111c25]"><Truck /></div>
            <span className="font-display text-2xl font-extrabold">EasyLunch</span>
          </div>
          <div className="mb-8">
            <p className="mb-3 text-sm font-semibold uppercase tracking-[0.2em] text-[#e7b31c]">Panel administrativo</p>
            <h2 className="font-display text-4xl font-extrabold tracking-tight">Bienvenido de vuelta</h2>
            <p className="mt-3 text-base leading-7 text-slate-400">Ingresa tus datos para continuar con la operación de hoy.</p>
          </div>
          <form className="flex flex-col gap-5" onSubmit={handleSubmit}>
            <label className="flex flex-col gap-2 text-sm font-semibold" htmlFor="email">
              Correo electrónico
              <span className="relative">
                <Mail className="absolute left-4 top-1/2 size-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
                <input id="email" type="email" required value={email} onChange={(event) => setEmail(event.target.value)} placeholder="nombre@easylunch.mx" className="h-13 w-full rounded-xl border border-white/10 bg-white/[0.06] pl-11 pr-4 text-white outline-none transition placeholder:text-slate-600 focus:border-[#e7b31c] focus:ring-2 focus:ring-[#e7b31c]/20" />
              </span>
            </label>
            <label className="flex flex-col gap-2 text-sm font-semibold" htmlFor="password">
              Contraseña
              <span className="relative">
                <LockKeyhole className="absolute left-4 top-1/2 size-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
                <input id="password" type={showPassword ? 'text' : 'password'} required minLength={6} value={password} onChange={(event) => setPassword(event.target.value)} placeholder="Tu contraseña" className="h-13 w-full rounded-xl border border-white/10 bg-white/[0.06] pl-11 pr-12 text-white outline-none transition placeholder:text-slate-600 focus:border-[#e7b31c] focus:ring-2 focus:ring-[#e7b31c]/20" />
                <button type="button" onClick={() => setShowPassword(!showPassword)} className="absolute right-3 top-1/2 grid size-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:bg-white/10 hover:text-white" aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}>{showPassword ? <EyeOff /> : <Eye />}</button>
              </span>
            </label>
            <div className="flex items-center justify-between text-sm">
              <label className="flex cursor-pointer items-center gap-2 text-slate-400"><input type="checkbox" checked={remember} onChange={(event) => setRemember(event.target.checked)} className="size-4 accent-[#e7b31c]" /> Recordarme</label>
              <Link href="/admin/olvide-contrasena" className="font-semibold text-[#e7b31c] hover:text-[#f4ca4d]">¿Olvidaste tu contraseña?</Link>
            </div>
            {status === 'error' && <p role="alert" className="rounded-xl border border-red-400/30 bg-red-400/10 px-4 py-3 text-sm text-red-200">No pudimos validar tus datos. Revisa tu correo y contraseña.</p>}
            {status === 'locked' && <p role="alert" className="rounded-xl border border-orange-300/30 bg-orange-300/10 px-4 py-3 text-sm text-orange-100">Esta cuenta está bloqueada temporalmente. Contacta a soporte.</p>}
            <button type="submit" disabled={status === 'loading'} className="flex h-13 items-center justify-center gap-2 rounded-xl bg-[#e7b31c] px-5 font-bold text-[#111c25] transition hover:bg-[#f4ca4d] disabled:cursor-wait disabled:opacity-70">{status === 'loading' ? 'Validando acceso...' : 'Entrar al panel'} {!status && <ArrowRight />}</button>
          </form>
          <div className="mt-10 flex items-start gap-3 border-t border-white/10 pt-6 text-sm leading-6 text-slate-500"><ShieldCheck className="mt-1 size-4 shrink-0 text-[#e7b31c]" /><p>Tu información está protegida con controles de acceso seguros. Solo personal autorizado puede ingresar.</p></div>
        </div>
      </section>
    </main>
  )
}
