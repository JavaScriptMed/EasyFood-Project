'use client'

import { useCallback, useEffect, useRef, useState } from 'react'

type Estado<T> = { data: T | undefined; error: Error | null; loading: boolean }

/**
 * Carga datos con una función de /lib/api.ts. Vuelve a cargar cuando cambian `deps`
 * (mostrando el estado de carga) y expone `reload` para refrescar tras una escritura.
 * `reload({ silent: true })` conserva lo que ya se ve mientras actualiza.
 */
export function useAsync<T>(cargar: () => Promise<T>, deps: unknown[] = []) {
  const [estado, setEstado] = useState<Estado<T>>({ data: undefined, error: null, loading: true })
  const [intento, setIntento] = useState(0)
  const silencioso = useRef(false)
  const cargarRef = useRef(cargar)
  cargarRef.current = cargar

  useEffect(() => {
    let vigente = true
    if (silencioso.current) silencioso.current = false
    else setEstado({ data: undefined, error: null, loading: true })
    cargarRef.current().then(
      (data) => vigente && setEstado({ data, error: null, loading: false }),
      (error) => vigente && setEstado({ data: undefined, error: error instanceof Error ? error : new Error(String(error)), loading: false }),
    )
    return () => { vigente = false }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, intento])

  const reload = useCallback((opciones?: { silent?: boolean }) => {
    silencioso.current = !!opciones?.silent
    setIntento((n) => n + 1)
  }, [])

  const setData = useCallback((siguiente: T | ((actual: T | undefined) => T | undefined)) => {
    setEstado((s) => ({ ...s, data: typeof siguiente === 'function' ? (siguiente as (actual: T | undefined) => T | undefined)(s.data) : siguiente }))
  }, [])

  return { ...estado, reload, retry: reload, setData }
}
