import { ContenidoDeCarga } from '@/components/pantalla-de-carga'

/** Lo que se ve mientras una página tarda en llegar al cambiar de sección. */
export default function Cargando() {
  return (
    <div className="grid min-h-screen place-items-center bg-lbg">
      <ContenidoDeCarga />
    </div>
  )
}
