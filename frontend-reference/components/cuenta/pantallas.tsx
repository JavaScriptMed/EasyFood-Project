'use client'

import Link from 'next/link'
import { useSearchParams } from 'next/navigation'
import { Suspense, useEffect, useState, type FormEvent } from 'react'
import { ArrowRight, Ban, ClockAlert, KeyRound, Mail, MailCheck, ShieldCheck, UserRound } from 'lucide-react'
import { aceptarInvitacion, obtenerInvitacionPublica, restablecerContrasena, solicitarRestablecimiento, validarTokenRestablecimiento } from '@/lib/api'
import { evaluarContrasena } from '@/lib/contrasena'
import { etiquetasRol } from '@/lib/etiquetas'
import { PORTALES, portalDeRol, rutaCuenta } from '@/lib/portales'
import { cerrarSesion, iniciarSesion, leerSesion } from '@/lib/sesion'
import type { Portal } from '@/lib/types'
import { useAsync } from '@/lib/use-async'
import { Skeleton } from '@/components/ui/skeleton'
import { AuthShell, AvisoAuth, BotonAuth, botonSecundario, CampoAuth, CampoContrasena, enlaceSecundario, EstadoAuth } from './auth-shell'

const mensajeError = (e: unknown, defecto: string) => (e instanceof Error ? e.message : defecto)

const CargandoAuth = () => <div className="flex flex-col gap-4" aria-hidden="true"><Skeleton className="h-14 w-14 rounded-2xl bg-white/10" /><Skeleton className="h-5 w-3/4 bg-white/10" /><Skeleton className="h-13 w-full rounded-xl bg-white/10" /><Skeleton className="h-13 w-full rounded-xl bg-white/10" /></div>

// ---------------------------------------------------------------- ¿Olvidaste tu contraseña?

export function OlvidePasswordScreen({ portal }: { portal: Portal }) {
  const [correo, setCorreo] = useState('')
  const [enviando, setEnviando] = useState(false)
  const [error, setError] = useState('')
  const [enlace, setEnlace] = useState<string | null | undefined>(undefined)

  async function enviar(e: FormEvent) {
    e.preventDefault()
    setError('')
    setEnviando(true)
    try {
      const respuesta = await solicitarRestablecimiento(correo)
      setEnlace(respuesta.enlace_simulado)
    } catch (err) {
      setError(mensajeError(err, 'No pudimos enviar el enlace. Intenta de nuevo.'))
    } finally {
      setEnviando(false)
    }
  }

  if (enlace !== undefined) {
    return (
      <AuthShell portal={portal} eyebrow="Revisa tu bandeja" titulo="Te enviamos un enlace">
        <EstadoAuth icono={MailCheck} tono="exito">
          <p className="leading-7 text-slate-300">Si <strong className="text-white">{correo}</strong> corresponde a una cuenta, recibirás un correo con las instrucciones para crear una contraseña nueva. El enlace vence en 60 minutos.</p>
          {enlace && <p className="rounded-xl border border-dashed border-white/15 p-4 text-sm text-slate-400"><span className="mb-1 block text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Solo maqueta</span>Aquí llegaría el correo. <Link href={enlace} className={enlaceSecundario}>Abrir el enlace del correo</Link></p>}
          <Link href={PORTALES[portal].login} className={botonSecundario}>Volver a iniciar sesión</Link>
          <button type="button" onClick={() => { setEnlace(undefined); setCorreo('') }} className={`${enlaceSecundario} text-center`}>Usar otro correo</button>
        </EstadoAuth>
      </AuthShell>
    )
  }

  return (
    <AuthShell portal={portal} eyebrow="Recupera tu acceso" titulo="¿Olvidaste tu contraseña?" descripcion="Escribe el correo de tu cuenta y te enviaremos un enlace para crear una contraseña nueva.">
      <form className="flex flex-col gap-5" onSubmit={enviar} noValidate>
        <CampoAuth id="correo" label="Correo electrónico" icono={Mail} type="email" required autoFocus autoComplete="email" value={correo} onChange={(e) => setCorreo(e.target.value)} placeholder="nombre@empresa.mx" />
        {error && <AvisoAuth>{error}</AvisoAuth>}
        <BotonAuth type="submit" cargando={enviando} textoCargando="Enviando enlace…">Enviar enlace <ArrowRight /></BotonAuth>
        <Link href={PORTALES[portal].login} className={`${enlaceSecundario} text-center`}>Volver a iniciar sesión</Link>
      </form>
    </AuthShell>
  )
}

// ---------------------------------------------------------------- Restablecer contraseña

export function RestablecerPasswordScreen({ portal }: { portal: Portal }) {
  return <Suspense fallback={<AuthShell portal={portal} eyebrow="Recupera tu acceso" titulo="Nueva contraseña"><CargandoAuth /></AuthShell>}><RestablecerContenido portal={portal} /></Suspense>
}

function RestablecerContenido({ portal }: { portal: Portal }) {
  const token = useSearchParams().get('token') ?? ''
  const validacion = useAsync(() => (token ? validarTokenRestablecimiento(token) : Promise.resolve({ valido: false, motivo: 'invalido' as const })), [token])
  const [clave, setClave] = useState('')
  const [confirmacion, setConfirmacion] = useState('')
  const [enviando, setEnviando] = useState(false)
  const [error, setError] = useState('')
  const [listo, setListo] = useState(false)
  const { valida } = evaluarContrasena(clave)
  const noCoinciden = confirmacion.length > 0 && clave !== confirmacion

  async function guardar(e: FormEvent) {
    e.preventDefault()
    if (!valida || clave !== confirmacion) return
    setError('')
    setEnviando(true)
    try {
      await restablecerContrasena(token, clave)
      setListo(true)
    } catch (err) {
      setError(mensajeError(err, 'No pudimos guardar tu contraseña. Intenta de nuevo.'))
    } finally {
      setEnviando(false)
    }
  }

  if (listo) {
    return (
      <AuthShell portal={portal} eyebrow="Todo listo" titulo="Contraseña actualizada">
        <EstadoAuth icono={ShieldCheck} tono="exito">
          <AvisoAuth tono="exito">Tu contraseña se cambió correctamente. Ya puedes iniciar sesión con la nueva.</AvisoAuth>
          <Link href={PORTALES[portal].login} className="flex h-13 w-full items-center justify-center gap-2 rounded-xl bg-[#e7b31c] px-5 font-bold text-[#111c25] transition hover:bg-[#f4ca4d]">Iniciar sesión <ArrowRight className="size-4" /></Link>
        </EstadoAuth>
      </AuthShell>
    )
  }

  if (validacion.loading) return <AuthShell portal={portal} eyebrow="Recupera tu acceso" titulo="Nueva contraseña"><CargandoAuth /></AuthShell>

  if (validacion.error) {
    return (
      <AuthShell portal={portal} eyebrow="Recupera tu acceso" titulo="No pudimos verificar el enlace">
        <EstadoAuth icono={Ban} tono="alerta">
          <AvisoAuth>{validacion.error.message}</AvisoAuth>
          <BotonAuth type="button" onClick={() => validacion.reload()}>Reintentar</BotonAuth>
        </EstadoAuth>
      </AuthShell>
    )
  }

  if (!validacion.data?.valido) {
    const motivo = validacion.data?.motivo
    return (
      <AuthShell portal={portal} eyebrow="Enlace no válido" titulo={motivo === 'vencido' ? 'Este enlace venció' : motivo === 'usado' ? 'Este enlace ya se usó' : 'Este enlace no es válido'}>
        <EstadoAuth icono={ClockAlert} tono="alerta">
          <p className="leading-7 text-slate-300">{motivo === 'vencido' ? 'Los enlaces para restablecer la contraseña duran 60 minutos.' : motivo === 'usado' ? 'Cada enlace solo sirve una vez.' : 'Revisa que hayas abierto el enlace completo del correo.'} Solicita uno nuevo para continuar.</p>
          <Link href={rutaCuenta(portal, 'olvide-contrasena')} className="flex h-13 w-full items-center justify-center gap-2 rounded-xl bg-[#e7b31c] px-5 font-bold text-[#111c25] transition hover:bg-[#f4ca4d]">Solicitar un enlace nuevo <ArrowRight className="size-4" /></Link>
          <Link href={PORTALES[portal].login} className={`${enlaceSecundario} text-center`}>Volver a iniciar sesión</Link>
        </EstadoAuth>
      </AuthShell>
    )
  }

  return (
    <AuthShell portal={portal} eyebrow="Recupera tu acceso" titulo="Restablecer contraseña" descripcion={`Crea una contraseña nueva para ${validacion.data.correo_oculto}.`}>
      <form className="flex flex-col gap-5" onSubmit={guardar} noValidate>
        <CampoContrasena id="nueva" label="Nueva contraseña" conMedidor autoFocus autoComplete="new-password" value={clave} onChange={(e) => setClave(e.target.value)} placeholder="Mínimo 8 caracteres" />
        <div className="flex flex-col gap-2">
          <CampoContrasena id="confirmacion" label="Confirmar contraseña" autoComplete="new-password" value={confirmacion} onChange={(e) => setConfirmacion(e.target.value)} placeholder="Repite la contraseña" aria-invalid={noCoinciden} />
          {noCoinciden && <p role="alert" className="text-xs font-medium text-red-300">Las contraseñas no coinciden.</p>}
        </div>
        {error && <AvisoAuth>{error}</AvisoAuth>}
        <BotonAuth type="submit" cargando={enviando} textoCargando="Guardando…" disabled={!valida || clave !== confirmacion}>Guardar contraseña <ArrowRight /></BotonAuth>
      </form>
    </AuthShell>
  )
}

// ---------------------------------------------------------------- Aceptar invitación

export function AceptarInvitacionScreen({ portal }: { portal: Portal }) {
  return <Suspense fallback={<AuthShell portal={portal} eyebrow="Invitación" titulo="Aceptar invitación"><CargandoAuth /></AuthShell>}><InvitacionContenido portal={portal} /></Suspense>
}

const tipoOrganizacion = { comedor: 'Comedor', empresa: 'Empresa', plataforma: 'Plataforma' } as const

function InvitacionContenido({ portal }: { portal: Portal }) {
  const token = useSearchParams().get('token') ?? ''
  const invitacion = useAsync(() => obtenerInvitacionPublica(token), [token])
  const [clave, setClave] = useState('')
  const [confirmacion, setConfirmacion] = useState('')
  const [enviando, setEnviando] = useState(false)
  const [error, setError] = useState('')
  const [entrada, setEntrada] = useState<{ nombre: string; organizacion: string; destino: string | null } | null>(null)
  const { valida } = evaluarContrasena(clave)
  const noCoinciden = confirmacion.length > 0 && clave !== confirmacion

  async function aceptar(e: FormEvent) {
    e.preventDefault()
    if (!valida || clave !== confirmacion) return
    setError('')
    setEnviando(true)
    try {
      const r = await aceptarInvitacion(token, clave)
      const destino = portalDeRol(r.rol)
      if (destino) iniciarSesion(r.rol)
      setEntrada({ nombre: r.nombre, organizacion: r.organizacion_nombre, destino: destino ? PORTALES[destino].inicio : null })
      if (destino) window.setTimeout(() => { window.location.href = PORTALES[destino].inicio }, 1400)
    } catch (err) {
      setError(mensajeError(err, 'No pudimos aceptar la invitación. Intenta de nuevo.'))
    } finally {
      setEnviando(false)
    }
  }

  if (entrada) {
    return (
      <AuthShell portal={portal} eyebrow="Cuenta creada" titulo={`¡Bienvenido, ${entrada.nombre.split(' ')[0]}!`}>
        <EstadoAuth icono={ShieldCheck} tono="exito">
          <AvisoAuth tono="exito">Tu cuenta en {entrada.organizacion} está lista.</AvisoAuth>
          {entrada.destino ? (
            <><p className="text-slate-400">Te estamos llevando a tu portal…</p><Link href={entrada.destino} className="flex h-13 w-full items-center justify-center gap-2 rounded-xl bg-[#e7b31c] px-5 font-bold text-[#111c25] transition hover:bg-[#f4ca4d]">Entrar ahora <ArrowRight className="size-4" /></Link></>
          ) : (
            <p className="leading-7 text-slate-300">Tu contraseña quedó guardada. Elegirás tu platillo desde la app de EasyLunch en cuanto tu empresa abra el servicio.</p>
          )}
        </EstadoAuth>
      </AuthShell>
    )
  }

  if (invitacion.loading) return <AuthShell portal={portal} eyebrow="Invitación" titulo="Aceptar invitación"><CargandoAuth /></AuthShell>

  if (invitacion.error) {
    return (
      <AuthShell portal={portal} eyebrow="Invitación" titulo="No pudimos cargar la invitación">
        <EstadoAuth icono={Ban} tono="alerta"><AvisoAuth>{invitacion.error.message}</AvisoAuth><BotonAuth type="button" onClick={() => invitacion.reload()}>Reintentar</BotonAuth></EstadoAuth>
      </AuthShell>
    )
  }

  const datos = invitacion.data
  if (!datos || datos.estado !== 'enviada') {
    const estado: 'invalida' | 'vencida' | 'aceptada' = datos?.estado === 'vencida' || datos?.estado === 'aceptada' ? datos.estado : 'invalida'
    const textos = {
      invalida: ['Esta invitación no es válida', 'Revisa que hayas abierto el enlace completo del correo o pide que te envíen otra invitación.'],
      vencida: ['Tu invitación venció', `Las invitaciones duran 7 días. Pide a quien te invitó${datos?.organizacion_nombre ? ` a ${datos.organizacion_nombre}` : ''} que te la reenvíe.`],
      aceptada: ['Esta invitación ya fue aceptada', 'Tu cuenta ya está activa. Inicia sesión con tu correo y tu contraseña.'],
    }[estado]
    return (
      <AuthShell portal={portal} eyebrow="Invitación" titulo={textos[0]}>
        <EstadoAuth icono={estado === 'aceptada' ? ShieldCheck : ClockAlert} tono={estado === 'aceptada' ? 'exito' : 'alerta'}>
          <p className="leading-7 text-slate-300">{textos[1]}</p>
          <Link href={PORTALES[datos && estado !== 'invalida' ? datos.portal : portal].login} className="flex h-13 w-full items-center justify-center gap-2 rounded-xl bg-[#e7b31c] px-5 font-bold text-[#111c25] transition hover:bg-[#f4ca4d]">Ir a iniciar sesión <ArrowRight className="size-4" /></Link>
        </EstadoAuth>
      </AuthShell>
    )
  }

  return (
    <AuthShell portal={datos.portal} eyebrow={`Invitación · ${tipoOrganizacion[datos.organizacion_tipo]}`} titulo={`Te invitaron a ${datos.organizacion_nombre}`} descripcion={`Entrarás como ${etiquetasRol[datos.rol].toLowerCase()}. Crea tu contraseña para activar tu cuenta.`}>
      <form className="flex flex-col gap-5" onSubmit={aceptar} noValidate>
        <CampoAuth id="nombre" label="Nombre" icono={UserRound} readOnly value={datos.nombre_invitado} />
        <CampoAuth id="correo" label="Correo" icono={Mail} readOnly value={datos.correo} />
        <CampoContrasena id="clave" label="Crea tu contraseña" conMedidor autoFocus autoComplete="new-password" value={clave} onChange={(e) => setClave(e.target.value)} placeholder="Mínimo 8 caracteres" />
        <div className="flex flex-col gap-2">
          <CampoContrasena id="confirmacion" label="Confirmar contraseña" autoComplete="new-password" value={confirmacion} onChange={(e) => setConfirmacion(e.target.value)} placeholder="Repite la contraseña" aria-invalid={noCoinciden} />
          {noCoinciden && <p role="alert" className="text-xs font-medium text-red-300">Las contraseñas no coinciden.</p>}
        </div>
        {error && <AvisoAuth>{error}</AvisoAuth>}
        <BotonAuth type="submit" cargando={enviando} textoCargando="Creando tu cuenta…" disabled={!valida || clave !== confirmacion}>Crear contraseña y entrar <ArrowRight /></BotonAuth>
      </form>
    </AuthShell>
  )
}

// ---------------------------------------------------------------- 403 y sesión expirada

export function SinPermisoScreen({ portal }: { portal: Portal }) {
  const [destino, setDestino] = useState<string | null>(null)
  useEffect(() => {
    const sesion = leerSesion()
    const propio = sesion.estado === 'activa' ? portalDeRol(sesion.rol) : null
    setDestino(propio ? PORTALES[propio].inicio : null)
  }, [])
  return (
    <AuthShell portal={portal} eyebrow="Error 403" titulo="No tienes permiso para ver esta página" descripcion="Tu cuenta no tiene acceso a esta sección. Si crees que es un error, pide a un administrador que revise tus permisos.">
      <EstadoAuth icono={Ban} tono="alerta">
        {destino && <Link href={destino} className="flex h-13 w-full items-center justify-center gap-2 rounded-xl bg-[#e7b31c] px-5 font-bold text-[#111c25] transition hover:bg-[#f4ca4d]">Volver a mi portal <ArrowRight className="size-4" /></Link>}
        <Link href={PORTALES[portal].login} onClick={cerrarSesion} className={botonSecundario}>Entrar con otra cuenta</Link>
      </EstadoAuth>
    </AuthShell>
  )
}

export function SesionExpiradaScreen({ portal }: { portal: Portal }) {
  return (
    <AuthShell portal={portal} eyebrow="Seguridad" titulo="Tu sesión expiró" descripcion="Por tu seguridad cerramos la sesión después de 30 minutos sin actividad. Inicia sesión de nuevo para continuar donde te quedaste.">
      <EstadoAuth icono={ClockAlert} tono="alerta">
        <Link href={PORTALES[portal].login} onClick={cerrarSesion} className="flex h-13 w-full items-center justify-center gap-2 rounded-xl bg-[#e7b31c] px-5 font-bold text-[#111c25] transition hover:bg-[#f4ca4d]"><KeyRound className="size-4" /> Volver a iniciar sesión</Link>
      </EstadoAuth>
    </AuthShell>
  )
}
