import type { Metadata } from 'next'
import Link from 'next/link'
import { ArrowLeft, Download, ExternalLink, MousePointerClick, MonitorSmartphone, Smartphone } from 'lucide-react'
import { Logo } from '@/components/landing/nav'
import { BotonPantallaCompleta } from './pantalla-completa'

/** Enlace al instalador para Windows. Vacío = no se muestra el botón de descarga. */
const ENLACE_WINDOWS = ''

const ID_JUEGO = 'juego-easylunch'

export const metadata: Metadata = {
  title: 'EasyLunch Rush · Juego',
  description: 'Maneja la camioneta de EasyLunch, esquiva topes y autos, y entrega todos los paquetes que puedas.',
}

export default function Juego() {
  return (
    <div className="landing font-body flex min-h-dvh flex-col bg-lbg text-lcream antialiased">
      <header className="border-b border-white/10">
        <nav aria-label="Principal" className="mx-auto flex h-14 max-w-7xl items-center justify-between gap-4 px-5 lg:px-8">
          <Logo />
          <Link href="/" className="inline-flex items-center gap-2 rounded-full px-3 py-2 text-sm font-medium text-lcream/80 transition-colors hover:text-lgold">
            <ArrowLeft className="size-4" aria-hidden="true" />
            Volver al inicio
          </Link>
        </nav>
      </header>

      <main id="contenido" className="mx-auto flex w-full max-w-7xl flex-1 flex-col items-center gap-3 px-5 py-4 lg:px-8">
        <div className="w-full text-center">
          <h1 className="font-heading text-3xl leading-tight text-lcream sm:text-4xl">
            EasyLunch <span className="text-lgold">Rush</span>
          </h1>
          <p className="mx-auto mt-1 max-w-2xl text-sm text-lcream/75 sm:text-base">
            Maneja la camioneta de EasyLunch por la ciudad: salta topes y baches, frena a los autos con el claxon y entrega todos los paquetes que puedas.
          </p>
        </div>

        {/* El ancho se limita por la altura de la pantalla para que el juego (16:10) quepa sin scroll en una laptop de 1366x768. */}
        <div className="w-full" style={{ maxWidth: 'min(100%, calc((100dvh - 260px) * 1.6))' }}>
          <iframe
            id={ID_JUEGO}
            src="/juego-web/index.html"
            title="EasyLunch Rush"
            allow="fullscreen; autoplay; gamepad"
            allowFullScreen
            className="block aspect-[16/10] w-full rounded-2xl border border-white/10 bg-[#231F20] shadow-[0_20px_60px_-20px_rgba(224,168,19,0.35)]"
          />
        </div>

        <div className="flex w-full flex-wrap items-center justify-center gap-x-6 gap-y-3">
          <div className="flex flex-wrap items-center justify-center gap-3">
            <BotonPantallaCompleta idJuego={ID_JUEGO} />
            {/* En iPhone el iframe no puede ir a pantalla completa: abrir el juego solo ocupa toda la pantalla. */}
            <a href="/juego-web/index.html" className="inline-flex items-center gap-2 rounded-full border border-lgold/60 px-5 py-2.5 text-sm font-semibold text-lgold transition-colors hover:bg-lgold/10 md:hidden">
              <ExternalLink className="size-4" aria-hidden="true" />
              Abrir solo el juego
            </a>
            {ENLACE_WINDOWS && (
              <a href={ENLACE_WINDOWS} className="inline-flex items-center gap-2 rounded-full border border-lgold/60 px-5 py-2.5 text-sm font-semibold text-lgold transition-colors hover:bg-lgold/10">
                <Download className="size-4" aria-hidden="true" />
                Descargar para Windows
              </a>
            )}
          </div>
          <ul className="flex flex-wrap justify-center gap-x-6 gap-y-1 text-xs text-lcream/65 sm:text-sm">
            <li className="hidden items-center gap-1.5 md:flex"><MousePointerClick className="size-4 text-lgold" aria-hidden="true" />Haz clic en el juego para usar los controles</li>
            <li className="flex items-center gap-1.5 md:hidden"><Smartphone className="size-4 rotate-90 text-lgold" aria-hidden="true" />En celular, gira el teléfono y usa los botones en pantalla</li>
            <li className="flex items-center gap-1.5"><MonitorSmartphone className="size-4 text-lgold" aria-hidden="true" />Si no carga, prueba en Chrome, Edge o Safari actualizados</li>
          </ul>
        </div>
      </main>
    </div>
  )
}
