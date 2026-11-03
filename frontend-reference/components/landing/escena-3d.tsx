'use client'

import { Canvas, useFrame } from '@react-three/fiber'
import { ContactShadows, Environment, useProgress } from '@react-three/drei'
import { Suspense, useEffect, useRef, useState, type ReactNode } from 'react'
import { DoubleSide, MathUtils, type Group } from 'three'

const ORO = '#E0A813'
const ORO_OSCURO = '#a87a08'
const CREMA = '#FAF8F2'

/**
 * Lonchera térmica con tapa, hecha con cilindros, torus y esferas.
 *
 * ¿Hay un modelo .glb? Reemplaza TODO el contenido de este componente por:
 *   const { scene } = useGLTF('/modelos/lonchera.glb')
 *   return <primitive object={scene} scale={1} />
 * y precárgalo con `useGLTF.preload('/modelos/lonchera.glb')` (import { useGLTF } from '@react-three/drei').
 * Haz lo mismo con <Aguacate />, <Chile /> y <Limon /> si también llegan como modelos.
 */
function Lonchera() {
  return (
    <group position={[0, -0.45, 0]}>
      {/* cuerpo */}
      <mesh><cylinderGeometry args={[1.2, 0.95, 1.1, 72]} /><meshStandardMaterial color={ORO} metalness={1} roughness={0.22} /></mesh>
      {/* base y banda */}
      <mesh position={[0, -0.55, 0]} rotation={[Math.PI / 2, 0, 0]}><torusGeometry args={[0.95, 0.055, 20, 96]} /><meshStandardMaterial color={ORO_OSCURO} metalness={1} roughness={0.3} /></mesh>
      <mesh position={[0, -0.05, 0]} rotation={[Math.PI / 2, 0, 0]}><torusGeometry args={[1.066, 0.05, 20, 96]} /><meshStandardMaterial color={CREMA} metalness={0.85} roughness={0.25} /></mesh>
      {/* borde y tapa */}
      <mesh position={[0, 0.55, 0]} rotation={[Math.PI / 2, 0, 0]}><torusGeometry args={[1.2, 0.07, 24, 96]} /><meshStandardMaterial color={CREMA} metalness={0.85} roughness={0.22} /></mesh>
      <mesh position={[0, 0.55, 0]} scale={[1, 0.62, 1]}><sphereGeometry args={[1.22, 72, 36, 0, Math.PI * 2, 0, Math.PI / 2]} /><meshStandardMaterial color={CREMA} metalness={0.75} roughness={0.28} side={DoubleSide} /></mesh>
      {/* perilla */}
      <mesh position={[0, 1.36, 0]} rotation={[Math.PI / 2, 0, 0]}><torusGeometry args={[0.15, 0.04, 16, 48]} /><meshStandardMaterial color={ORO_OSCURO} metalness={1} roughness={0.3} /></mesh>
      <mesh position={[0, 1.42, 0]}><sphereGeometry args={[0.16, 32, 24]} /><meshStandardMaterial color={ORO} metalness={1} roughness={0.2} /></mesh>
      {/* broches laterales */}
      {[-1.21, 1.21].map((x) => <mesh key={x} position={[x, 0.55, 0]}><boxGeometry args={[0.14, 0.44, 0.18]} /><meshStandardMaterial color={ORO} metalness={1} roughness={0.25} /></mesh>)}
    </group>
  )
}

function Aguacate() {
  return (
    <group>
      <mesh scale={[0.34, 0.46, 0.3]}><sphereGeometry args={[1, 32, 24]} /><meshStandardMaterial color="#3f6b2a" roughness={0.55} /></mesh>
      <mesh position={[0, 0, 0.1]} scale={[0.28, 0.4, 0.2]}><sphereGeometry args={[1, 32, 24]} /><meshStandardMaterial color="#d3df7c" roughness={0.6} /></mesh>
      <mesh position={[0, -0.05, 0.2]}><sphereGeometry args={[0.12, 24, 18]} /><meshStandardMaterial color="#7b4a24" roughness={0.4} /></mesh>
    </group>
  )
}

function Chile() {
  return (
    <group rotation={[0, 0, -0.9]}>
      <mesh position={[0, -0.22, 0]} rotation={[Math.PI, 0, 0]}><coneGeometry args={[0.1, 0.55, 24]} /><meshStandardMaterial color="#c62828" roughness={0.35} /></mesh>
      <mesh position={[0, 0.06, 0]}><sphereGeometry args={[0.1, 24, 18]} /><meshStandardMaterial color="#c62828" roughness={0.35} /></mesh>
      <mesh position={[0, 0.2, 0]}><cylinderGeometry args={[0.025, 0.04, 0.18, 12]} /><meshStandardMaterial color="#3f7d2a" roughness={0.6} /></mesh>
    </group>
  )
}

function Limon() {
  return (
    <group rotation={[Math.PI / 2.3, 0, 0]}>
      <mesh><cylinderGeometry args={[0.4, 0.4, 0.06, 48]} /><meshStandardMaterial color="#9bc53d" roughness={0.5} /></mesh>
      <mesh><cylinderGeometry args={[0.35, 0.35, 0.07, 48]} /><meshStandardMaterial color="#f1f3a8" roughness={0.45} /></mesh>
      {[0, 1, 2, 3].map((k) => <mesh key={k} rotation={[0, (k * Math.PI) / 4, 0]}><boxGeometry args={[0.68, 0.075, 0.014]} /><meshStandardMaterial color="#fff9d6" roughness={0.5} /></mesh>)}
    </group>
  )
}

/** Hace girar un ingrediente alrededor de la lonchera con su propio vaivén. */
function Orbita({ radio, altura, fase, velocidad, children }: { radio: number; altura: number; fase: number; velocidad: number; children: ReactNode }) {
  const grupo = useRef<Group>(null)
  useFrame((estado) => {
    const g = grupo.current
    if (!g) return
    const t = estado.clock.elapsedTime
    const a = t * velocidad + fase
    g.position.set(Math.cos(a) * radio, altura + Math.sin(t * 1.3 + fase) * 0.14, Math.sin(a) * radio * 0.65)
    g.rotation.set(t * 0.6 + fase, t * 0.9, t * 0.35)
  })
  return <group ref={grupo}>{children}</group>
}

function Escenario() {
  const inclinacion = useRef<Group>(null)
  const giro = useRef<Group>(null)
  const cursor = useRef({ x: 0, y: 0 })
  const scroll = useRef(0)

  // El cursor se sigue en toda la ventana: la lonchera mira hacia donde mueves el mouse.
  useEffect(() => {
    const mover = (e: PointerEvent) => { cursor.current.x = (e.clientX / window.innerWidth) * 2 - 1; cursor.current.y = (e.clientY / window.innerHeight) * 2 - 1 }
    window.addEventListener('pointermove', mover, { passive: true })
    return () => window.removeEventListener('pointermove', mover)
  }, [])

  useFrame((estado, dt) => {
    const t = estado.clock.elapsedTime
    scroll.current = MathUtils.damp(scroll.current, Math.min(window.scrollY, 900), 4, dt)
    if (giro.current) giro.current.rotation.y = t * 0.2 + scroll.current * 0.0022 // rota despacio y un poco más al bajar
    const g = inclinacion.current
    if (!g) return
    g.rotation.x = MathUtils.damp(g.rotation.x, cursor.current.y * 0.22 + 0.1, 4, dt)
    g.rotation.y = MathUtils.damp(g.rotation.y, cursor.current.x * 0.35, 4, dt)
    g.position.x = MathUtils.damp(g.position.x, cursor.current.x * 0.2, 3, dt)
    g.position.y = Math.sin(t * 1.1) * 0.12 - scroll.current * 0.0006 // vaivén suave
  })

  return (
    <group ref={inclinacion}>
      <group ref={giro}><Lonchera /></group>
      <Orbita radio={2.15} altura={0.55} fase={0} velocidad={0.45}><Aguacate /></Orbita>
      <Orbita radio={2.0} altura={-0.1} fase={2.1} velocidad={0.38}><Chile /></Orbita>
      <Orbita radio={2.25} altura={1.05} fase={4.2} velocidad={0.5}><Limon /></Orbita>
    </group>
  )
}

/** Escena 3D del hero. `activa` pausa el render cuando la escena no se ve; `onListo` avisa al cargar el entorno. */
export default function Escena3D({ activa, onListo }: { activa: boolean; onListo: () => void }) {
  const [creada, setCreada] = useState(false)
  const { active: cargando } = useProgress()

  useEffect(() => {
    if (!creada || cargando) return
    const temporizador = window.setTimeout(onListo, 450)
    return () => window.clearTimeout(temporizador)
  }, [creada, cargando, onListo])

  return (
    <Canvas aria-hidden="true" dpr={[1, 1.5]} frameloop={activa ? 'always' : 'never'} camera={{ position: [0, 0.7, 7.6], fov: 40 }} gl={{ antialias: true, alpha: true, powerPreference: 'high-performance' }} onCreated={() => setCreada(true)}>
      <ambientLight intensity={0.55} color="#ffe3b3" />
      <directionalLight position={[4.5, 3, 2.5]} intensity={2.4} color={ORO} />
      <directionalLight position={[-4, 1.5, -2]} intensity={0.8} color="#ffd9a0" />
      <Suspense fallback={null}>
        <Environment preset="studio" environmentIntensity={0.9} />
      </Suspense>
      <Escenario />
      <ContactShadows position={[0, -1.75, 0]} opacity={0.55} scale={10} blur={2.8} far={3.4} resolution={512} color="#000000" />
    </Canvas>
  )
}
