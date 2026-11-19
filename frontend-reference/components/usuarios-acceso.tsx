'use client'

import { useRef, useState, type FormEvent } from 'react'
import { toast } from 'sonner'
import { CircleAlert, ExternalLink, Pencil, Power, RotateCw, Upload, UserPlus, X } from 'lucide-react'
import { CampoLista, CampoTexto, VentanaConfirmar, VentanaFormulario } from '@/components/ventanas'
import { FilasEstado } from '@/components/estados'
import { Pill } from '@/components/panel'
import { Button } from '@/components/ui/button'
import { cambiarEstadoUsuarioAcceso, editarUsuarioAcceso, invitarUsuario, invitarVariosUsuarios, listarUsuariosAcceso, reenviarInvitacion } from '@/lib/api'
import { etiquetasRol } from '@/lib/etiquetas'
import { formatDate } from '@/lib/format'
import { PORTALES } from '@/lib/portales'
import type { CorreoOmitido, EstadoInvitacion, InvitacionDetalle, Rol, TipoOrganizacion, UsuarioAcceso } from '@/lib/types'
import { ARCHIVOS_DE_EMPLEADOS, correoValido, leerArchivoDeEmpleados, MOTIVOS_DE_OMISION, type PersonaDelArchivo } from '@/lib/empleados-archivo'
import { useAsync } from '@/lib/use-async'

const portalDe = { comedor: 'comedores', empresa: 'empresas', plataforma: 'admin' } as const
const etiquetasEstado: Record<EstadoInvitacion, string> = { enviada: 'Enviada', aceptada: 'Aceptada', vencida: 'Vencida' }
const tonos = { enviada: 'info', aceptada: 'success', vencida: 'danger' } as const

export const PillInvitacion = ({ estado }: { estado: EstadoInvitacion }) => <Pill tono={tonos[estado]}>{etiquetasEstado[estado]}</Pill>

/** Reenviar una invitación enviada o vencida. `onReenviada` recarga la lista de quien lo usa. */
export function BotonReenviar({ invitacion, onReenviada, compacto }: { invitacion: InvitacionDetalle; onReenviada: () => void; compacto?: boolean }) {
  const [enviando, setEnviando] = useState(false)
  if (invitacion.estado === 'aceptada') return null
  async function reenviar() {
    setEnviando(true)
    try {
      await reenviarInvitacion(invitacion.id)
      toast.success(`Invitación reenviada a ${invitacion.correo}`)
      onReenviada()
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo reenviar la invitación')
    } finally {
      setEnviando(false)
    }
  }
  return <Button variant={invitacion.estado === 'vencida' ? 'default' : 'outline'} size={compacto ? 'sm' : 'default'} onClick={reenviar} disabled={enviando}><RotateCw className={enviando ? 'animate-spin' : ''} /> {enviando ? 'Reenviando…' : 'Reenviar'}</Button>
}

/** Texto bajo la etiqueta: cuándo se envió o cuándo vence. */
export const detalleInvitacion = (i: InvitacionDetalle) =>
  i.estado === 'aceptada' ? `Aceptada el ${formatDate((i.aceptada_en ?? i.enviada_en).slice(0, 10))}` : i.estado === 'vencida' ? `Venció el ${formatDate(i.vence_en.slice(0, 10))}` : `Vence el ${formatDate(i.vence_en.slice(0, 10))}`

/** Enlace de la invitación; en producción llega por correo, aquí se abre para probar el flujo. */
export const enlaceInvitacion = (tipo: TipoOrganizacion, token: string) => `${PORTALES[portalDe[tipo]].base}/aceptar-invitacion?token=${token}`

export function UsuariosAcceso({ organizacion, roles, vacioPlural = 'usuarios' }: { organizacion: { tipo: TipoOrganizacion; id: string | null }; roles: Rol[]; vacioPlural?: string }) {
  const usuarios = useAsync(() => listarUsuariosAcceso(organizacion), [organizacion.tipo, organizacion.id])
  const [invitando, setInvitando] = useState(false)
  const [editando, setEditando] = useState<UsuarioAcceso | null>(null)
  const [desactivando, setDesactivando] = useState<UsuarioAcceso | null>(null)
  const [cambiando, setCambiando] = useState(false)
  const lista = usuarios.data ?? []

  async function cambiarEstado(u: UsuarioAcceso, activo: boolean) {
    setCambiando(true)
    try {
      await cambiarEstadoUsuarioAcceso(u.usuario_id, activo)
      toast.success(activo ? `${u.nombre} quedó activo` : `${u.nombre} quedó inactivo`, { description: activo ? undefined : 'Ya no puede entrar, pero conserva su historial.' })
      setDesactivando(null)
      usuarios.reload({ silent: true })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo cambiar el estado del usuario')
    } finally {
      setCambiando(false)
    }
  }

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-muted-foreground">Las invitaciones vencen a los 7 días; puedes reenviarlas cuando quieras.</p>
        <Button onClick={() => setInvitando(true)}><UserPlus /> Invitar usuario</Button>
      </div>
      <div className="overflow-hidden rounded-xl border bg-card">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-left text-sm">
            <thead className="border-b bg-muted/40 text-xs uppercase text-muted-foreground"><tr><th className="px-5 py-3">Usuario</th><th className="px-5 py-3">Rol</th><th className="px-5 py-3">Invitación</th><th className="px-5 py-3"><span className="sr-only">Acciones</span></th></tr></thead>
            <tbody>
              <FilasEstado columnas={4} filas={3} cargando={usuarios.loading} error={usuarios.error} vacio={!lista.length} mensajeVacio={`Todavía no hay ${vacioPlural} con acceso.`} onReintentar={usuarios.reload} />
              {lista.map((u) => (
                <tr key={u.usuario_id} className="border-b last:border-0">
                  <td className="px-5 py-4"><p className="font-semibold">{u.nombre}</p><p className="text-xs text-muted-foreground">{u.correo}</p></td>
                  <td className="px-5 py-4 text-muted-foreground">{etiquetasRol[u.rol]}</td>
                  <td className="px-5 py-4">
                    {u.es_principal && <Pill tono="brand">Titular</Pill>}{u.invitacion && <div className={u.es_principal ? "mt-1.5" : ""}><PillInvitacion estado={u.invitacion.estado} /><p className="mt-1 text-xs text-muted-foreground">{detalleInvitacion(u.invitacion)}</p></div>}
                  </td>
                  <td className="px-5 py-4">
                    <div className="flex items-center justify-end gap-1">
                      <button type="button" onClick={() => setEditando(u)} title="Editar" aria-label={`Editar a ${u.nombre}`} className="rounded-lg p-2 text-muted-foreground transition hover:bg-muted hover:text-foreground"><Pencil className="size-4" /></button>
                      {!u.es_principal && u.invitacion?.estado === 'aceptada' && (u.activo
                        ? <button type="button" onClick={() => setDesactivando(u)} title="Desactivar" aria-label={`Desactivar a ${u.nombre}`} className="rounded-lg p-2 text-muted-foreground transition hover:bg-destructive/10 hover:text-destructive"><Power className="size-4" /></button>
                        : <button type="button" onClick={() => cambiarEstado(u, true)} disabled={cambiando} title="Reactivar" aria-label={`Reactivar a ${u.nombre}`} className="rounded-lg p-2 text-muted-foreground transition hover:bg-muted hover:text-foreground"><Power className="size-4" /></button>)}
                    </div>
                    {u.invitacion && u.invitacion.estado !== 'aceptada' && (
                      <div className="mt-1 flex items-center justify-end gap-1">
                        {u.invitacion.token && <a href={enlaceInvitacion(organizacion.tipo, u.invitacion.token)} target="_blank" rel="noreferrer" title="Abrir el enlace de la invitación (solo maqueta)" aria-label={`Abrir el enlace de la invitación de ${u.nombre} (solo maqueta)`} className="rounded-lg p-2 text-muted-foreground transition hover:bg-muted hover:text-foreground"><ExternalLink className="size-4" /></a>}
                        <BotonReenviar invitacion={u.invitacion} compacto onReenviada={() => usuarios.reload({ silent: true })} />
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
      {invitando && <InvitarModal organizacion={organizacion} roles={roles} onCerrar={() => setInvitando(false)} onInvitado={() => { setInvitando(false); usuarios.reload({ silent: true }) }} onActualizar={() => usuarios.reload({ silent: true })} />}
      {editando && <EditarUsuarioModal usuario={editando} roles={roles} onCerrar={() => setEditando(null)} onGuardado={() => { setEditando(null); usuarios.reload({ silent: true }) }} />}
      {desactivando && <VentanaConfirmar titulo={`¿Desactivar a ${desactivando.nombre}?`} descripcion="Ya no podrá entrar a EasyLunch. No se borra: conserva su historial y puedes reactivarlo cuando quieras." textoConfirmar="Desactivar" trabajando={cambiando} onCerrar={() => setDesactivando(null)} onConfirmar={() => cambiarEstado(desactivando, false)} />}
    </div>
  )
}

/** Editar el nombre y el rol de una persona con acceso (el correo es su acceso y no se cambia aquí). */
function EditarUsuarioModal({ usuario, roles, onCerrar, onGuardado }: { usuario: UsuarioAcceso; roles: Rol[]; onCerrar: () => void; onGuardado: () => void }) {
  const correoEditable = !!usuario.invitacion && usuario.invitacion.estado !== 'aceptada'
  const [correo, setCorreo] = useState(usuario.correo)
  const [nombre, setNombre] = useState(usuario.nombre)
  const [rol, setRol] = useState<Rol>(usuario.rol)
  const [guardando, setGuardando] = useState(false)
  const opciones = roles.includes(usuario.rol) ? roles : [usuario.rol, ...roles]
  async function guardar() {
    setGuardando(true)
    try {
      await editarUsuarioAcceso(usuario.usuario_id, { nombre, rol, ...(correoEditable && correo.trim().toLowerCase() !== usuario.correo.toLowerCase() ? { correo: correo.trim() } : {}) })
      toast.success('Cambios guardados')
      onGuardado()
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo guardar al usuario')
    } finally {
      setGuardando(false)
    }
  }
  return (
    <VentanaFormulario titulo={`Editar a ${usuario.nombre}`} textoEnviar="Guardar cambios" trabajando={guardando} deshabilitarEnvio={!nombre.trim()} onCerrar={onCerrar} onEnviar={guardar}>
      <CampoTexto etiqueta="Nombre completo" required autoFocus value={nombre} onChange={(e) => setNombre(e.target.value)} />
      {correoEditable
        ? <CampoTexto etiqueta="Correo" type="email" required value={correo} onChange={(e) => setCorreo(e.target.value)} ayuda="Aún no acepta su invitación: si lo cambias, le mandamos la invitación al correo nuevo." />
        : <CampoTexto etiqueta="Correo" value={usuario.correo} disabled readOnly ayuda="Es su acceso a EasyLunch y ya lo está usando; no se cambia aquí." />}
      {opciones.length > 1 && !usuario.es_principal && <CampoLista etiqueta="Rol" value={rol} onChange={(e) => setRol(e.target.value as Rol)} opciones={opciones.map((r) => ({ valor: r, texto: etiquetasRol[r] }))} />}
    </VentanaFormulario>
  )
}

function InvitarModal({ organizacion, roles, onCerrar, onInvitado, onActualizar }: { organizacion: { tipo: TipoOrganizacion; id: string | null }; roles: Rol[]; onCerrar: () => void; onInvitado: () => void; onActualizar: () => void }) {
  const [modo, setModo] = useState<'uno' | 'archivo'>('uno')
  const [nombre, setNombre] = useState('')
  const [correo, setCorreo] = useState('')
  const [rol, setRol] = useState<Rol>(roles[0])
  const [enviando, setEnviando] = useState(false)
  // Modo archivo: personas leídas del CSV o Excel, filas que no se pueden invitar y, al terminar, los correos omitidos.
  const [personas, setPersonas] = useState<PersonaDelArchivo[]>([])
  const [descartadas, setDescartadas] = useState<{ nombre: string; correo: string; motivo: string }[]>([])
  const [nombreArchivo, setNombreArchivo] = useState('')
  const [resultado, setResultado] = useState<{ invitados: number; omitidos: CorreoOmitido[] } | null>(null)
  const archivo = useRef<HTMLInputElement>(null)

  async function enviar(e: FormEvent) {
    e.preventDefault()
    setEnviando(true)
    try {
      await invitarUsuario({ organizacion_tipo: organizacion.tipo, organizacion_id: organizacion.id, nombre, correo, rol })
      toast.success(`Invitación enviada a ${correo.trim()}`)
      onInvitado()
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo enviar la invitación')
    } finally {
      setEnviando(false)
    }
  }

  async function leerArchivo(input: HTMLInputElement) {
    const f = input.files?.[0]
    input.value = ''
    if (!f) return
    try {
      const leidas = await leerArchivoDeEmpleados(f)
      if (!leidas.length) return void toast.error('El archivo no tiene personas. Usa la columna A para el nombre y la B para el correo.')
      // Aquí el correo es obligatorio (es con lo que entran): sin correo o mal escrito no se puede invitar.
      const sinNombreValido = (p: PersonaDelArchivo) => p.nombre.trim().length < 2
      setPersonas(leidas.filter((p) => !sinNombreValido(p) && correoValido(p.correo)))
      setDescartadas(leidas.filter((p) => sinNombreValido(p) || !correoValido(p.correo)).map((p) => ({ ...p, motivo: !p.correo ? 'no tiene correo' : 'el correo está mal escrito' })))
      setNombreArchivo(f.name)
      setResultado(null)
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo leer el archivo', { duration: 12000 })
    }
  }

  async function enviarArchivo() {
    setEnviando(true)
    try {
      const r = await invitarVariosUsuarios({ organizacion_tipo: organizacion.tipo, organizacion_id: organizacion.id, rol, personas })
      if (r.omitidos === 0) {
        toast.success(`${r.invitados} invitaciones enviadas`)
        onInvitado()
      } else {
        onActualizar() // se refresca la lista de atrás, pero el diálogo sigue abierto para ver los omitidos
        // Con omitidos no se celebra ni se cierra: se muestra la lista para que se vea cuáles no salieron.
        toast.warning(r.invitados ? `${r.invitados} invitadas, pero ${r.omitidos} se omitieron` : `No se invitó a nadie: se omitieron ${r.omitidos}`)
        setResultado({ invitados: r.invitados, omitidos: r.correos_omitidos })
        setPersonas([])
        setDescartadas([])
      }
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudieron enviar las invitaciones')
    } finally {
      setEnviando(false)
    }
  }

  const selectorDeRol = roles.length > 1 && <label className="text-sm font-medium">Rol<select value={rol} onChange={(e) => setRol(e.target.value as Rol)} className="mt-1 h-11 w-full rounded-lg border bg-background px-3">{roles.map((r) => <option key={r} value={r}>{etiquetasRol[r]}</option>)}</select></label>
  const pestana = (valor: 'uno' | 'archivo', texto: string) => <button type="button" role="tab" aria-selected={modo === valor} onClick={() => !enviando && setModo(valor)} className={`flex-1 rounded-md px-3 py-1.5 text-sm font-medium ${modo === valor ? 'bg-background shadow-sm' : 'text-muted-foreground'}`}>{texto}</button>

  return (
    <div className="fixed inset-0 z-[70] grid place-items-center bg-black/40 p-4" onClick={() => !enviando && onCerrar()}>
      <form role="dialog" aria-modal="true" aria-label="Invitar usuario" onSubmit={modo === 'uno' ? enviar : (e) => { e.preventDefault(); void enviarArchivo() }} onClick={(e) => e.stopPropagation()} className="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-card p-6 shadow-xl">
        <div className="flex items-start justify-between gap-3"><div><h2 className="font-display text-xl font-bold">Invitar usuario</h2><p className="mt-1 text-sm text-muted-foreground">{modo === 'uno' ? 'Recibirá un correo para crear su contraseña y entrar.' : 'Cada persona del archivo recibirá un correo para crear su contraseña y entrar.'}</p></div><button type="button" onClick={onCerrar} disabled={enviando} aria-label="Cerrar"><X /></button></div>
        <div role="tablist" className="mt-4 flex gap-1 rounded-lg bg-muted p-1">{pestana('uno', 'Una persona')}{pestana('archivo', 'Desde un archivo')}</div>
        {modo === 'uno' ? (
          <>
            <div className="mt-5 flex flex-col gap-4">
              <label className="text-sm font-medium">Nombre completo<input required autoFocus value={nombre} onChange={(e) => setNombre(e.target.value)} className="mt-1 h-11 w-full rounded-lg border bg-background px-3" placeholder="Nombre y apellido" /></label>
              <label className="text-sm font-medium">Correo<input required type="email" value={correo} onChange={(e) => setCorreo(e.target.value)} className="mt-1 h-11 w-full rounded-lg border bg-background px-3" placeholder="nombre@correo.mx" /></label>
              {selectorDeRol}
            </div>
            <div className="mt-6 flex justify-end gap-2"><Button type="button" variant="outline" className="h-10 px-4" onClick={onCerrar} disabled={enviando}>Cancelar</Button><Button type="submit" className="h-10 px-4" disabled={enviando}>{enviando ? 'Enviando…' : 'Enviar invitación'}</Button></div>
          </>
        ) : (
          <>
            <div className="mt-5 flex flex-col gap-4">
              {selectorDeRol}
              <div className="rounded-lg border border-dashed p-4">
                <input ref={archivo} type="file" accept={ARCHIVOS_DE_EMPLEADOS} className="hidden" onChange={(e) => void leerArchivo(e.target)} />
                <Button type="button" variant="outline" className="h-10 px-4" onClick={() => archivo.current?.click()} disabled={enviando}><Upload /> {nombreArchivo ? 'Elegir otro archivo' : 'Elegir archivo CSV o Excel'}</Button>
                <p className="mt-2 text-xs text-muted-foreground">Excel (.xlsx) o CSV con dos columnas: <strong>nombre</strong> (A) y <strong>correo</strong> (B). Aquí el correo es obligatorio.</p>
                {nombreArchivo && <p className="mt-2 truncate text-sm font-medium">{nombreArchivo}</p>}
              </div>
              {personas.length > 0 && (
                <div className="rounded-lg border p-3">
                  <p className="text-sm font-semibold">{personas.length} persona(s) por invitar</p>
                  <ul className="mt-2 max-h-40 space-y-1 overflow-y-auto text-sm">{personas.map((p, i) => <li key={`${p.correo}-${i}`} className="truncate"><strong>{p.nombre}</strong> <span className="text-muted-foreground">{p.correo}</span></li>)}</ul>
                </div>
              )}
              {descartadas.length > 0 && (
                <div role="alert" className="rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
                  <p className="flex items-center gap-2 font-semibold"><CircleAlert className="size-4 text-warning" aria-hidden="true" /> {descartadas.length} fila(s) no se pueden invitar</p>
                  <ul className="mt-2 max-h-28 space-y-0.5 overflow-y-auto">{descartadas.map((d, i) => <li key={`${d.nombre}-${i}`}><strong>{d.nombre || '(sin nombre)'}</strong> {d.correo && <span className="break-all">{d.correo}</span>} — {d.motivo}</li>)}</ul>
                </div>
              )}
              {resultado && (
                <div role="alert" className="rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
                  <p className="flex items-center gap-2 font-semibold"><CircleAlert className="size-4 text-warning" aria-hidden="true" /> {resultado.invitados ? `Se invitó a ${resultado.invitados} y se omitieron ${resultado.omitidos.length}.` : `No se invitó a nadie: se omitieron ${resultado.omitidos.length}.`}</p>
                  <ul className="mt-2 max-h-40 space-y-0.5 overflow-y-auto">{resultado.omitidos.map((o, i) => <li key={`${o.correo}-${i}`}><strong className="break-all">{o.correo}</strong> — {o.nombre}: {MOTIVOS_DE_OMISION[o.motivo]}</li>)}</ul>
                </div>
              )}
            </div>
            <div className="mt-6 flex justify-end gap-2"><Button type="button" variant="outline" className="h-10 px-4" onClick={onCerrar} disabled={enviando}>{resultado ? 'Cerrar' : 'Cancelar'}</Button><Button type="submit" className="h-10 px-4" disabled={enviando || personas.length === 0}>{enviando ? 'Enviando…' : personas.length ? `Enviar ${personas.length} invitación(es)` : 'Enviar invitaciones'}</Button></div>
          </>
        )}
      </form>
    </div>
  )
}
