'use client'

import { FormEvent, useState } from 'react'
import { ArrowRight, Building2, Eye, EyeOff, LockKeyhole, Mail, ShieldCheck } from 'lucide-react'
import Link from 'next/link'
import { iniciarSesionEnPortal } from '@/lib/auth'

export default function EmpresaLogin() {
  const [showPassword, setShowPassword] = useState(false)
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')

  async function submit(e: FormEvent) {
    e.preventDefault()
    setError('')
    setLoading(true)
    const resultado = await iniciarSesionEnPortal('empresas', email, password)
    if (resultado.estado === 'ok') { window.location.href = resultado.destino; return }
    setLoading(false)
    setError(resultado.mensaje)
  }

  return (
    <main className="min-h-screen bg-[#111c25] text-white lg:grid lg:grid-cols-[1.05fr_.95fr]">
      <section className="relative hidden min-h-screen overflow-hidden bg-[#e7b31c] p-10 lg:flex lg:flex-col lg:justify-between">
        <div className="relative z-10 flex items-center gap-3 text-[#101b24]">
          <div className="grid size-11 place-items-center rounded-2xl bg-[#101b24] text-[#e7b31c]"><Building2 /></div>
          <span className="font-display text-2xl font-extrabold">EasyLunch</span>
        </div>
        <div className="relative z-10 max-w-xl pb-12 text-[#101b24]">
          <p className="mb-5 text-sm font-bold uppercase tracking-[0.28em]">Portal de empresas</p>
          <h1 className="font-display text-5xl font-extrabold leading-[1.03] xl:text-6xl">Un almuerzo resuelto para todo tu equipo.</h1>
          <p className="mt-6 max-w-md text-lg leading-8 text-[#34424a]">Consulta los pedidos de mañana, administra a tus empleados y revisa tu consumo y facturas en un solo lugar.</p>
        </div>
        <p className="relative z-10 text-xs font-semibold uppercase tracking-widest text-[#34424a]">© 2026 EasyLunch · Comida corporativa</p>
      </section>

      <section className="flex min-h-screen items-center justify-center px-6 py-12 sm:px-10">
        <div className="w-full max-w-md">
          <div className="mb-10 flex items-center gap-3 lg:hidden">
            <div className="grid size-10 place-items-center rounded-xl bg-[#e7b31c] text-[#111c25]"><Building2 /></div>
            <span className="font-display text-2xl font-extrabold">EasyLunch</span>
          </div>
          <div className="mb-8">
            <p className="mb-3 text-sm font-semibold uppercase tracking-[0.2em] text-[#e7b31c]">Acceso para empresas</p>
            <h2 className="font-display text-4xl font-extrabold tracking-tight">Bienvenido a tu portal</h2>
            <p className="mt-3 text-base leading-7 text-slate-400">Ingresa con el correo del contacto de RR. HH. de tu empresa.</p>
          </div>
          <form className="flex flex-col gap-5" onSubmit={submit}>
            <label className="flex flex-col gap-2 text-sm font-semibold">
              Correo de RR. HH.
              <span className="relative">
                <Mail className="absolute left-4 top-1/2 size-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
                <input required type="email" value={email} onChange={(e) => setEmail(e.target.value)} placeholder="rrhh@tuempresa.mx" className="h-13 w-full rounded-xl border border-white/10 bg-white/[0.06] pl-11 pr-4 text-white outline-none placeholder:text-slate-600 focus:border-[#e7b31c] focus:ring-2 focus:ring-[#e7b31c]/20" />
              </span>
            </label>
            <label className="flex flex-col gap-2 text-sm font-semibold">
              Contraseña
              <span className="relative">
                <LockKeyhole className="absolute left-4 top-1/2 size-4 -translate-y-1/2 text-slate-500" aria-hidden="true" />
                <input required minLength={6} type={showPassword ? 'text' : 'password'} value={password} onChange={(e) => setPassword(e.target.value)} placeholder="Tu contraseña" className="h-13 w-full rounded-xl border border-white/10 bg-white/[0.06] pl-11 pr-12 text-white outline-none placeholder:text-slate-600 focus:border-[#e7b31c] focus:ring-2 focus:ring-[#e7b31c]/20" />
                <button type="button" onClick={() => setShowPassword(!showPassword)} className="absolute right-3 top-1/2 grid size-8 -translate-y-1/2 place-items-center rounded-lg text-slate-400 hover:bg-white/10 hover:text-white" aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}>{showPassword ? <EyeOff /> : <Eye />}</button>
              </span>
            </label>
            <div className="-mt-2 text-right text-sm"><Link href="/empresas/olvide-contrasena" className="font-semibold text-[#e7b31c] hover:text-[#f4ca4d]">¿Olvidaste tu contraseña?</Link></div>
            {error && <p role="alert" className="rounded-xl border border-red-400/30 bg-red-400/10 px-4 py-3 text-sm text-red-200">{error}</p>}
            <button disabled={loading} className="flex h-13 items-center justify-center gap-2 rounded-xl bg-[#e7b31c] font-bold text-[#111c25] transition hover:bg-[#f4ca4d] disabled:cursor-wait disabled:opacity-70">{loading ? 'Validando acceso...' : <>Entrar al portal <ArrowRight /></>}</button>
          </form>
          <div className="mt-10 flex gap-3 border-t border-white/10 pt-6 text-sm leading-6 text-slate-500"><ShieldCheck className="mt-1 size-4 shrink-0 text-[#e7b31c]" /><p>Acceso restringido al contacto de RR. HH. de empresas con contrato vigente.</p></div>
        </div>
      </section>
    </main>
  )
}
