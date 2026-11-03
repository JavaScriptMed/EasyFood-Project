'use client'

import { PageTitle } from '@/components/panel'
import { UsuariosAcceso } from '@/components/usuarios-acceso'
import { EMPRESA_PORTAL_ID } from '@/lib/config'

export default function EquipoEmpresa() {
  return (
    <div>
      <div className="mb-8 border-b pb-6"><PageTitle eyebrow="Accesos" title="Equipo de RR. HH." description="Invita a quienes administran el portal de tu empresa y revisa si ya aceptaron su invitación." /></div>
      <UsuariosAcceso organizacion={{ tipo: 'empresa', id: EMPRESA_PORTAL_ID }} roles={['empresa']} vacioPlural="usuarios" />
    </div>
  )
}
