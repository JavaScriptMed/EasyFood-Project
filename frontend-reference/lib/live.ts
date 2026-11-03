'use client'

import { useEffect, useState } from 'react'

/** Fecha actual actualizada cada segundo; es null en el servidor para evitar errores de hidratación. */
export function useNow() {
  const [now, setNow] = useState<Date | null>(null)
  useEffect(() => {
    setNow(new Date())
    const timer = window.setInterval(() => setNow(new Date()), 1000)
    return () => window.clearInterval(timer)
  }, [])
  return now
}

/** Segundos que faltan para la siguiente vez que sean las `hour`:`minute`. */
export function secondsUntilHour(now: Date, hour: number, minute = 0) {
  const target = new Date(now)
  target.setHours(hour, minute, 0, 0)
  if (target <= now) target.setDate(target.getDate() + 1)
  return Math.floor((target.getTime() - now.getTime()) / 1000)
}

export const formatCountdown = (seconds: number) => {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${pad(Math.floor(seconds / 3600))}:${pad(Math.floor((seconds % 3600) / 60))}:${pad(seconds % 60)}`
}

/** Cuenta de 0 a `target` con ease-out; respeta prefers-reduced-motion. */
export function useCountUp(target: number, duration = 1100) {
  const [value, setValue] = useState(0)
  useEffect(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      setValue(target)
      return
    }
    let frame = 0
    const start = performance.now()
    const tick = (time: number) => {
      const progress = Math.min((time - start) / duration, 1)
      setValue(Math.round(target * (1 - Math.pow(1 - progress, 3))))
      if (progress < 1) frame = requestAnimationFrame(tick)
    }
    frame = requestAnimationFrame(tick)
    return () => cancelAnimationFrame(frame)
  }, [target, duration])
  return value
}
