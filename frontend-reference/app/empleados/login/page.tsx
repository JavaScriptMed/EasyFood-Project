'use client'

import Link from 'next/link'
import { useState, type FormEvent } from 'react'
import { ArrowRight, Mail } from 'lucide-react'
import { AuthShell, AvisoAuth, BotonAuth, CampoAuth, CampoContrasena, enlaceSecundario } from '@/components/cuenta/auth-shell'
import { iniciarSesionEnPortal } from '@/lib/auth'

export default function EmpleadoLogin() {
  const [correo, setCorreo] = useState('')
  const [clave, setClave] = useState('')
  const [cargando, setCargando] = useState(false)
  const [error, setError] = useState('')

  async function entrar(e: FormEvent) {
    e.preventDefault()
    setError('')
    setCargando(true)
    const resultado = await iniciarSesionEnPortal('empleados', correo, clave)
    if (resultado.estado === 'ok') { window.location.href = resultado.destino; return }
    setCargando(false)
    setError(resultado.mensaje)
  }

  return (
    <AuthShell portal="empleados" eyebrow="Acceso para empleados" titulo="Bienvenido a tu comida" descripcion="Ingresa con tu correo para elegir tu platillo de mañana.">
      <form className="flex flex-col gap-5" onSubmit={entrar}>
        <CampoAuth id="correo" label="Correo electrónico" icono={Mail} type="email" required autoComplete="email" value={correo} onChange={(e) => setCorreo(e.target.value)} placeholder="nombre@tuempresa.mx" />
        <CampoContrasena id="clave" label="Contraseña" required minLength={6} autoComplete="current-password" value={clave} onChange={(e) => setClave(e.target.value)} placeholder="Tu contraseña" />
        <div className="-mt-2 text-right text-sm"><Link href="/empleados/olvide-contrasena" className={enlaceSecundario}>¿Olvidaste tu contraseña?</Link></div>
        {error && <AvisoAuth>{error}</AvisoAuth>}
        <BotonAuth type="submit" cargando={cargando} textoCargando="Validando acceso…">Entrar a mi portal <ArrowRight /></BotonAuth>
      </form>
    </AuthShell>
  )
}
