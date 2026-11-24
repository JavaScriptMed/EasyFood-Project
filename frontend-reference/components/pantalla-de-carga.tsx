'use client'

import { useEffect, useState } from 'react'

/** La camioneta de EasyLunch en marcha: bota suave, ruedas que giran, camino que corre, humo y polvo. Se dibuja con SVG para que no pese. */
export function Camioncito({ className = '' }: { className?: string }) {
  return (
    <svg viewBox="0 0 420 240" role="img" aria-label="Camioneta de EasyLunch en camino" className={className}>
      <defs>
        <linearGradient id="carga-cuerpo" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#f6b04a" />
          <stop offset="1" stopColor="#e8872a" />
        </linearGradient>
        <linearGradient id="carga-vidrio" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#9fb0bd" />
          <stop offset="1" stopColor="#5c6c78" />
        </linearGradient>
        <radialGradient id="carga-resplandor" cx="0.5" cy="0.5" r="0.5">
          <stop offset="0" stopColor="#e0a813" stopOpacity="0.32" />
          <stop offset="1" stopColor="#e0a813" stopOpacity="0" />
        </radialGradient>
      </defs>

      <ellipse cx="215" cy="120" rx="200" ry="110" fill="url(#carga-resplandor)" />

      {/* camino */}
      <g>
        <g className="carga-camino" stroke="#e8c97a" strokeWidth="4" strokeLinecap="round">
          {Array.from({ length: 9 }, (_, i) => <line key={i} x1={i * 60 - 20} y1="206" x2={i * 60 + 12} y2="206" strokeOpacity={0.85} />)}
        </g>
      </g>
      <line x1="0" y1="196" x2="420" y2="196" stroke="#e8c97a" strokeWidth="2" strokeOpacity="0.5" />

      {/* líneas de velocidad */}
      <g stroke="#e0a813" strokeWidth="3" strokeLinecap="round">
        <line className="carga-lineas" style={{ ['--retraso' as string]: '0s' }} x1="40" y1="100" x2="84" y2="100" />
        <line className="carga-lineas" style={{ ['--retraso' as string]: '0.15s' }} x1="26" y1="118" x2="82" y2="118" />
        <line className="carga-lineas" style={{ ['--retraso' as string]: '0.3s' }} x1="44" y1="136" x2="86" y2="136" />
      </g>

      {/* polvo y destellos detrás */}
      <g fill="#c9a24a">
        <circle className="carga-polvo" style={{ ['--retraso' as string]: '0s' }} cx="96" cy="184" r="9" fillOpacity="0.55" />
        <circle className="carga-polvo" style={{ ['--retraso' as string]: '0.35s' }} cx="84" cy="188" r="6" fillOpacity="0.5" />
        <circle className="carga-polvo" style={{ ['--retraso' as string]: '0.7s' }} cx="104" cy="190" r="7" fillOpacity="0.45" />
      </g>
      <g fill="#ffd45e">
        <circle className="carga-polvo" style={{ ['--retraso' as string]: '0.2s' }} cx="70" cy="170" r="2.4" />
        <circle className="carga-polvo" style={{ ['--retraso' as string]: '0.55s' }} cx="60" cy="182" r="2" />
        <circle className="carga-polvo" style={{ ['--retraso' as string]: '0.9s' }} cx="78" cy="160" r="1.8" />
      </g>

      <g className="carga-camion">
        {/* humo de la chimenea */}
        <g fill="#f4efe4">
          <circle className="carga-humo" style={{ ['--retraso' as string]: '0s' }} cx="170" cy="62" r="9" />
          <circle className="carga-humo" style={{ ['--retraso' as string]: '0.5s' }} cx="166" cy="62" r="7" />
          <circle className="carga-humo" style={{ ['--retraso' as string]: '1s' }} cx="172" cy="62" r="8" />
        </g>
        <rect x="160" y="64" width="22" height="14" rx="2" fill="#9aa1a8" />
        <rect x="156" y="62" width="30" height="5" rx="2.5" fill="#b4bac0" />

        {/* caja de la camioneta */}
        <path d="M104 78h196a12 12 0 0 1 12 12v88H92V90a12 12 0 0 1 12-12z" fill="url(#carga-cuerpo)" stroke="#7a4310" strokeWidth="3" strokeLinejoin="round" />
        <rect x="92" y="150" width="220" height="28" fill="#dd7a1d" />
        <line x1="92" y1="150" x2="312" y2="150" stroke="#7a4310" strokeWidth="2" strokeOpacity="0.6" />

        {/* ventana de servicio y toldo */}
        <rect x="112" y="102" width="106" height="44" rx="6" fill="#262c33" stroke="#7a4310" strokeWidth="2.5" />
        <rect x="124" y="124" width="82" height="22" fill="#d7dbe0" />
        <rect x="124" y="124" width="82" height="4" fill="#aeb4ba" />
        <path d="M104 84h122l-6 20H110z" fill="#fff5e6" stroke="#7a4310" strokeWidth="2.5" strokeLinejoin="round" />
        {[0, 1, 2, 3, 4].map((i) => <path key={i} d={`M${112 + i * 22.4} 84h11.2l-2.6 20h-11.2z`} fill="#e8872a" />)}

        {/* insignia con tenedor y cuchara */}
        <circle cx="262" cy="118" r="26" fill="#e0a813" stroke="#7a4310" strokeWidth="2.5" />
        <g stroke="#2b2118" strokeWidth="4" strokeLinecap="round" fill="none">
          <path d="M252 106l20 24M272 106l-20 24" />
          <path d="M249 106v6m4-6v6m4-6v6" strokeWidth="2.4" />
        </g>

        {/* cabina */}
        <path d="M312 96h26a18 18 0 0 1 15 8l17 26a16 16 0 0 1 3 9v39h-61z" fill="url(#carga-cuerpo)" stroke="#7a4310" strokeWidth="3" strokeLinejoin="round" />
        <path d="M322 106h14a10 10 0 0 1 8 4l12 18h-34z" fill="url(#carga-vidrio)" stroke="#7a4310" strokeWidth="2.5" strokeLinejoin="round" />
        <path d="M326 110l16 0-8 16h-8z" fill="#fff" fillOpacity="0.18" />
        <rect x="364" y="150" width="14" height="10" rx="3" fill="#ffe08a" stroke="#7a4310" strokeWidth="2" />
        <rect x="366" y="172" width="22" height="9" rx="3.5" fill="#cfd4d9" stroke="#6b7178" strokeWidth="2" />
        <rect x="82" y="172" width="20" height="9" rx="3.5" fill="#cfd4d9" stroke="#6b7178" strokeWidth="2" />

        {/* ruedas */}
        {[148, 336].map((cx) => (
          <g key={cx}>
            <circle cx={cx} cy="184" r="22" fill="#23272b" />
            <g className="carga-rueda" style={{ transformOrigin: `${cx}px 184px` }}>
              <circle cx={cx} cy="184" r="12.5" fill="#b9bfc5" />
              <g stroke="#6e757c" strokeWidth="2.4" strokeLinecap="round">
                <line x1={cx - 10} y1="184" x2={cx + 10} y2="184" />
                <line x1={cx} y1="174" x2={cx} y2="194" />
                <line x1={cx - 7} y1="177" x2={cx + 7} y2="191" />
                <line x1={cx + 7} y1="177" x2={cx - 7} y2="191" />
              </g>
              <circle cx={cx} cy="184" r="3.4" fill="#40464c" />
            </g>
          </g>
        ))}
      </g>
    </svg>
  )
}

/** Contenido de la pantalla de carga (camioneta, texto y barra). `progreso` va de 0 a 100; sin él, la barra avanza sola. */
export function ContenidoDeCarga({ progreso }: { progreso?: number }) {
  return (
    <div role="status" aria-live="polite" className="flex w-[min(88vw,26rem)] flex-col items-center">
      <Camioncito className="w-full" />
      <p className="mt-2 font-heading text-2xl tracking-tight text-lcream">Cargando…</p>
      <div className="mt-4 h-2.5 w-full max-w-xs overflow-hidden rounded-full border border-lgold/40 bg-white/5" aria-hidden="true">
        <div className={`h-full rounded-full bg-gradient-to-r from-[#b8860b] via-lgold to-[#ffd45e] shadow-[0_0_14px_rgba(224,168,19,0.7)] transition-[width] duration-200 ease-linear ${progreso === undefined ? 'w-1/3 animate-pulse' : ''}`} style={progreso === undefined ? undefined : { width: `${progreso}%` }} />
      </div>
    </div>
  )
}

/**
 * Pantalla de carga de todo el sitio: aparece al abrir o recargar una página y se va cuando todo terminó de cargar
 * (se queda un mínimo para que se alcance a ver, y nunca más de 4 segundos). Con «menos movimiento» dura menos y no se anima.
 */
export function PantallaDeCarga() {
  const [fase, setFase] = useState<'visible' | 'saliendo' | 'oculta'>('visible')
  const [progreso, setProgreso] = useState(6)

  useEffect(() => {
    const reducido = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    const minimo = reducido ? 250 : 1100
    const inicio = performance.now()
    let terminado = false
    const temporizadores: ReturnType<typeof setTimeout>[] = []
    const avance = setInterval(() => setProgreso((p) => (terminado ? 100 : p + (92 - p) * 0.09)), 90)

    const terminar = () => {
      if (terminado) return
      terminado = true
      const resto = Math.max(0, minimo - (performance.now() - inicio))
      temporizadores.push(setTimeout(() => {
        setProgreso(100)
        temporizadores.push(setTimeout(() => {
          setFase('saliendo')
          temporizadores.push(setTimeout(() => setFase('oculta'), 450))
        }, 220))
      }, resto))
    }

    if (document.readyState === 'complete') terminar()
    else window.addEventListener('load', terminar, { once: true })
    temporizadores.push(setTimeout(terminar, 4000))

    return () => {
      clearInterval(avance)
      temporizadores.forEach(clearTimeout)
      window.removeEventListener('load', terminar)
    }
  }, [])

  useEffect(() => {
    if (fase === 'oculta') return
    const anterior = document.documentElement.style.overflow
    document.documentElement.style.overflow = 'hidden'
    return () => { document.documentElement.style.overflow = anterior }
  }, [fase])

  if (fase === 'oculta') return null
  return (
    <>
      <noscript><style>{'#pantalla-de-carga{display:none!important}'}</style></noscript>
      <div id="pantalla-de-carga" aria-hidden={fase === 'saliendo'} className={`fixed inset-0 z-[200] grid place-items-center bg-lbg transition-opacity duration-500 ${fase === 'saliendo' ? 'pointer-events-none opacity-0' : 'opacity-100'}`}>
        <ContenidoDeCarga progreso={progreso} />
      </div>
    </>
  )
}
