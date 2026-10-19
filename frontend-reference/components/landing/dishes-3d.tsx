'use client'

import { Component, useEffect, useLayoutEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import * as THREE from 'three'
import { Canvas, useFrame, useThree } from '@react-three/fiber'
import { ContactShadows, Environment, Float, Lightformer, OrbitControls, RoundedBox } from '@react-three/drei'
import type { OrbitControls as OrbitControlsImpl } from 'three-stdlib'

export type DishId = 'teriyaki' | 'tinga' | 'lasana' | 'quinoa'

type Vec3 = [number, number, number]
type Item = { p: Vec3; r?: Vec3; s?: number | Vec3; c?: string }

const CREAM = '#f7f1e6'
const NAVY = '#1f3a4a'
const TERRACOTTA = '#cf6b3d'

function rng(seed: number) {
  let a = seed
  return () => {
    a = (a + 0x6d2b79f5) | 0
    let t = Math.imul(a ^ (a >>> 15), 1 | a)
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296
  }
}

/* ---------- piezas base ---------- */

const PLATE: [number, number][] = [[0, 0], [0.85, 0], [1.05, 0.04], [1.4, 0.2], [1.48, 0.22], [1.46, 0.25], [1.38, 0.25], [1.02, 0.11], [0, 0.09]]
const BOWL: [number, number][] = [[0, 0], [0.45, 0], [0.62, 0.04], [0.95, 0.3], [1.18, 0.62], [1.26, 0.85], [1.2, 0.87], [1.12, 0.84], [0.9, 0.5], [0.6, 0.2], [0, 0.12]]

function Lathe({ points, color, roughness = 0.3 }: { points: [number, number][]; color: string; roughness?: number }) {
  const geometry = useMemo(() => new THREE.LatheGeometry(points.map(([x, y]) => new THREE.Vector2(x, y)), 64), [points])
  useEffect(() => () => geometry.dispose(), [geometry])
  return (
    <mesh geometry={geometry} castShadow receiveShadow>
      <meshStandardMaterial color={color} roughness={roughness} side={THREE.DoubleSide} />
    </mesh>
  )
}

/** Muchas copias de una misma geometría en una sola llamada de dibujo. */
function Scatter({ items, children }: { items: Item[]; children: ReactNode }) {
  const ref = useRef<THREE.InstancedMesh>(null)
  useLayoutEffect(() => {
    const mesh = ref.current
    if (!mesh) return
    const o = new THREE.Object3D()
    const color = new THREE.Color()
    items.forEach((it, i) => {
      o.position.set(...it.p)
      o.rotation.set(...(it.r ?? [0, 0, 0]))
      if (typeof it.s === 'number') o.scale.setScalar(it.s)
      else if (it.s) o.scale.set(...it.s)
      else o.scale.setScalar(1)
      o.updateMatrix()
      mesh.setMatrixAt(i, o.matrix)
      if (it.c) mesh.setColorAt(i, color.set(it.c))
    })
    mesh.instanceMatrix.needsUpdate = true
    if (mesh.instanceColor) mesh.instanceColor.needsUpdate = true
  }, [items])
  return (
    <instancedMesh ref={ref} args={[undefined, undefined, items.length]} castShadow>
      {children}
    </instancedMesh>
  )
}

function Food({ color, roughness = 0.75, flat = true }: { color: string; roughness?: number; flat?: boolean }) {
  return <meshStandardMaterial color={color} roughness={roughness} flatShading={flat} />
}

/* ---------- 1 · Pollo teriyaki con arroz ---------- */

const RICE_R = 1.12
const riceY = (r: number) => 0.62 + 0.56 * Math.sqrt(Math.max(0, 1 - (r / RICE_R) ** 2))

function Teriyaki() {
  const { chicken, broccoli, carrots, sesame } = useMemo(() => {
    const rand = rng(11)
    const ring = (count: number, radius: number, jitter: number, offset = 0) =>
      Array.from({ length: count }, (_, i) => {
        const a = offset + (i / count) * Math.PI * 2 + (rand() - 0.5) * jitter
        const r = radius + (rand() - 0.5) * 0.12
        return { a, r, x: Math.cos(a) * r, z: Math.sin(a) * r }
      })
    return {
      chicken: ring(7, 0.5, 0.3).map((q) => ({ ...q, rot: rand() * Math.PI, tilt: (rand() - 0.5) * 0.3 })),
      broccoli: ring(4, 0.78, 0.5, 0.4),
      carrots: ring(6, 0.3, 1, 0.2).map((q) => ({ ...q, tilt: rand() * 0.8 })),
      sesame: Array.from({ length: 46 }, (): Item => {
        const a = rand() * Math.PI * 2
        const r = Math.sqrt(rand()) * 0.85
        return { p: [Math.cos(a) * r, riceY(r) + 0.2, Math.sin(a) * r], r: [rand() * 3, rand() * 3, rand() * 3], s: [0.035, 0.02, 0.025], c: rand() > 0.8 ? '#2a2a2a' : '#fff4d6' }
      }),
    }
  }, [])

  return (
    <group>
      <Lathe points={BOWL} color={NAVY} />
      <mesh position={[0, 0.62, 0]} scale={[1, 0.5, 1]} castShadow>
        <icosahedronGeometry args={[RICE_R, 3]} />
        <Food color="#fffaf0" roughness={0.9} />
      </mesh>
      {chicken.map((q, i) => (
        <RoundedBox key={i} args={[0.52, 0.2, 0.34]} radius={0.07} smoothness={3} position={[q.x, riceY(q.r) + 0.08, q.z]} rotation={[q.tilt, q.rot, q.tilt]} castShadow>
          <meshStandardMaterial color="#8a3f14" roughness={0.35} flatShading />
        </RoundedBox>
      ))}
      {broccoli.map((q, i) => (
        <group key={i} position={[q.x, riceY(q.r) + 0.05, q.z]} rotation={[0, q.a, 0]}>
          <mesh position={[0, 0.08, 0]} castShadow>
            <cylinderGeometry args={[0.06, 0.09, 0.26, 8]} />
            <Food color="#8fbb55" />
          </mesh>
          {([[0, 0.3, 0, 0.17], [0.12, 0.24, 0.05, 0.12], [-0.1, 0.25, -0.06, 0.13], [0.02, 0.26, -0.12, 0.11]] as const).map(([x, y, z, s], j) => (
            <mesh key={j} position={[x, y, z]} castShadow>
              <icosahedronGeometry args={[s, 1]} />
              <Food color="#2f7d32" />
            </mesh>
          ))}
        </group>
      ))}
      {carrots.map((q, i) => (
        <mesh key={i} position={[q.x, riceY(q.r) + 0.05, q.z]} rotation={[q.tilt, q.a, q.tilt]} castShadow>
          <cylinderGeometry args={[0.11, 0.11, 0.045, 14]} />
          <Food color="#ec8a2c" roughness={0.5} />
        </mesh>
      ))}
      <Scatter items={sesame}>
        <sphereGeometry args={[1, 6, 6]} />
        <meshStandardMaterial roughness={0.6} />
      </Scatter>
    </group>
  )
}

/* ---------- 2 · Tinga de setas ---------- */

function Tinga() {
  const toppings = useMemo(() => {
    const rand = rng(23)
    return [90, 210, 330].map((deg) => {
      const a = (deg * Math.PI) / 180
      return {
        x: Math.cos(a) * 0.6,
        z: Math.sin(a) * 0.6,
        strips: Array.from({ length: 6 }, () => ({ x: (rand() - 0.5) * 0.5, z: (rand() - 0.5) * 0.5, rot: rand() * Math.PI, dark: rand() > 0.5 })),
        cilantro: Array.from({ length: 7 }, () => ({ x: (rand() - 0.5) * 0.55, z: (rand() - 0.5) * 0.55, rot: rand() * 3 })),
      }
    })
  }, [])

  return (
    <group>
      <Lathe points={PLATE} color={NAVY} />
      {toppings.map((t, i) => (
        <group key={i} position={[t.x, 0.13, t.z]}>
          <mesh castShadow>
            <cylinderGeometry args={[0.5, 0.5, 0.045, 28]} />
            <Food color="#e0b26a" roughness={0.8} flat={false} />
          </mesh>
          <mesh position={[0, 0.07, 0]} scale={[1, 0.42, 1]} castShadow>
            <icosahedronGeometry args={[0.4, 2]} />
            <Food color="#a83a1c" roughness={0.55} />
          </mesh>
          {t.strips.map((s, j) => (
            <RoundedBox key={j} args={[0.2, 0.06, 0.07]} radius={0.025} position={[s.x, 0.17, s.z]} rotation={[0, s.rot, 0]} castShadow>
              <meshStandardMaterial color={s.dark ? '#5a3a28' : '#d2b38a'} roughness={0.7} flatShading />
            </RoundedBox>
          ))}
          <mesh position={[0, 0.2, 0]} scale={[1, 0.55, 1]} castShadow>
            <sphereGeometry args={[0.1, 14, 14]} />
            <meshStandardMaterial color="#fffdf5" roughness={0.4} />
          </mesh>
          {t.cilantro.map((c, j) => (
            <mesh key={j} position={[c.x, 0.2, c.z]} rotation={[c.rot, c.rot * 2, 0]} scale={[1, 0.35, 0.7]}>
              <icosahedronGeometry args={[0.055, 0]} />
              <Food color="#3e9a3b" />
            </mesh>
          ))}
          <mesh position={[0.18, 0.2, -0.16]} rotation={[Math.PI / 2, 0, 0.4]} castShadow>
            <torusGeometry args={[0.1, 0.014, 6, 18]} />
            <Food color="#b05a8a" roughness={0.5} flat={false} />
          </mesh>
        </group>
      ))}
      <group position={[0, 0.14, 0]} rotation={[0, 0.5, 0]}>
        <mesh rotation={[0, 0, Math.PI / 2]} castShadow>
          <cylinderGeometry args={[0.22, 0.22, 0.12, 20, 1, false, 0, Math.PI]} />
          <meshStandardMaterial color="#9ccc3c" roughness={0.5} side={THREE.DoubleSide} />
        </mesh>
      </group>
    </group>
  )
}

/* ---------- 3 · Lasaña de verduras ---------- */

const LAYERS = [
  { h: 0.16, c: '#efd9a2' },
  { h: 0.14, c: '#c1432a' },
  { h: 0.12, c: '#4f8f3a' },
  { h: 0.16, c: '#efd9a2' },
  { h: 0.14, c: '#c1432a' },
  { h: 0.16, c: '#efd9a2' },
  { h: 0.1, c: '#f2c14e' },
]

function LayerStack({ width, depth = 1.3 }: { width: number; depth?: number }) {
  let y = 0
  return (
    <group>
      {LAYERS.map((l, i) => {
        const cy = y + l.h / 2
        y += l.h
        const sauce = l.c === '#c1432a'
        return (
          <RoundedBox key={i} args={[width + (sauce ? 0.05 : 0), l.h, depth + (sauce ? 0.05 : 0)]} radius={0.03} smoothness={3} position={[0, cy, 0]} castShadow>
            <meshStandardMaterial color={l.c} roughness={sauce ? 0.35 : 0.8} flatShading />
          </RoundedBox>
        )
      })}
    </group>
  )
}

function Lasana() {
  const top = LAYERS.reduce((sum, l) => sum + l.h, 0)
  const spots = useMemo(() => {
    const rand = rng(31)
    return Array.from({ length: 14 }, (): Item => ({ p: [(rand() - 0.5) * 1.3 - 0.2, top + 0.005, (rand() - 0.5) * 1.05], s: [0.09 + rand() * 0.07, 0.025, 0.08 + rand() * 0.05], c: '#b8651b' }))
  }, [top])

  return (
    <group rotation={[0, -0.3, 0]}>
      <RoundedBox args={[3.1, 0.14, 2.7]} radius={0.06} smoothness={4} position={[0, 0.07, 0]} castShadow receiveShadow>
        <meshStandardMaterial color={CREAM} roughness={0.3} />
      </RoundedBox>
      <group position={[-0.3, 0.14, 0]}>
        <group position={[-0.2, 0, 0]}>
          <LayerStack width={1.5} />
          <Scatter items={spots}>
            <sphereGeometry args={[1, 8, 8]} />
            <meshStandardMaterial roughness={0.7} />
          </Scatter>
        </group>
        <group position={[1.05, 0, 0.1]} rotation={[0, 0.14, 0]}>
          <LayerStack width={0.45} depth={1.25} />
        </group>
        {([[-0.7, top + 0.03, -0.2, 0.4], [-0.1, top + 0.03, 0.25, -0.6], [0.35, top + 0.03, -0.25, 1.1]] as const).map(([x, y, z, rot], i) => (
          <mesh key={i} position={[x, y, z]} rotation={[0.15, rot, 0.1]} scale={[0.26, 0.04, 0.14]} castShadow>
            <sphereGeometry args={[1, 10, 8]} />
            <Food color="#2f8a3a" roughness={0.45} flat={false} />
          </mesh>
        ))}
      </group>
    </group>
  )
}

/* ---------- 4 · Ensalada de quinoa ---------- */

const MOUND_R = 1.1
const moundY = (r: number) => 0.6 + 0.46 * Math.sqrt(Math.max(0, 1 - (r / MOUND_R) ** 2))

function Quinoa() {
  const { grains, chickpeas, cucumbers, tomatoes, leaves } = useMemo(() => {
    const rand = rng(47)
    const onMound = (rMax: number, lift: number): Vec3 => {
      const a = rand() * Math.PI * 2
      const r = Math.sqrt(rand()) * rMax
      return [Math.cos(a) * r, moundY(r) + lift, Math.sin(a) * r]
    }
    const ring = (count: number, radius: number, jitter: number) =>
      Array.from({ length: count }, (_, i) => {
        const a = (i / count) * Math.PI * 2 + (rand() - 0.5) * jitter
        const r = radius + (rand() - 0.5) * 0.15
        return { a, x: Math.cos(a) * r, z: Math.sin(a) * r, r }
      })
    const tones = ['#e8d49a', '#cfae6a', '#f1e2b5']
    return {
      grains: Array.from({ length: 260 }, (): Item => ({ p: onMound(1.0, 0.03), s: 0.04, c: tones[Math.floor(rand() * 3)] })),
      chickpeas: Array.from({ length: 14 }, () => onMound(0.85, 0.06)),
      cucumbers: ring(7, 0.55, 0.5).map((q) => ({ ...q, tilt: 0.3 + rand() * 0.5 })),
      tomatoes: ring(5, 0.25, 1.2),
      leaves: ring(10, 0.98, 0.3).map((q) => ({ ...q, rot: rand() * 3, lift: rand() * 0.1 })),
    }
  }, [])

  return (
    <group>
      <Lathe points={BOWL} color={TERRACOTTA} roughness={0.5} />
      <mesh position={[0, 0.6, 0]} scale={[1, 0.42, 1]} castShadow>
        <icosahedronGeometry args={[MOUND_R, 3]} />
        <Food color="#d9bd7e" roughness={0.95} />
      </mesh>
      <Scatter items={grains}>
        <sphereGeometry args={[1, 6, 6]} />
        <meshStandardMaterial roughness={0.9} />
      </Scatter>
      {chickpeas.map((p, i) => (
        <mesh key={i} position={p} castShadow>
          <icosahedronGeometry args={[0.09, 1]} />
          <Food color="#d9a441" roughness={0.6} />
        </mesh>
      ))}
      {cucumbers.map((q, i) => (
        <group key={i} position={[q.x, moundY(q.r) + 0.1, q.z]} rotation={[q.tilt * Math.cos(q.a), -q.a, q.tilt * Math.sin(q.a)]}>
          <mesh castShadow>
            <cylinderGeometry args={[0.18, 0.18, 0.04, 20]} />
            <Food color="#7fb85c" roughness={0.4} flat={false} />
          </mesh>
          <mesh position={[0, 0.001, 0]}>
            <cylinderGeometry args={[0.14, 0.14, 0.044, 20]} />
            <Food color="#d9efc2" roughness={0.4} flat={false} />
          </mesh>
        </group>
      ))}
      {tomatoes.map((q, i) => (
        <group key={i} position={[q.x, moundY(q.r) + 0.1, q.z]}>
          <mesh castShadow>
            <sphereGeometry args={[0.15, 16, 16]} />
            <meshStandardMaterial color="#d8352a" roughness={0.25} />
          </mesh>
          <mesh position={[0, 0.14, 0]}>
            <cylinderGeometry args={[0.03, 0.04, 0.04, 6]} />
            <Food color="#3e8a35" />
          </mesh>
        </group>
      ))}
      {leaves.map((q, i) => (
        <mesh key={i} position={[q.x, 0.86 + q.lift, q.z]} rotation={[0.4 * Math.sin(q.a), q.rot, 0.4 * Math.cos(q.a)]} scale={[0.3, 0.05, 0.15]} castShadow>
          <sphereGeometry args={[1, 10, 8]} />
          <Food color="#3f8f3d" roughness={0.5} flat={false} />
        </mesh>
      ))}
    </group>
  )
}

const MODELS: Record<DishId, () => ReactNode> = {
  teriyaki: () => <Teriyaki />,
  tinga: () => <Tinga />,
  lasana: () => <Lasana />,
  quinoa: () => <Quinoa />,
}

/* ---------- escenas ---------- */

function Studio() {
  return (
    <>
      <ambientLight intensity={0.55} />
      <directionalLight position={[4, 7, 4]} intensity={2.2} />
      <directionalLight position={[-5, 3, -3]} intensity={0.6} color="#ffe2a8" />
      <Environment resolution={256} frames={1}>
        <Lightformer form="rect" intensity={2.4} position={[0, 5, 2]} scale={[8, 3, 1]} rotation-x={Math.PI / 2} />
        <Lightformer form="rect" intensity={1.2} position={[-5, 2, 3]} scale={[4, 4, 1]} color="#fff1cf" />
        <Lightformer form="rect" intensity={0.8} position={[5, 1, -2]} scale={[3, 5, 1]} color="#cfe6ff" />
      </Environment>
    </>
  )
}

/** Escala, giro por clic y “pop” al pasar el cursor. */
function Pop({ children, scale = 1, idle = 0.18, reduced }: { children: ReactNode; scale?: number; idle?: number; reduced: boolean }) {
  const group = useRef<THREE.Group>(null)
  const [hover, setHover] = useState(false)
  const spin = useRef({ target: 0, current: 0 })

  useFrame((state, dt) => {
    const g = group.current
    if (!g) return
    g.scale.setScalar(THREE.MathUtils.damp(g.scale.x, scale * (hover ? 1.08 : 1), 8, dt))
    spin.current.current = THREE.MathUtils.damp(spin.current.current, spin.current.target, 4, dt)
    g.rotation.y = spin.current.current + (reduced ? 0 : state.clock.elapsedTime * idle)
  })

  useEffect(() => () => { document.body.style.cursor = '' }, [])

  return (
    <group
      ref={group}
      scale={scale}
      onPointerOver={(e) => { e.stopPropagation(); setHover(true); document.body.style.cursor = 'pointer' }}
      onPointerOut={() => { setHover(false); document.body.style.cursor = '' }}
      onClick={(e) => { e.stopPropagation(); spin.current.target += Math.PI * 2 }}
    >
      {children}
    </group>
  )
}

const HERO_LAYOUT: { id: DishId; position: Vec3; scale: number; speed: number }[] = [
  { id: 'teriyaki', position: [0.15, 0, 0.9], scale: 1.12, speed: 1.4 },
  { id: 'tinga', position: [-2.7, 0.15, -0.6], scale: 0.86, speed: 1.1 },
  { id: 'lasana', position: [2.75, 0.1, -0.5], scale: 0.8, speed: 1.2 },
  { id: 'quinoa', position: [-0.4, 0.2, -2.7], scale: 0.9, speed: 1 },
]

function HeroCluster({ reduced }: { reduced: boolean }) {
  const root = useRef<THREE.Group>(null)
  const width = useThree((s) => s.size.width)
  const fit = THREE.MathUtils.clamp(width / 720, 0.5, 1)

  useFrame((state, dt) => {
    const g = root.current
    if (!g) return
    const { x, y } = reduced ? { x: 0, y: 0 } : state.pointer
    g.rotation.y = THREE.MathUtils.damp(g.rotation.y, x * 0.45, 3, dt)
    g.rotation.x = THREE.MathUtils.damp(g.rotation.x, -y * 0.12, 3, dt)
  })

  return (
    <group ref={root} scale={fit} position={[0, -0.5, 0]}>
      {HERO_LAYOUT.map((d) => (
        <group key={d.id} position={d.position}>
          <Float speed={d.speed} rotationIntensity={reduced ? 0 : 0.25} floatIntensity={reduced ? 0 : 0.7} floatingRange={[-0.08, 0.12]}>
            <Pop scale={d.scale} reduced={reduced}>{MODELS[d.id]()}</Pop>
          </Float>
        </group>
      ))}
      <ContactShadows position={[0, -0.02, -0.5]} opacity={0.38} scale={14} blur={2.6} far={4} />
    </group>
  )
}

/** Cambia de platillo encogiendo el actual y haciendo crecer el nuevo. */
function DishSwitcher({ dish }: { dish: DishId }) {
  const group = useRef<THREE.Group>(null)
  const [shown, setShown] = useState(dish)
  const width = useThree((s) => s.size.width)
  const fit = THREE.MathUtils.clamp(width / 520, 0.6, 1)

  useFrame((_, dt) => {
    const g = group.current
    if (!g) return
    const leaving = shown !== dish
    const next = THREE.MathUtils.damp(g.scale.x, leaving ? 0.001 : fit, leaving ? 14 : 7, dt)
    g.scale.setScalar(next)
    if (leaving && next < fit * 0.04) setShown(dish)
  })

  return (
    <>
      <group ref={group} scale={0.001} position={[0, -0.4, 0]}>{MODELS[shown]()}</group>
      <ContactShadows position={[0, -0.42, 0]} opacity={0.4} scale={9} blur={2.4} far={3} />
    </>
  )
}

/* ---------- contenedor ---------- */

class SceneBoundary extends Component<{ fallback: ReactNode; children: ReactNode }, { failed: boolean }> {
  state = { failed: false }
  static getDerivedStateFromError() { return { failed: true } }
  render() { return this.state.failed ? this.props.fallback : this.props.children }
}

/** Pausa el render cuando el lienzo sale de pantalla para no gastar batería. */
function Frame({ children, className, fallback, camera }: { children: ReactNode; className?: string; fallback: ReactNode; camera: { position: Vec3; fov: number } }) {
  const host = useRef<HTMLDivElement>(null)
  const [visible, setVisible] = useState(true)

  useEffect(() => {
    const el = host.current
    if (!el) return
    const io = new IntersectionObserver(([entry]) => setVisible(entry.isIntersecting), { rootMargin: '120px' })
    io.observe(el)
    return () => io.disconnect()
  }, [])

  return (
    <div ref={host} className={className}>
      <SceneBoundary fallback={fallback}>
        <Canvas frameloop={visible ? 'always' : 'never'} dpr={[1, 1.75]} camera={camera} gl={{ alpha: true, antialias: true }} onCreated={({ camera: c }) => c.lookAt(0, 0.4, 0)} style={{ touchAction: 'pan-y' }}>
          <Studio />
          {children}
        </Canvas>
      </SceneBoundary>
    </div>
  )
}

export function HeroScene({ className, reduced, fallback }: { className?: string; reduced: boolean; fallback: ReactNode }) {
  return (
    <Frame className={className} fallback={fallback} camera={{ position: [0, 4.4, 8.6], fov: 32 }}>
      <HeroCluster reduced={reduced} />
    </Frame>
  )
}

/** OrbitControls fuerza touch-action: none; lo devolvemos a pan-y para que en móvil se pueda seguir haciendo scroll. */
function Orbit({ reduced }: { reduced: boolean }) {
  const controls = useRef<OrbitControlsImpl>(null)
  useEffect(() => {
    const el = controls.current?.domElement as HTMLElement | undefined
    if (el) el.style.touchAction = 'pan-y'
  }, [])
  return (
    <OrbitControls
      ref={controls}
      enablePan={false}
      enableZoom={false}
      enableDamping
      autoRotate={!reduced}
      autoRotateSpeed={1.4}
      minPolarAngle={Math.PI / 5}
      maxPolarAngle={Math.PI / 2.15}
      target={[0, 0.4, 0]}
    />
  )
}

export function DishViewer({ dish, className, reduced, fallback }: { dish: DishId; className?: string; reduced: boolean; fallback: ReactNode }) {
  return (
    <Frame className={className} fallback={fallback} camera={{ position: [0, 3.4, 5.8], fov: 32 }}>
      <DishSwitcher dish={dish} />
      <Orbit reduced={reduced} />
    </Frame>
  )
}
