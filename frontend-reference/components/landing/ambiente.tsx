'use client'

import { motion, useReducedMotion, useScroll, useTransform } from 'motion/react'
import { useState } from 'react'

/** Números repetibles (el servidor y el cliente deben pintar lo mismo). */
function azar(semilla: number) {
  let a = semilla
  return () => {
    a = (a + 0x6d2b79f5) | 0
    let t = Math.imul(a ^ (a >>> 15), 1 | a)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

const rnd = azar(7)
const vapor = Array.from({ length: 11 }, (_, i) => ({ left: `${(i * 9.4 + rnd() * 6).toFixed(1)}%`, tamano: Math.round(110 + rnd() * 120), dur: 15 + rnd() * 11, retraso: -(i * 2.3 + rnd() * 3), dx: Math.round(-40 + rnd() * 80), op: 0.18 + rnd() * 0.16 }))
const destellos = Array.from({ length: 22 }, () => ({ left: `${(rnd() * 100).toFixed(1)}%`, top: `${(18 + rnd() * 72).toFixed(1)}%`, tamano: Math.round(3 + rnd() * 4), dur: 5 + rnd() * 5, retraso: -(rnd() * 9), dx: Math.round(-14 + rnd() * 28), op: 0.55 + rnd() * 0.4 }))

/** Fondo del hero: foto de platillos difuminada, viñeta oscura, vapor y destellos dorados. */
export function Ambiente() {
  const reducido = useReducedMotion()
  const [hayFoto, setHayFoto] = useState(true)
  const { scrollY } = useScroll()
  const desplazamiento = useTransform(scrollY, [0, 700], [0, reducido ? 0 : 90])

  return (
    <div aria-hidden="true" className="pointer-events-none absolute inset-0 overflow-hidden">
      <motion.div style={{ y: desplazamiento }} className="absolute -inset-x-6 -top-10 bottom-[-4rem]">
        {hayFoto && <img src="/platillos/ambiente.jpg" alt="" onError={() => setHayFoto(false)} className="size-full scale-110 object-cover opacity-55 blur-[7px]" />}
        {!hayFoto && (
          <>
            <div className="absolute left-[8%] top-[18%] size-[34rem] rounded-full bg-[radial-gradient(closest-side,rgba(224,168,19,0.22),transparent)]" />
            <div className="absolute right-[6%] top-[38%] size-[30rem] rounded-full bg-[radial-gradient(closest-side,rgba(207,107,61,0.20),transparent)]" />
            <div className="absolute bottom-[4%] left-[34%] size-[28rem] rounded-full bg-[radial-gradient(closest-side,rgba(114,166,108,0.14),transparent)]" />
          </>
        )}
      </motion.div>
      <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_50%_35%,rgba(14,22,29,0.25)_0%,rgba(14,22,29,0.82)_62%,#0E161D_100%)]" />
      <div className="absolute inset-x-0 bottom-0 h-48 bg-gradient-to-b from-transparent to-lbg" />

      {vapor.map((v, i) => (
        <span key={`v${i}`} className="landing-vapor absolute bottom-0 block rounded-full bg-[radial-gradient(closest-side,rgba(250,248,242,0.55),transparent)]" style={{ left: v.left, width: v.tamano, height: v.tamano, ['--dur' as string]: `${v.dur}s`, ['--delay' as string]: `${v.retraso}s`, ['--dx' as string]: `${v.dx}px`, ['--op' as string]: v.op }} />
      ))}
      {destellos.map((d, i) => (
        <span key={`d${i}`} className="landing-destello absolute block rounded-full bg-lgold shadow-[0_0_12px_3px_rgba(224,168,19,0.55)]" style={{ left: d.left, top: d.top, width: d.tamano, height: d.tamano, ['--dur' as string]: `${d.dur}s`, ['--delay' as string]: `${d.retraso}s`, ['--dx' as string]: `${d.dx}px`, ['--op' as string]: d.op }} />
      ))}
    </div>
  )
}
