import type { ReactNode } from 'react'
import { EmpleadoShell } from '@/components/empleado-shell'

export default function EmpleadosLayout({ children }: { children: ReactNode }) {
  return <EmpleadoShell>{children}</EmpleadoShell>
}
