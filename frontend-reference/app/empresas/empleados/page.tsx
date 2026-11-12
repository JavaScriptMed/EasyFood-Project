'use client'

import { PageTitle } from '@/components/panel'
import { FormEvent, useRef, useState } from 'react'
import { toast } from 'sonner'
import { Eye, Pencil, Search, Upload, UserPlus, X } from 'lucide-react'
import { CampoTexto, GrillaDatos, PanelDetalle, VentanaFormulario } from '@/components/ventanas'
import { editarEmpleado } from '@/lib/api'
import { etiquetaMes } from '@/lib/format'
import { BotonReenviar, detalleInvitacion, PillInvitacion } from '@/components/usuarios-acceso'
import { ExternalLink } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card } from '@/components/ui/card'
import { useEmpresa } from '@/components/empresa-shell'
import { MES_ACTUAL } from '@/lib/config'

const unquote = (value: string) => value.trim().replace(/^"|"$/g, '').trim()

/** Lee un CSV "nombre,correo" (con o sin encabezado, separado por coma o punto y coma). */
function parseEmployeesCsv(text: string) {
  return text
    .replace(/^﻿/, '')
    .split(/\r?\n/)
    .map((line) => line.split(/[;,]/).map(unquote))
    .filter(([name]) => name && !/^(nombre|name)$/i.test(name))
    .map(([nombre, correo = '']) => ({ nombre, correo }))
}

export default function EmpresaEmpleados() {
  const { empleados: employees, agregarEmpleados, cambiarEstadoEmpleado, recargar } = useEmpresa()
  const [porDesactivar, setPorDesactivar] = useState<(typeof employees)[number] | null>(null)
  const [desactivando, setDesactivando] = useState(false)
  const [viendoId, setViendoId] = useState<string | null>(null)
  const [editandoId, setEditandoId] = useState<string | null>(null)
  const viendo = employees.find((e) => e.id === viendoId) ?? null
  const editando = employees.find((e) => e.id === editandoId) ?? null
  const [query, setQuery] = useState('')
  const [adding, setAdding] = useState(false)
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [saving, setSaving] = useState(false)
  const fileInput = useRef<HTMLInputElement>(null)

  const visible = employees.filter((e) => `${e.nombre} ${e.correo}`.toLowerCase().includes(query.trim().toLowerCase()))
  const activeCount = employees.filter((e) => e.activo).length

  async function submit(e: FormEvent) {
    e.preventDefault()
    setSaving(true)
    try {
      const { agregados } = await agregarEmpleados([{ nombre: name.trim(), correo: email.trim() }])
      if (agregados === 0) {
        toast.error('Ese correo ya está registrado')
        return
      }
      toast.success(`${name.trim()} agregado`, { description: 'Le enviamos una invitación para crear su contraseña.' })
      setName('')
      setEmail('')
      setAdding(false)
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo agregar al empleado')
    } finally {
      setSaving(false)
    }
  }

  async function toggle(id: string, activo: boolean) {
    try {
      await cambiarEstadoEmpleado(id, activo)
      return true
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo cambiar el estado del empleado')
      return false
    }
  }

  async function confirmarDesactivar() {
    if (!porDesactivar) return
    setDesactivando(true)
    if (await toggle(porDesactivar.id, false)) toast.success(`${porDesactivar.nombre} quedó inactivo`, { description: 'Conserva su historial y puedes reactivarlo cuando quieras.' })
    setDesactivando(false)
    setPorDesactivar(null)
  }

  async function upload(file: File | undefined) {
    if (!file) return
    const people = parseEmployeesCsv(await file.text())
    if (!people.length) toast.error('El archivo no tiene empleados. Usa las columnas nombre y correo.')
    else {
      try {
        const { agregados, omitidos } = await agregarEmpleados(people)
        toast.success(`${agregados} empleados agregados`, { description: omitidos > 0 ? `${omitidos} omitidos por correo repetido.` : undefined })
      } catch (error) {
        toast.error(error instanceof Error ? error.message : 'No se pudo procesar el archivo')
      }
    }
    if (fileInput.current) fileInput.current.value = ''
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-end justify-between gap-4 border-b pb-6">
        <div>
          <PageTitle eyebrow="Equipo" title="Empleados" description={<>{activeCount} activos de {employees.length} registrados.</>} />
        </div>
        <div className="flex flex-wrap gap-2">
          <input ref={fileInput} type="file" accept=".csv,text/csv" className="hidden" onChange={(e) => upload(e.target.files?.[0])} />
          <Button variant="outline" className="h-10 px-4" onClick={() => fileInput.current?.click()}><Upload /> Subir CSV</Button>
          <Button className="h-10 px-4" onClick={() => setAdding(true)}><UserPlus /> Agregar empleado</Button>
        </div>
      </div>

      <label className="relative mb-4 block max-w-md">
        <span className="sr-only">Buscar empleado</span>
        <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden="true" />
        <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Buscar por nombre o correo" className="h-10 w-full rounded-lg border bg-card pl-9 pr-3 text-sm outline-none focus:border-primary" />
      </label>

      <Card className="p-0">
        <div className="overflow-x-auto">
          <table className="w-full text-left text-sm">
            <thead className="border-b bg-muted/40 text-xs uppercase text-muted-foreground">
              <tr>
                <th className="px-4 py-3 sm:px-5">Nombre</th>
                <th className="hidden px-5 py-3 md:table-cell">Correo</th>
                <th className="px-3 py-3">Estado</th>
                <th className="px-3 py-3">Invitación</th>
                <th className="px-3 py-3 text-right"><span className="hidden sm:inline">Platillos del mes</span><span className="sm:hidden">Mes</span></th>
                <th className="px-4 py-3 sm:px-5"><span className="sr-only">Acción</span></th>
              </tr>
            </thead>
            <tbody>
              {visible.map((e) => (
                <tr key={e.id} className={`border-b last:border-0 ${e.activo ? '' : 'text-muted-foreground'}`}>
                  <td className="px-4 py-3 sm:px-5"><p className="font-semibold">{e.nombre}</p><p className="break-all text-xs text-muted-foreground md:hidden">{e.correo}</p></td>
                  <td className="hidden px-5 py-3 md:table-cell">{e.correo}</td>
                  <td className="px-3 py-3"><span className={`rounded-full px-2 py-1 text-xs font-semibold ${e.activo ? 'bg-emerald-500/10 text-emerald-600' : 'bg-muted text-muted-foreground'}`}>{e.activo ? 'Activo' : 'Inactivo'}</span></td>
                  <td className="px-3 py-3">
                    {e.invitacion ? (
                      <div className="flex flex-col items-start gap-1.5"><PillInvitacion estado={e.invitacion.estado} /><span className="hidden text-xs text-muted-foreground xl:block">{detalleInvitacion(e.invitacion)}</span><div className="flex items-center gap-1"><BotonReenviar invitacion={e.invitacion} compacto onReenviada={recargar} />{e.invitacion.estado !== 'aceptada' && <a href={`/empleados/aceptar-invitacion?token=${e.invitacion.token}`} target="_blank" rel="noreferrer" title="Abrir el enlace de la invitación (solo maqueta)" aria-label={`Abrir el enlace de la invitación de ${e.nombre} (solo maqueta)`} className="rounded-lg p-1.5 text-muted-foreground transition hover:bg-muted hover:text-foreground"><ExternalLink className="size-3.5" /></a>}</div></div>
                    ) : <span className="text-xs text-muted-foreground">—</span>}
                  </td>
                  <td className="px-3 py-3 text-right tabular-nums">{e.platillos_por_mes[MES_ACTUAL] ?? 0}</td>
                  <td className="px-4 py-3 text-right sm:px-5">
                    <div className="flex items-center justify-end gap-1">
                      <Button variant="ghost" size="sm" onClick={() => setViendoId(e.id)} aria-label={`Ver detalle de ${e.nombre}`} title="Ver detalle"><Eye /></Button>
                      <Button variant="ghost" size="sm" onClick={() => setEditandoId(e.id)} aria-label={`Editar a ${e.nombre}`} title="Editar"><Pencil /></Button>
                      <Button variant={e.activo ? 'destructive' : 'outline'} size="sm" onClick={() => (e.activo ? setPorDesactivar(e) : toggle(e.id, true))}>{e.activo ? 'Desactivar' : 'Activar'}</Button>
                    </div>
                  </td>
                </tr>
              ))}
              {!visible.length && <tr><td colSpan={6} className="px-5 py-10 text-center text-muted-foreground">{employees.length ? 'No hay empleados que coincidan con la búsqueda.' : 'Todavía no hay empleados registrados. Agrega uno o sube un CSV.'}</td></tr>}
            </tbody>
          </table>
        </div>
      </Card>
      <p className="mt-3 text-xs text-muted-foreground">Para subir un CSV usa dos columnas: nombre y correo. Los empleados desactivados dejan de poder elegir platillo.</p>

      {porDesactivar && (
        <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" onClick={() => !desactivando && setPorDesactivar(null)}>
          <div role="alertdialog" aria-modal="true" aria-label={`Desactivar a ${porDesactivar.nombre}`} onClick={(e) => e.stopPropagation()} className="w-full max-w-md rounded-2xl bg-card p-6 shadow-xl">
            <h2 className="font-display text-xl font-bold">¿Desactivar a {porDesactivar.nombre}?</h2>
            <p className="mt-3 text-sm text-muted-foreground">Quedará como <strong className="text-foreground">inactivo</strong>: ya no podrá elegir platillo y se quitará su elección de mañana. No se borra; conserva su historial y su consumo, y puedes reactivarlo cuando quieras.</p>
            <div className="mt-6 flex justify-end gap-2">
              <Button variant="outline" className="h-10 px-4" onClick={() => setPorDesactivar(null)} disabled={desactivando}>Cancelar</Button>
              <Button variant="destructive" className="h-10 px-4" onClick={confirmarDesactivar} disabled={desactivando}>{desactivando ? 'Desactivando…' : 'Desactivar'}</Button>
            </div>
          </div>
        </div>
      )}

      {adding && (
        <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" onClick={() => setAdding(false)}>
          <form role="dialog" aria-label="Agregar empleado" onSubmit={submit} onClick={(e) => e.stopPropagation()} className="w-full max-w-md rounded-xl bg-card p-5 shadow-xl sm:p-6">
            <div className="flex items-start justify-between">
              <h2 className="font-display text-2xl font-bold">Agregar empleado</h2>
              <button type="button" onClick={() => setAdding(false)} aria-label="Cerrar"><X /></button>
            </div>
            <div className="mt-5 flex flex-col gap-4">
              <label className="text-sm font-medium">Nombre completo<input required autoFocus value={name} onChange={(e) => setName(e.target.value)} className="mt-1 h-11 w-full rounded-lg border bg-background px-3" placeholder="Nombre y apellido" /></label>
              <label className="text-sm font-medium">Correo<input required type="email" value={email} onChange={(e) => setEmail(e.target.value)} className="mt-1 h-11 w-full rounded-lg border bg-background px-3" placeholder="nombre@tuempresa.mx" /></label>
            </div>
            <div className="mt-6 flex justify-end gap-2">
              <Button type="button" variant="outline" className="h-10 px-4" onClick={() => setAdding(false)}>Cancelar</Button>
              <Button type="submit" className="h-10 px-4" disabled={saving}>{saving ? 'Agregando…' : 'Agregar'}</Button>
            </div>
          </form>
        </div>
      )}

      {viendo && (
        <PanelDetalle etiqueta={viendo.activo ? 'Empleado activo' : 'Empleado inactivo'} titulo={viendo.nombre} onCerrar={() => setViendoId(null)} acciones={<><Button variant="outline" onClick={() => { setEditandoId(viendo.id); setViendoId(null) }}><Pencil /> Editar empleado</Button><Button variant={viendo.activo ? 'destructive' : 'outline'} onClick={() => { if (viendo.activo) { setPorDesactivar(viendo); setViendoId(null) } else toggle(viendo.id, true) }}>{viendo.activo ? 'Desactivar' : 'Activar'}</Button></>}>
          <GrillaDatos datos={[
            { etiqueta: 'Correo', valor: <span className="break-all">{viendo.correo}</span> },
            { etiqueta: 'Departamento', valor: viendo.departamento },
            { etiqueta: 'Estado', valor: viendo.activo ? 'Activo' : 'Inactivo' },
            { etiqueta: 'Platillos este mes', valor: viendo.platillos_por_mes[MES_ACTUAL] ?? 0 },
          ]} />
          <div className="mt-5 rounded-lg border p-4"><p className="text-xs uppercase text-muted-foreground">Elección de mañana</p><p className="mt-2 text-sm font-semibold">{viendo.eleccion_manana ? viendo.eleccion_manana.platillo_nombre : 'Todavía no elige'}</p>{viendo.eleccion_manana && <p className="text-xs text-muted-foreground">{viendo.eleccion_manana.origen === 'manual' ? 'La eligió la persona.' : 'Se asignó por defecto.'}</p>}</div>
          <div className="mt-5 rounded-lg border p-4"><p className="text-xs uppercase text-muted-foreground">Invitación</p>{viendo.invitacion ? <div className="mt-2 flex flex-col items-start gap-1.5"><PillInvitacion estado={viendo.invitacion.estado} /><span className="text-xs text-muted-foreground">{detalleInvitacion(viendo.invitacion)}</span><BotonReenviar invitacion={viendo.invitacion} compacto onReenviada={recargar} /></div> : <p className="mt-2 text-sm text-muted-foreground">Sin invitación pendiente.</p>}</div>
          <div className="mt-5 rounded-lg border p-4"><p className="text-xs uppercase text-muted-foreground">Platillos por mes</p><ul className="mt-2 divide-y text-sm">{Object.entries(viendo.platillos_por_mes).sort(([a], [b]) => b.localeCompare(a)).map(([mes, n]) => <li key={mes} className="flex justify-between py-1.5"><span>{etiquetaMes(mes)}</span><strong className="tabular-nums">{n}</strong></li>)}{!Object.keys(viendo.platillos_por_mes).length && <li className="py-1.5 text-muted-foreground">Sin consumo todavía.</li>}</ul></div>
        </PanelDetalle>
      )}

      {editando && <EditarEmpleado empleado={editando} onCerrar={() => setEditandoId(null)} onGuardado={() => { setEditandoId(null); recargar() }} />}
    </div>
  )
}

/** Editar el nombre y el departamento de un empleado (el correo es su acceso y no se cambia aquí). */
function EditarEmpleado({ empleado, onCerrar, onGuardado }: { empleado: { id: string; nombre: string; correo: string; departamento: string | null }; onCerrar: () => void; onGuardado: () => void }) {
  const [nombre, setNombre] = useState(empleado.nombre)
  const [departamento, setDepartamento] = useState(empleado.departamento ?? '')
  const [guardando, setGuardando] = useState(false)
  async function guardar() {
    setGuardando(true)
    try {
      await editarEmpleado(empleado.id, { nombre, departamento })
      toast.success('Cambios guardados')
      onGuardado()
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo guardar al empleado')
    } finally {
      setGuardando(false)
    }
  }
  return (
    <VentanaFormulario titulo={`Editar a ${empleado.nombre}`} textoEnviar="Guardar cambios" trabajando={guardando} deshabilitarEnvio={!nombre.trim()} onCerrar={onCerrar} onEnviar={guardar}>
      <CampoTexto etiqueta="Nombre completo" required autoFocus value={nombre} onChange={(e) => setNombre(e.target.value)} />
      <CampoTexto etiqueta="Departamento" value={departamento} onChange={(e) => setDepartamento(e.target.value)} placeholder="Opcional" />
      <CampoTexto etiqueta="Correo" value={empleado.correo} disabled ayuda="Es su acceso a EasyLunch; no se cambia aquí." readOnly />
    </VentanaFormulario>
  )
}
