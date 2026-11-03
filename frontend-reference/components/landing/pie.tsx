import Link from 'next/link'
import { Logo } from './nav'

export function Pie() {
  return (
    <footer className="border-t border-white/10">
      <div className="mx-auto flex max-w-7xl flex-col items-start justify-between gap-8 px-5 py-12 md:flex-row md:items-center lg:px-8">
        <Logo />
        <nav aria-label="Pie de página"><ul className="flex flex-wrap gap-x-8 gap-y-3 text-sm font-medium text-lcream/80">
          <li><Link href="/empresas/login" className="transition-colors hover:text-lgold">Empresas</Link></li>
          <li><Link href="/comedores/login" className="transition-colors hover:text-lgold">Comedores</Link></li>
          <li><Link href="/login" className="transition-colors hover:text-lgold">Iniciar sesión</Link></li>
        </ul></nav>
        <p className="text-sm text-lcream/65">© 2026 EasyLunch</p>
      </div>
    </footer>
  )
}
