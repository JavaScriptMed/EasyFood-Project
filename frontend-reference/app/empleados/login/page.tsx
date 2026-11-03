'use client'

import Link from 'next/link'
import { useState, type FormEvent } from 'react'
import { ArrowRight, Mail } from 'lucide-react'
import { AuthShell, AvisoAuth, BotonAuth, CampoAuth, CampoContrasena, enlaceSecundario } from '@/components/cuenta/auth-shell'
import { iniciarSesion } from '@/lib/sesion'

export default function EmpleadoLogin() {
  const [correo, setCorreo] = useState('')
  const [clave, setClave] = useState('')
  const [cargando, setCargando] = useState(false)
  const [error, setError] = useState(false)

  // Maqueta: solo entran correos de empleado. El backend validará el rol real.
  function entrar(e: FormEvent) {
    e.preventDefault()
    setError(false)
    setCargando(true)
    window.setTimeout(() => {
      if (/empleado/i.test(correo)) {
        iniciarSesion('empleado')
        window.location.href = '/empleados'
      } else {
        setCargando(false)
        setError(true)
      }
    }, 450)
  }

  return (
    <AuthShell portal="empleados" eyebrow="Acceso para empleados" titulo="Bienvenido a tu comida" descripcion="Ingresa con tu correo para elegir tu platillo de mañana.">
      <form className="flex flex-col gap-5" onSubmit={entrar}>
        <CampoAuth id="correo" label="Correo electrónico" icono={Mail} type="email" required autoComplete="email" value={correo} onChange={(e) => setCorreo(e.target.value)} placeholder="nombre@tuempresa.mx" />
        <CampoContrasena id="clave" label="Contraseña" required minLength={6} autoComplete="current-password" value={clave} onChange={(e) => setClave(e.target.value)} placeholder="Tu contraseña" />
        <div className="-mt-2 text-right text-sm"><Link href="/empleados/olvide-contrasena" className={enlaceSecundario}>¿Olvidaste tu contraseña?</Link></div>
        {error && <AvisoAuth>Este acceso es solo para cuentas de empleado.</AvisoAuth>}
        <BotonAuth type="submit" cargando={cargando} textoCargando="Validando acceso…">Entrar a mi portal <ArrowRight /></BotonAuth>
      </form>
    </AuthShell>
  )
}
