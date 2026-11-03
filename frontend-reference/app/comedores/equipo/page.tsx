'use client'

import { PageTitle } from '@/components/panel'
import { UsuariosAcceso } from '@/components/usuarios-acceso'
import { COMEDOR_PORTAL_ID } from '@/lib/config'

export default function EquipoComedor() {
  return (
    <div>
      <div className="mb-8 border-b pb-6"><PageTitle eyebrow="Accesos" title="Equipo" description="Invita a las personas de tu cocina y revisa si ya aceptaron su invitación." /></div>
      <UsuariosAcceso organizacion={{ tipo: 'comedor', id: COMEDOR_PORTAL_ID }} roles={['comedor']} vacioPlural="usuarios" />
    </div>
  )
}
