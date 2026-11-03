// Base de datos de ejemplo en memoria. Solo /lib/api.ts debe importar este archivo:
// las pantallas piden todo a la API para que después baste con reemplazar las funciones de api.ts por fetch.

import { MESES, MES_ACTUAL, FECHA_MANANA } from './config'
import { calcularConsumo, calcularTotales, folioFactura } from './facturacion'
import { sumarDias } from './format'
import type {
  BloqueHorario, ConfiguracionOperacion, ContratoComedor, Corte, DiaSinServicio, ResultadoCorte, ContratoEmpresa, DisponibilidadDia, EleccionEmpleado, Comedor, Empleado, Empresa, EntregaEmpresa, EstadoFactura,
  EstadoLiquidacion, FacturaMensual, HistorialPrecio, Incidencia, LiquidacionComedor, MensajeTicket, OrdenProduccion, Parada, Pedido, Plan, Platillo,
  Invitacion, MensajeContacto, RegistroAuditoria, Repartidor, TokenRestablecimiento, ResumenDashboard, Ruta, Ticket, TipoVehiculo, Usuario,
} from './types'

// ---------------------------------------------------------------- comedores

const comedoresBase = [
  { id: 'COM-001', nombre: 'Cocina Norte', zona: 'Zapopan Norte', contacto: 'Laura Gómez', telefono: '33 1458 2201', precio: 49, inicio: '2026-07-01', fin: '2026-12-31', entregados: [142, 224, 210, 198] },
  { id: 'COM-002', nombre: 'Sabor Casero Centro', zona: 'Guadalajara Centro', contacto: 'Andrés Valdés', telefono: '33 2190 4472', precio: 47, inicio: '2026-06-15', fin: '2026-12-14', entregados: [188, 262, 251, 240] },
  { id: 'COM-003', nombre: 'Fogón del Sur', zona: 'Tlaquepaque', contacto: 'Mónica Salas', telefono: '33 3678 9014', precio: 52, inicio: '2026-05-01', fin: '2026-10-31', entregados: [96, 158, 149, 133] },
  { id: 'COM-004', nombre: 'Cocina Industrial Poniente', zona: 'Zapopan Poniente', contacto: 'Héctor Jiménez', telefono: '33 4410 7733', precio: 48, inicio: '2026-08-01', fin: '2027-01-31', entregados: [235, 318, 302, 289] },
  { id: 'COM-005', nombre: 'Comedor La Huerta', zona: 'El Salto', contacto: 'Rosa Elena Pineda', telefono: '33 5521 8806', precio: 50, inicio: '2026-07-01', fin: '2026-12-31', entregados: [121, 176, 168, 155] },
  { id: 'COM-006', nombre: 'Alimentos del Valle', zona: 'Tonalá', contacto: 'Gerardo Ochoa', telefono: '33 6632 1190', precio: 46, inicio: '2026-09-01', fin: '2027-02-28', entregados: [74, 119, 112, 104] },
]

export const comedores: Comedor[] = comedoresBase.map((c, i) => ({ id: c.id, usuario_id: `USR-COM-${String(i + 1).padStart(2, '0')}`, nombre: c.nombre, zona: c.zona, activo: true }))

export const contratos_comedor: ContratoComedor[] = comedoresBase.map((c) => ({
  id: `CTC-${c.id.slice(4)}`, comedor_id: c.id, precio_por_platillo: c.precio, compra_minima_mensual: 200, vigencia_inicio: c.inicio, vigencia_fin: c.fin,
}))

// ---------------------------------------------------------------- empresas

const empresasBase = [
  { id: 'EMP-001', nombre: 'Grupo Industrial Nexo', dominio: 'nexo.mx', rrhh: 'Mariana Ortega', correo: 'rrhh@nexo.mx', subsidio: 60, minimo: 420, empleados: 49, inicio: '2026-07-01', fin: '2026-12-31', direccion: 'Av. Industria 1450, Zapopan', ventana: ['12:30', '12:50'], estimada: '12:40', zona: 'Zapopan', semilla: 1 },
  { id: 'EMP-002', nombre: 'Logística Pacífico', dominio: 'logpacifico.mx', rrhh: 'Ricardo Salas', correo: 'rsalas@logpacifico.mx', subsidio: 50, minimo: 300, empleados: 32, inicio: '2026-05-01', fin: '2026-10-31', direccion: 'Calz. del Ejército 220, Guadalajara', ventana: ['13:00', '13:20'], estimada: '13:10', zona: 'Guadalajara', semilla: 2 },
  { id: 'EMP-003', nombre: 'Farmacéutica Andrade', dominio: 'andrade.com.mx', rrhh: 'Lucía Andrade', correo: 'lucia@andrade.com.mx', subsidio: 80, minimo: 380, empleados: 58, inicio: '2026-04-01', fin: '2026-12-31', direccion: 'Blvd. Puerta de Hierro 4965, Zapopan', ventana: ['12:15', '12:35'], estimada: '12:25', zona: 'Zapopan', semilla: 3 },
  { id: 'EMP-004', nombre: 'Textiles La Aurora', dominio: 'aurora-textil.mx', rrhh: 'Jorge Beltrán', correo: 'jbeltran@aurora-textil.mx', subsidio: 40, minimo: 200, empleados: 21, inicio: '2026-08-01', fin: '2027-01-31', direccion: 'Calle Hilanderos 88, Tlaquepaque', ventana: ['13:30', '13:45'], estimada: '13:38', zona: 'Tlaquepaque', semilla: 4 },
  { id: 'EMP-005', nombre: 'Autopartes del Bajío', dominio: 'autobajio.mx', rrhh: 'Patricia Méndez', correo: 'pmendez@autobajio.mx', subsidio: 70, minimo: 600, empleados: 84, inicio: '2026-05-12', fin: '2026-11-11', direccion: 'Parque Industrial El Salto, nave 12', ventana: ['12:00', '12:25'], estimada: '12:12', zona: 'El Salto', semilla: 5 },
  { id: 'EMP-006', nombre: 'Consultoría Vértice', dominio: 'vertice.mx', rrhh: 'Daniela Fuentes', correo: 'dfuentes@vertice.mx', subsidio: 30, minimo: 150, empleados: 18, inicio: '2026-09-01', fin: '2027-02-28', direccion: 'Av. Américas 1500, piso 9, Guadalajara', ventana: ['13:45', '14:00'], estimada: '13:52', zona: 'Guadalajara', semilla: 6 },
  { id: 'EMP-007', nombre: 'Alimentos Casa Real', dominio: 'casareal.mx', rrhh: 'Héctor Villalobos', correo: 'hvillalobos@casareal.mx', subsidio: 60, minimo: 380, empleados: 44, inicio: '2026-03-01', fin: '2026-12-31', direccion: 'Carr. a Nogales km 8, Zapopan', ventana: ['12:45', '13:05'], estimada: '12:55', zona: 'Zapopan', semilla: 7 },
  { id: 'EMP-008', nombre: 'Tecnologías Horizonte', dominio: 'horizonte.tech', rrhh: 'Sofía Quintero', correo: 'squintero@horizonte.tech', subsidio: 100, minimo: 200, empleados: 36, inicio: '2026-07-15', fin: '2027-01-14', direccion: 'Av. Patria 2085, Zapopan', ventana: ['13:15', '13:30'], estimada: '13:22', zona: 'Zapopan', semilla: 8 },
]

export const empresas: Empresa[] = empresasBase.map((e, i) => ({
  id: e.id, usuario_id: `USR-EMP-${String(i + 1).padStart(2, '0')}`, nombre: e.nombre, dominio: e.dominio, zona: e.zona, direccion_entrega: e.direccion,
}))

export const contratos_empresa: ContratoEmpresa[] = empresasBase.map((e) => ({
  id: `CTE-${e.id.slice(4)}`, empresa_id: e.id, porcentaje_subsidio: e.subsidio, compra_minima_mensual: e.minimo, precio_por_platillo: 130,
  vigencia_inicio: e.inicio, vigencia_fin: e.fin, hora_entrega: e.estimada, ventana_entrega_inicio: e.ventana[0], ventana_entrega_fin: e.ventana[1],
}))

// ---------------------------------------------------------------- usuarios

export const usuarios: Usuario[] = [
  { id: 'USR-ADM-01', nombre: 'Admin', correo: 'admin@easylunch.mx', telefono: null, rol: 'administrador', activo: true },
  { id: 'USR-SOP-01', nombre: 'Ana Ruiz', correo: 'ana.ruiz@easylunch.mx', telefono: null, rol: 'soporte', activo: true },
  { id: 'USR-SOP-02', nombre: 'Carlos Méndez', correo: 'carlos.mendez@easylunch.mx', telefono: null, rol: 'soporte', activo: true },
  ...comedoresBase.map((c, i): Usuario => ({
    id: `USR-COM-${String(i + 1).padStart(2, '0')}`, nombre: c.contacto, correo: `comedor.${c.id.toLowerCase()}@easylunch.mx`, telefono: c.telefono, rol: 'comedor', activo: true,
  })),
  ...empresasBase.map((e, i): Usuario => ({ id: `USR-EMP-${String(i + 1).padStart(2, '0')}`, nombre: e.rrhh, correo: e.correo, telefono: null, rol: 'empresa', activo: true })),
  ...['Mariana López', 'Jorge Ramírez', 'Sofía Hernández', 'Diego Torres', 'Ana Paula Silva', 'Roberto Sánchez', 'Ana Paula Torres', 'Lucía Méndez'].map((nombre, i): Usuario => ({
    id: `USR-CLI-${String(i + 1).padStart(2, '0')}`, nombre, correo: `cliente${i + 1}@correo.mx`, telefono: null, rol: 'cliente', activo: true,
  })),
]

// ---------------------------------------------------------------- repartidores

const repartidoresBase: { nombre: string; vehiculo: TipoVehiculo; zona: string; estado: Repartidor['estado']; bloques: BloqueHorario[]; asignadas: number; completadas: number; ubicacion: string; personalizado: boolean }[] = [
  { nombre: 'Carlos Ramírez', vehiculo: 'motocicleta', zona: 'Centro', estado: 'en_ruta', bloques: ['manana', 'mediodia'], asignadas: 12, completadas: 7, ubicacion: '2026-11-16T12:45:00', personalizado: false },
  { nombre: 'Sofía Hernández', vehiculo: 'bicicleta', zona: 'Providencia', estado: 'libre', bloques: ['mediodia'], asignadas: 0, completadas: 0, ubicacion: '2026-11-16T12:43:00', personalizado: false },
  { nombre: 'Diego Morales', vehiculo: 'automovil', zona: 'Zapopan', estado: 'asignado', bloques: ['manana'], asignadas: 18, completadas: 10, ubicacion: '2026-11-16T12:47:00', personalizado: false },
  { nombre: 'Valeria Torres', vehiculo: 'motocicleta', zona: 'Tlaquepaque', estado: 'no_disponible', bloques: [], asignadas: 0, completadas: 0, ubicacion: '2026-11-16T10:48:00', personalizado: true },
  { nombre: 'Andrés Castillo', vehiculo: 'bicicleta', zona: 'Centro', estado: 'libre', bloques: [], asignadas: 0, completadas: 0, ubicacion: '2026-11-16T12:40:00', personalizado: false },
  { nombre: 'Mariana López', vehiculo: 'automovil', zona: 'Valle Real', estado: 'en_ruta', bloques: ['manana', 'mediodia'], asignadas: 26, completadas: 19, ubicacion: '2026-11-16T12:46:00', personalizado: false },
  { nombre: 'Luis Navarro', vehiculo: 'automovil', zona: 'Zapopan', estado: 'libre', bloques: [], asignadas: 0, completadas: 0, ubicacion: '2026-11-16T12:41:00', personalizado: false },
  { nombre: 'Fernanda Cruz', vehiculo: 'automovil', zona: 'Centro', estado: 'libre', bloques: [], asignadas: 0, completadas: 0, ubicacion: '2026-11-16T12:44:00', personalizado: false },
]
const numerosEmpleado = ['EMP-0003', 'EMP-0008', 'EMP-0012', 'EMP-0017', 'EMP-0021', 'EMP-0024', 'EMP-0027', 'EMP-0030']

usuarios.push(...repartidoresBase.map((r, i): Usuario => ({
  id: `USR-REP-${String(i + 1).padStart(2, '0')}`, nombre: r.nombre, correo: `repartidor${i + 1}@easylunch.mx`, telefono: null, rol: 'repartidor', activo: true,
})))

export const repartidores: Repartidor[] = repartidoresBase.map((r, i) => ({
  id: `REP-${String(i + 1).padStart(3, '0')}`, usuario_id: `USR-REP-${String(i + 1).padStart(2, '0')}`, numero_empleado: numerosEmpleado[i], tipo_vehiculo: r.vehiculo,
  vehiculo_personalizado: r.personalizado, placas: r.vehiculo === 'bicicleta' ? null : `JAL-${100 + i * 17}-${'ABCDEFGH'[i]}`, zona_base: r.zona, estado: r.estado,
  bloques_reservados: r.bloques, entregas_asignadas: r.asignadas, entregas_completadas: r.completadas, ultima_ubicacion_en: r.ubicacion,
}))

export const capacidades_vehiculo: Record<TipoVehiculo, number> = { bicicleta: 8, motocicleta: 15, automovil: 30 }

// ---------------------------------------------------------------- platillos

const foto = (id: string) => `https://images.unsplash.com/photo-${id}?w=800&q=80`

export const platillos: Platillo[] = [
  { id: 'P-001', comedor_id: 'COM-001', nombre: 'Pollo teriyaki con arroz', descripcion: 'Pollo glaseado, arroz jazmín y verduras salteadas.', tiempo_comida: 'comida', calorias: 520, alergenos: ['Soya', 'Ajonjolí'], etiquetas: ['Alto en proteína'], precio_venta: 119, foto_url: foto('1546069901-ba9599a7e63c'), estado_revision: 'publicado', motivo_rechazo: null, activo: true },
  { id: 'P-002', comedor_id: 'COM-001', nombre: 'Avena con frutos rojos', descripcion: 'Avena cocida, fruta fresca y nuez tostada.', tiempo_comida: 'desayuno', calorias: 290, alergenos: ['Nueces'], etiquetas: ['Vegetariano', 'Ligero'], precio_venta: 82, foto_url: foto('1517673400267-0251440c45dc'), estado_revision: 'en_revision', motivo_rechazo: null, activo: true },
  { id: 'P-003', comedor_id: 'COM-001', nombre: 'Ensalada de quinoa', descripcion: 'Quinoa, garbanzo, pepino, jitomate y vinagreta cítrica.', tiempo_comida: 'cena', calorias: 380, alergenos: [], etiquetas: ['Vegetariano', 'Sin gluten'], precio_venta: 105, foto_url: null, estado_revision: 'publicado', motivo_rechazo: null, activo: true },
  { id: 'P-004', comedor_id: 'COM-001', nombre: 'Lasaña de verduras', descripcion: 'Pasta horneada con vegetales, jitomate y queso.', tiempo_comida: 'comida', calorias: 560, alergenos: ['Gluten', 'Lácteos'], etiquetas: ['Vegetariano'], precio_venta: 128, foto_url: null, estado_revision: 'borrador', motivo_rechazo: null, activo: true },
  { id: 'P-005', comedor_id: 'COM-003', nombre: 'Tinga de setas', descripcion: 'Setas guisadas en adobo de jitomate con tostadas horneadas.', tiempo_comida: 'comida', calorias: 450, alergenos: [], etiquetas: ['Vegetariano'], precio_venta: 99, foto_url: null, estado_revision: 'rechazado', motivo_rechazo: 'agrega gramaje de la guarnición.', activo: true },
  { id: 'P-006', comedor_id: 'COM-003', nombre: 'Pescado a la veracruzana', descripcion: 'Filete de pescado con aceituna, alcaparra y arroz.', tiempo_comida: 'comida', calorias: 410, alergenos: ['Pescado'], etiquetas: ['Ligero', 'Sin gluten'], precio_venta: 149, foto_url: null, estado_revision: 'publicado', motivo_rechazo: null, activo: true },
  { id: 'P-007', comedor_id: 'COM-004', nombre: 'Pechuga a la plancha con arroz rojo', descripcion: 'Pechuga asada con arroz rojo y verduras al vapor.', tiempo_comida: 'comida', calorias: 480, alergenos: [], etiquetas: ['Alto en proteína'], precio_venta: 130, foto_url: null, estado_revision: 'publicado', motivo_rechazo: null, activo: true },
  { id: 'P-008', comedor_id: 'COM-004', nombre: 'Enchiladas verdes con pollo', descripcion: 'Tortillas rellenas de pollo con salsa verde y crema.', tiempo_comida: 'comida', calorias: 560, alergenos: ['Lácteos'], etiquetas: [], precio_venta: 130, foto_url: null, estado_revision: 'publicado', motivo_rechazo: null, activo: true },
  { id: 'P-009', comedor_id: 'COM-005', nombre: 'Bowl vegetariano de quinoa', descripcion: 'Quinoa con verduras asadas, aguacate y semillas.', tiempo_comida: 'comida', calorias: 430, alergenos: [], etiquetas: ['Vegetariano'], precio_venta: 130, foto_url: null, estado_revision: 'publicado', motivo_rechazo: null, activo: true },
  { id: 'P-010', comedor_id: 'COM-004', nombre: 'Milanesa de res con puré', descripcion: 'Milanesa empanizada con puré de papa y ensalada.', tiempo_comida: 'comida', calorias: 640, alergenos: ['Gluten', 'Lácteos'], etiquetas: [], precio_venta: 130, foto_url: null, estado_revision: 'publicado', motivo_rechazo: null, activo: true },
  { id: 'P-011', comedor_id: 'COM-006', nombre: 'Tacos de cochinita con frijoles', descripcion: 'Cochinita pibil en tortilla de maíz con frijoles de la olla.', tiempo_comida: 'comida', calorias: 590, alergenos: [], etiquetas: [], precio_venta: 130, foto_url: null, estado_revision: 'publicado', motivo_rechazo: null, activo: true },
  { id: 'P-012', comedor_id: 'COM-002', nombre: 'Ensalada mediterránea', descripcion: 'Hojas verdes, aceitunas, jitomate y queso feta.', tiempo_comida: 'comida', calorias: 340, alergenos: ['Lácteos'], etiquetas: ['Vegetariano', 'Ligero'], precio_venta: 105, foto_url: foto('1512621776951-a57141f2eefd'), estado_revision: 'publicado', motivo_rechazo: null, activo: true },
  { id: 'P-013', comedor_id: 'COM-002', nombre: 'Chilaquiles rojos', descripcion: 'Totopos en salsa roja con crema, queso y pollo deshebrado.', tiempo_comida: 'desayuno', calorias: 480, alergenos: ['Lácteos', 'Gluten'], etiquetas: ['Favorito'], precio_venta: 99, foto_url: foto('1515003197210-e0cd71810b5f'), estado_revision: 'publicado', motivo_rechazo: null, activo: true },
  { id: 'P-014', comedor_id: 'COM-002', nombre: 'Pasta al pesto', descripcion: 'Pasta con pesto de albahaca, nuez y parmesano.', tiempo_comida: 'comida', calorias: 610, alergenos: ['Gluten', 'Nueces', 'Lácteos'], etiquetas: ['Vegetariano'], precio_venta: 128, foto_url: foto('1473093295043-cdd812d0e601'), estado_revision: 'publicado', motivo_rechazo: null, activo: false },
  { id: 'P-015', comedor_id: 'COM-005', nombre: 'Salmón con verduras', descripcion: 'Filete de salmón al horno con verduras de temporada.', tiempo_comida: 'cena', calorias: 410, alergenos: ['Pescado'], etiquetas: ['Ligero', 'Sin gluten'], precio_venta: 149, foto_url: foto('1467003909585-2f8a72700288'), estado_revision: 'publicado', motivo_rechazo: null, activo: true },
]

export const historial_precios: HistorialPrecio[] = platillos.flatMap((p) => [
  { id: `HP-${p.id}-3`, platillo_id: p.id, vigente_desde: '2026-11-12', vigente_hasta: null, precio_venta: p.precio_venta, costo: 67 },
  { id: `HP-${p.id}-2`, platillo_id: p.id, vigente_desde: '2026-09-01', vigente_hasta: '2026-11-11', precio_venta: p.precio_venta - 10, costo: 65 },
  { id: `HP-${p.id}-1`, platillo_id: p.id, vigente_desde: '2026-07-01', vigente_hasta: '2026-08-31', precio_venta: p.precio_venta - 20, costo: 62 },
])

export const MENU_MANANA = ['P-007', 'P-008', 'P-009', 'P-010', 'P-011']

// ---------------------------------------------------------------- empleados y elecciones

const NOMBRES = ['Carlos', 'María', 'José', 'Ana', 'Luis', 'Fernanda', 'Miguel', 'Daniela', 'Jorge', 'Valeria', 'Ricardo', 'Paola', 'Andrés', 'Gabriela', 'Eduardo', 'Karla', 'Roberto', 'Sofía', 'Alejandro', 'Mónica', 'Héctor', 'Lorena', 'Iván', 'Natalia', 'Raúl', 'Itzel']
const APELLIDOS = ['Hernández', 'García', 'Martínez', 'López', 'González', 'Rodríguez', 'Pérez', 'Sánchez', 'Ramírez', 'Flores', 'Torres', 'Rivera', 'Gómez', 'Díaz', 'Cruz', 'Morales', 'Reyes', 'Ortiz', 'Gutiérrez', 'Chávez', 'Ramos', 'Mendoza', 'Ruiz', 'Aguilar', 'Castillo', 'Vargas', 'Navarro', 'Medina']
const DEPARTAMENTOS = ['Operaciones', 'Ventas', 'Administración', 'Producto', 'Recursos Humanos']

export const correoDesdeNombre = (nombre: string, dominio: string) =>
  `${nombre.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z\s]/g, '').trim().split(/\s+/).join('.')}@${dominio}`

export const empleados: Empleado[] = []
export const invitaciones: Invitacion[] = []
export const elecciones_empleado: EleccionEmpleado[] = []
export const disponibilidad_dia: DisponibilidadDia[] = []

function diasHabiles(mes: string, hasta: number) {
  const [y, m] = mes.split('-').map(Number)
  const dias: string[] = []
  for (let d = 1; d <= hasta; d++) {
    const fecha = new Date(Date.UTC(y, m - 1, d))
    if (fecha.getUTCMonth() !== m - 1) break
    if (fecha.getUTCDay() % 6 !== 0) dias.push(fecha.toISOString().slice(0, 10))
  }
  return dias
}

;(() => {
  const habiles = Object.fromEntries(MESES.map((mes) => [mes, diasHabiles(mes, mes === MES_ACTUAL ? 29 : 31)]))
  const correosUsados = new Set(usuarios.map((u) => u.correo))
  let consecutivo = 0
  const tomadasManana: Record<string, number> = {}

  empresasBase.forEach((empresa) => {
    const subsidio = empresa.subsidio
    for (let i = 0; i < empresa.empleados; i++) {
      const nombre = `${NOMBRES[(i + empresa.semilla) % NOMBRES.length]} ${APELLIDOS[(i * 3 + empresa.semilla + Math.floor(i / NOMBRES.length)) % APELLIDOS.length]}`
      let correo = correoDesdeNombre(nombre, empresa.dominio)
      for (let n = 2; correosUsados.has(correo); n++) correo = correoDesdeNombre(nombre, empresa.dominio).replace('@', `${n}@`)
      correosUsados.add(correo)

      const id = `${empresa.id}-E${String(i + 1).padStart(3, '0')}`
      const activo = i % 11 !== 10
      usuarios.push({ id: `USR-${id}`, nombre, correo, telefono: null, rol: 'empleado', activo })
      empleados.push({ id, usuario_id: `USR-${id}`, empresa_id: empresa.id, departamento: DEPARTAMENTOS[i % DEPARTAMENTOS.length], activo })
      const caso = i % 20
      invitaciones.push({
        id: `INV-${id}`, usuario_id: `USR-${id}`, correo, rol: 'empleado', organizacion_tipo: 'empresa', organizacion_id: empresa.id, token: `inv-${id.toLowerCase()}`,
        enviada_en: caso === 0 ? '2026-10-28T09:30:00' : caso === 1 ? '2026-10-14T09:30:00' : '2026-07-06T10:00:00',
        vence_en: caso === 0 ? '2026-11-04T09:30:00' : caso === 1 ? '2026-10-21T09:30:00' : '2026-07-13T10:00:00',
        aceptada_en: caso <= 1 ? null : '2026-07-07T08:15:00',
      })

      MESES.forEach((mes, indiceMes) => {
        const base = ((empresa.semilla * 3 + i * 7 + indiceMes * 5) % 12) + 4
        const n = mes === MES_ACTUAL ? Math.round(base * 0.86) : base
        const dias = habiles[mes]
        for (let k = 0; k < n; k++) {
          consecutivo++
          elecciones_empleado.push({
            id: `ELEC-${String(consecutivo).padStart(6, '0')}`, empleado_id: id, fecha: dias[(Math.floor((k * dias.length) / n) + (i % 2)) % dias.length], tiempo_comida: 'comida',
            platillo_id: MENU_MANANA[(i + k) % MENU_MANANA.length], precio_congelado: 130, porcentaje_subsidio_congelado: subsidio, origen: (i + k) % 6 === 0 ? 'por_defecto' : 'manual',
          })
        }
      })

      const sinElegir = i % 7 === 3 || i === 1
      if (activo && !sinElegir) {
        consecutivo++
        const platillo_id = MENU_MANANA[(i + empresa.semilla) % MENU_MANANA.length]
        tomadasManana[platillo_id] = (tomadasManana[platillo_id] ?? 0) + 1
        elecciones_empleado.push({ id: `ELEC-${String(consecutivo).padStart(6, '0')}`, empleado_id: id, fecha: FECHA_MANANA, tiempo_comida: 'comida', platillo_id, precio_congelado: 130, porcentaje_subsidio_congelado: subsidio, origen: 'manual' })
      }
    }
  })

  MENU_MANANA.forEach((platillo_id) => disponibilidad_dia.push({ id: `DISP-${FECHA_MANANA}-${platillo_id}`, platillo_id, fecha: FECHA_MANANA, porciones_maximas: 200, porciones_tomadas: tomadasManana[platillo_id] ?? 0 }))
})()

// Semana del comedor Cocina Norte (portal de comedores)
;['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24'].forEach((fecha, i) => {
  disponibilidad_dia.push({ id: `DISP-${fecha}-P-001`, platillo_id: 'P-001', fecha, porciones_maximas: i === 0 ? 58 : 70, porciones_tomadas: i === 0 ? 68 : i === 1 ? 12 : 0 })
  disponibilidad_dia.push({ id: `DISP-${fecha}-P-002`, platillo_id: 'P-002', fecha, porciones_maximas: 42, porciones_tomadas: i === 0 ? 42 : 0 })
})

// Menú semanal (administración)
;['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21'].forEach((fecha, i) => {
  disponibilidad_dia.push({ id: `DISP-${fecha}-P-001`, platillo_id: 'P-001', fecha, porciones_maximas: 24, porciones_tomadas: 0 })
  if (i === 0) disponibilidad_dia.push({ id: `DISP-${fecha}-P-002`, platillo_id: 'P-002', fecha, porciones_maximas: 24, porciones_tomadas: 0 })
})

// ---------------------------------------------------------------- producción y entregas

export const ordenes_produccion: OrdenProduccion[] = [
  { id: 'OP-20261019-1', comedor_id: 'COM-001', platillo_id: 'P-001', fecha: '2026-10-19', porciones_totales: 68, porciones_empresas: 54, porciones_individuales: 14, hora_recoleccion: '10:30', estado: 'pendiente' },
  { id: 'OP-20261019-2', comedor_id: 'COM-001', platillo_id: 'P-002', fecha: '2026-10-19', porciones_totales: 42, porciones_empresas: 32, porciones_individuales: 10, hora_recoleccion: '08:00', estado: 'pendiente' },
  { id: 'OP-20261019-3', comedor_id: 'COM-001', platillo_id: 'P-003', fecha: '2026-10-19', porciones_totales: 32, porciones_empresas: 24, porciones_individuales: 8, hora_recoleccion: '12:00', estado: 'pendiente' },
  { id: 'OP-20261116-1', comedor_id: 'COM-001', platillo_id: 'P-002', fecha: '2026-11-16', porciones_totales: 84, porciones_empresas: 66, porciones_individuales: 18, hora_recoleccion: '08:00', estado: 'pendiente' },
  { id: 'OP-20261116-2', comedor_id: 'COM-001', platillo_id: 'P-001', fecha: '2026-11-16', porciones_totales: 96, porciones_empresas: 76, porciones_individuales: 20, hora_recoleccion: '10:30', estado: 'pendiente' },
  { id: 'OP-20261116-3', comedor_id: 'COM-001', platillo_id: 'P-003', fecha: '2026-11-16', porciones_totales: 68, porciones_empresas: 52, porciones_individuales: 16, hora_recoleccion: '12:00', estado: 'pendiente' },
]

const estadosEntrega: EntregaEmpresa['estado'][] = ['entregada', 'en_ruta', 'en_consolidacion', 'programada', 'no_localizado', 'reprogramada', 'cancelada', 'programada']
const vehiculosEntrega: TipoVehiculo[] = ['bicicleta', 'motocicleta', 'automovil', 'bicicleta', 'motocicleta', 'automovil', 'bicicleta', 'motocicleta']

export const entregas_empresa: EntregaEmpresa[] = empresasBase.map((e, i) => ({
  id: `EL-${1048 + i}`, empresa_id: e.id, ruta_id: i < 3 ? `R-0${i + 1}` : null, fecha: '2026-10-30', zona: e.zona, ventana_inicio: e.ventana[0], ventana_fin: e.ventana[1], estado: estadosEntrega[i], tipo_vehiculo: vehiculosEntrega[i],
}))

export const rutas: Ruta[] = [
  { id: 'R-01', fecha: '2026-11-16', repartidor_id: 'REP-001', tipo_vehiculo: 'motocicleta', zona: 'Centro · Americana', bloque: 'manana', ventana_inicio: '07:00', ventana_fin: '09:00', capacidad_maxima: 15, entregas_asignadas: 15, distancia_km: 18.4, hora_fin_estimada: '08:52', excede_bloque: true, estado: 'borrador' },
  { id: 'R-02', fecha: '2026-11-16', repartidor_id: 'REP-002', tipo_vehiculo: 'bicicleta', zona: 'Providencia', bloque: 'manana', ventana_inicio: '07:00', ventana_fin: '09:00', capacidad_maxima: 8, entregas_asignadas: 6, distancia_km: 9.7, hora_fin_estimada: '08:31', excede_bloque: false, estado: 'borrador' },
  { id: 'R-03', fecha: '2026-11-16', repartidor_id: 'REP-003', tipo_vehiculo: 'automovil', zona: 'Zapopan', bloque: 'mediodia', ventana_inicio: '11:30', ventana_fin: '13:30', capacidad_maxima: 30, entregas_asignadas: 23, distancia_km: 26.1, hora_fin_estimada: '13:18', excede_bloque: false, estado: 'borrador' },
]

export const paradas: Parada[] = [
  { id: 'PAR-01-1', ruta_id: 'R-01', orden: 1, entrega_id: null, nombre_destinatario: 'Mariana López', direccion: 'Av. Vallarta 1248', hora_estimada: '07:18', estado: 'entregado' },
  { id: 'PAR-01-2', ruta_id: 'R-01', orden: 2, entrega_id: null, nombre_destinatario: 'Ana Paula Silva', direccion: 'Morelos 88', hora_estimada: '07:42', estado: 'pendiente' },
  { id: 'PAR-01-3', ruta_id: 'R-01', orden: 3, entrega_id: null, nombre_destinatario: 'Luis Navarro', direccion: 'Federalismo 331', hora_estimada: '08:16', estado: 'pendiente' },
  { id: 'PAR-02-1', ruta_id: 'R-02', orden: 1, entrega_id: null, nombre_destinatario: 'Sofía Hernández', direccion: 'Rubén Darío 742', hora_estimada: '07:25', estado: 'pendiente' },
  { id: 'PAR-02-2', ruta_id: 'R-02', orden: 2, entrega_id: null, nombre_destinatario: 'Pablo Castro', direccion: 'Lerdo de Tejada 68', hora_estimada: '07:51', estado: 'pendiente' },
  { id: 'PAR-03-1', ruta_id: 'R-03', orden: 1, entrega_id: null, nombre_destinatario: 'Jorge Ramírez', direccion: 'Calz. Federalismo 220', hora_estimada: '11:54', estado: 'pendiente' },
  { id: 'PAR-03-2', ruta_id: 'R-03', orden: 2, entrega_id: null, nombre_destinatario: 'Elena García', direccion: 'Patria 845', hora_estimada: '12:36', estado: 'pendiente' },
]

const inc = (id: string, tipo: Incidencia['tipo'], descripcion: string, prioridad: Incidencia['prioridad'], estado: Incidencia['estado'], extra: Partial<Incidencia> = {}): Incidencia => ({
  id, fecha: '2026-11-16', tipo, descripcion, prioridad, estado, hora: null, foto_url: null, nota: null, repartidor_reasignado_id: null, ruta_id: null, entrega_id: null, ...extra,
})

export const incidencias: Incidencia[] = [
  inc('INC-001', 'falta_repartidores', 'Faltan repartidores: 4 entregas de Guadalajara Centro', 'inmediata', 'abierta'),
  inc('INC-002', 'pago_pendiente', '3 pedidos tienen pago pendiente', 'normal', 'abierta'),
  inc('INC-003', 'ruta_excede_bloque', 'Ruta de Chapalita excede el bloque', 'inmediata', 'abierta', { ruta_id: 'R-01' }),
  inc('INC-004', 'entrega_no_localizada', 'Cliente no localizado en Colinas de San Javier', 'normal', 'resuelta', { entrega_id: 'EL-1052' }),
  inc('INC-005', 'otro', 'Retraso en recolección de Cocina Norte', 'normal', 'resuelta'),
  inc('INC-006', 'otro', 'Dirección incompleta en un pedido de Zapopan', 'normal', 'resuelta'),
  inc('INC-007', 'otro', 'Bolsa térmica dañada en ruta R-02', 'normal', 'resuelta', { ruta_id: 'R-02' }),
  inc('INC-008', 'repartidor_no_llego', 'Carlos Ramírez no se presentó a recolectar la ruta', 'inmediata', 'abierta', { ruta_id: 'R-01', hora: '07:12' }),
  inc('INC-009', 'empresa_cerrada', 'Logística Pacífico está cerrada por inventario y nadie recibe', 'normal', 'abierta', { ruta_id: 'R-03', hora: '11:48', foto_url: foto('1486406146926-c627a92ad1ab'), nota: 'Llamé a RR. HH. y no contestan.' }),
  inc('INC-010', 'platillo_danado', 'Una ensalada llegó derramada en la entrega de Providencia', 'normal', 'reasignada', { ruta_id: 'R-02', hora: '07:40', foto_url: foto('1512621776951-a57141f2eefd'), repartidor_reasignado_id: 'REP-005', nota: 'Se repone con una entrega de respaldo.' }),
  inc('INC-011', 'empleado_ausente', 'Empleado ausente en Farmacéutica Andrade; nadie recoge su comida', 'normal', 'resuelta', { ruta_id: 'R-03', hora: '12:20', nota: 'Se dejó con recepción.' }),
  inc('INC-012', 'empleado_ausente', 'Empleada de Consultoría Vértice no se encontraba en su piso', 'normal', 'abierta', { ruta_id: 'R-01', hora: '07:55' }),
  inc('INC-013', 'platillo_danado', 'Pollo teriyaki sin sellar en Zapopan', 'normal', 'resuelta', { ruta_id: 'R-03', hora: '12:05', foto_url: foto('1546069901-ba9599a7e63c'), nota: 'Se cambió por otra porción del comedor.' }),
]

export const resumen_dashboard: ResumenDashboard = {
  entregas_hoy: 248,
  entregas_completadas: 186,
  pedidos_activos: 36,
  en_consolidacion: 12,
  en_ruta: 24,
  ingresos_semana: 84620,
  variacion_ingresos_porcentaje: 8.2,
  pedidos_por_dia: [
    { dia: 'Lun', pedidos: 112 }, { dia: 'Mar', pedidos: 138 }, { dia: 'Mié', pedidos: 126 }, { dia: 'Jue', pedidos: 154 },
    { dia: 'Vie', pedidos: 178 }, { dia: 'Sáb', pedidos: 194 }, { dia: 'Dom', pedidos: 146 },
  ],
  entregas_por_estado: [
    { estado: 'Entregadas', cantidad: 142, color: '#43a86b' }, { estado: 'En ruta', cantidad: 38, color: '#3d7fe8' },
    { estado: 'Consolidación', cantidad: 29, color: '#e9b51f' }, { estado: 'Programadas', cantidad: 39, color: '#8c9aa7' },
  ],
  zonas_activas: [
    { zona: 'GDL Centro', x: '28%', y: '30%' }, { zona: 'Providencia', x: '51%', y: '23%' }, { zona: 'Chapalita', x: '65%', y: '54%' },
    { zona: 'Americana', x: '38%', y: '67%' }, { zona: 'Colinas', x: '77%', y: '73%' },
  ],
}

// ---------------------------------------------------------------- facturación y liquidaciones

const primerDiaMesSiguiente = (mes: string) => {
  const [y, m] = mes.split('-').map(Number)
  return new Date(Date.UTC(y, m, 1)).toISOString().slice(0, 10)
}

function estadoInicialFactura(mes: string, indice: number): EstadoFactura {
  if (mes === MES_ACTUAL) return 'borrador'
  if (mes === '2026-09') return indice % 3 === 0 ? 'emitida' : 'pagada'
  if (mes === '2026-08') return indice === 2 ? 'emitida' : 'pagada'
  return 'pagada'
}

function estadoInicialLiquidacion(mes: string, indice: number): EstadoLiquidacion {
  if (mes === MES_ACTUAL) return 'pendiente'
  if (mes === '2026-09') return indice === 4 ? 'pendiente' : 'pagado'
  return 'pagado'
}

export const facturas_mensuales: FacturaMensual[] = MESES.flatMap((mes) =>
  empresas.map((empresa, indice) => {
    const contrato = contratos_empresa.find((c) => c.empresa_id === empresa.id)!
    const lineas = calcularConsumo(empleados.filter((e) => e.empresa_id === empresa.id), (id) => usuarios.find((u) => u.id === id), elecciones_empleado, mes)
    const estado = estadoInicialFactura(mes, indice)
    const emitida_en = estado === 'borrador' ? null : primerDiaMesSiguiente(mes)
    return { ...calcularTotales(empresa.id, contrato, lineas, mes, folioFactura(mes, indice)), estado, emitida_en, pagada_en: estado === 'pagada' ? sumarDias(emitida_en!, 10) : null }
  }),
)

export const liquidaciones_comedor: LiquidacionComedor[] = MESES.flatMap((mes, indiceMes) =>
  comedoresBase.map((c, indice) => {
    const estado = estadoInicialLiquidacion(mes, indice)
    const emitida = primerDiaMesSiguiente(mes)
    const entregados = c.entregados[indiceMes]
    const minimos = 200
    const faltantes = Math.max(0, minimos - entregados)
    return {
      id: `LIQ-${mes}-${c.id}`, comedor_id: c.id, mes, platillos_entregados: entregados, precio_por_platillo: c.precio, platillos_minimos: minimos, platillos_faltantes: faltantes,
      diferencia_minimo: faltantes * c.precio, monto: (entregados + faltantes) * c.precio, estado, pagada_en: estado === 'pagado' ? sumarDias(emitida, 10) : null,
    }
  }),
)

// ---------------------------------------------------------------- planes, pedidos y soporte

export const planes: Plan[] = [
  { id: 'PLAN-1', nombre: 'Plan 1 día', descuento_porcentaje: 0, detalle: 'Elige una entrega', destacado: false },
  { id: 'PLAN-2', nombre: 'Plan 2 días', descuento_porcentaje: 5, detalle: 'Ahorra en tu segunda entrega', destacado: false },
  { id: 'PLAN-7', nombre: 'Plan semanal', descuento_porcentaje: 12, detalle: '6 días de comida resuelta', destacado: true },
]

export const pedidos: Pedido[] = [
  { folio: 'GDL-20261116-0001', cliente_id: 'USR-CLI-01', plan_id: 'PLAN-7', fecha_inicio: '2026-11-16', fecha_fin: '2026-11-21', zona: 'Centro', bloque: 'manana', total: 1194, estado: 'activo', entregas_totales: 6, entregas_completadas: 3, direccion_entrega: 'Av. Vallarta 1248, Americana', referencias: 'Portón negro, casa verde', metodo_pago: 'Mercado Pago', ultimos_digitos: '4821', tiene_evidencia: true },
  { folio: 'GDL-20261116-0002', cliente_id: 'USR-CLI-02', plan_id: 'PLAN-2', fecha_inicio: '2026-11-16', fecha_fin: '2026-11-17', zona: 'Zapopan', bloque: 'mediodia', total: 398, estado: 'recibido', entregas_totales: 2, entregas_completadas: 0, direccion_entrega: 'Calz. del Federalismo 220', referencias: 'Timbre junto a la cochera', metodo_pago: 'Stripe', ultimos_digitos: '0914', tiene_evidencia: false },
  { folio: 'GDL-20261116-0003', cliente_id: 'USR-CLI-03', plan_id: 'PLAN-1', fecha_inicio: '2026-11-16', fecha_fin: '2026-11-16', zona: 'Providencia', bloque: 'tarde', total: 219, estado: 'completado', entregas_totales: 1, entregas_completadas: 1, direccion_entrega: 'Av. Rubén Darío 742', referencias: 'Recepción del edificio', metodo_pago: 'Mercado Pago', ultimos_digitos: '7730', tiene_evidencia: true },
  { folio: 'GDL-20261116-0004', cliente_id: 'USR-CLI-04', plan_id: 'PLAN-7', fecha_inicio: '2026-11-16', fecha_fin: '2026-11-21', zona: 'Chapalita', bloque: 'manana', total: 1194, estado: 'pendiente_pago', entregas_totales: 6, entregas_completadas: 0, direccion_entrega: 'Tepeyac 1850, Chapalita', referencias: 'Casa esquina, fachada gris', metodo_pago: 'Stripe', ultimos_digitos: null, tiene_evidencia: false },
  { folio: 'GDL-20261115-0048', cliente_id: 'USR-CLI-05', plan_id: 'PLAN-2', fecha_inicio: '2026-11-15', fecha_fin: '2026-11-16', zona: 'Centro', bloque: 'manana', total: 398, estado: 'cancelado', entregas_totales: 2, entregas_completadas: 1, direccion_entrega: 'Morelos 88, Centro', referencias: 'Local con toldo azul', metodo_pago: 'Mercado Pago', ultimos_digitos: '2210', tiene_evidencia: true },
]

export const tickets: Ticket[] = [
  { id: 'TKT-4821', cliente_id: 'USR-CLI-01', asunto: 'No llegó mi comida', pedido_folio: 'GDL-20261116-0042', estado: 'abierto', mensajes_sin_leer: 2, actualizado_en: '2026-11-16T12:44:00' },
  { id: 'TKT-4818', cliente_id: 'USR-CLI-06', asunto: 'Cambiar dirección de entrega', pedido_folio: 'GDL-20261116-0037', estado: 'en_proceso', mensajes_sin_leer: 0, actualizado_en: '2026-11-16T12:30:00' },
  { id: 'TKT-4812', cliente_id: 'USR-CLI-07', asunto: '¿Puedo cambiar mi platillo?', pedido_folio: 'GDL-20261116-0029', estado: 'abierto', mensajes_sin_leer: 1, actualizado_en: '2026-11-16T12:17:00' },
  { id: 'TKT-4804', cliente_id: 'USR-CLI-02', asunto: 'Confirmación de suscripción', pedido_folio: 'GDL-20261116-0018', estado: 'resuelto', mensajes_sin_leer: 0, actualizado_en: '2026-11-15T18:42:00' },
  { id: 'TKT-4799', cliente_id: 'USR-CLI-08', asunto: 'Cobro duplicado', pedido_folio: 'GDL-20261115-0091', estado: 'cerrado', mensajes_sin_leer: 0, actualizado_en: '2026-11-15T15:10:00' },
]

export const mensajes_ticket: MensajeTicket[] = [
  { id: 'MSG-1', ticket_id: 'TKT-4821', autor: 'cliente', texto: 'Hola, mi entrega estaba programada para las 12:30 y todavía no llega. ¿Me pueden ayudar?', enviado_en: '2026-11-16T12:44:00' },
  { id: 'MSG-2', ticket_id: 'TKT-4821', autor: 'cliente', texto: 'El pedido es el GDL-20261116-0042.', enviado_en: '2026-11-16T12:45:00' },
  { id: 'MSG-3', ticket_id: 'TKT-4821', autor: 'agente', texto: 'Hola Mariana, ya estoy revisando tu pedido. Dame un momento, por favor.', enviado_en: '2026-11-16T12:46:00' },
  { id: 'MSG-4', ticket_id: 'TKT-4818', autor: 'cliente', texto: 'Necesito cambiar la dirección de entrega de mi pedido.', enviado_en: '2026-11-16T12:30:00' },
  { id: 'MSG-5', ticket_id: 'TKT-4812', autor: 'cliente', texto: '¿Puedo cambiar mi platillo de mañana?', enviado_en: '2026-11-16T12:17:00' },
  { id: 'MSG-6', ticket_id: 'TKT-4804', autor: 'cliente', texto: 'Quiero confirmar que mi suscripción quedó activa.', enviado_en: '2026-11-15T18:42:00' },
  { id: 'MSG-7', ticket_id: 'TKT-4799', autor: 'cliente', texto: 'Me hicieron un cobro duplicado en mi tarjeta.', enviado_en: '2026-11-15T15:10:00' },
]

/** Resumen del pedido que ve soporte junto a cada ticket. */
export const resumen_pedido_ticket = { plan: 'Plan semanal', proxima_entrega: 'Hoy, 12:30–14:00', zona: 'Guadalajara Centro', total: 1248, direccion: 'Av. Vallarta 1842, Col. Americana\nGuadalajara, Jalisco', referencias: 'Portón negro, casa verde' }

export const registros_auditoria: RegistroAuditoria[] = pedidos.flatMap((p) => [
  { id: `AUD-${p.folio}-1`, usuario_id: null, accion: 'cambio_estado', entidad: 'pedido', entidad_id: p.folio, detalle: 'Cambió el pedido a recibido', creado_en: '2026-11-16T06:42:00' },
  { id: `AUD-${p.folio}-2`, usuario_id: 'USR-REP-01', accion: 'cambio_estado', entidad: 'pedido', entidad_id: p.folio, detalle: 'Cambió el pedido a activo', creado_en: '2026-11-16T07:12:00' },
  { id: `AUD-${p.folio}-3`, usuario_id: null, accion: 'asignar_ruta', entidad: 'pedido', entidad_id: p.folio, detalle: 'Asignó la ruta al pedido', creado_en: '2026-11-16T07:18:00' },
])

// ---------------------------------------------------------------- operación: configuración, calendario y cortes

export const configuracion_operacion: ConfiguracionOperacion = {
  hora_corte: '18:00',
  zona_horaria: 'America/Mexico_City',
  dias_entrega: 'lunes_viernes',
  regla_sin_eleccion: 'ultimo_platillo',
  capacidad_auto_entregas: capacidades_vehiculo.automovil,
  capacidad_auto_platillos: 120,
}

export const dias_sin_servicio: DiaSinServicio[] = [
  { id: 'DSS-001', fecha: '2026-09-16', tipo: 'festivo', motivo: 'Día de la Independencia', empresa_id: null },
  { id: 'DSS-002', fecha: '2026-11-16', tipo: 'festivo', motivo: 'Revolución Mexicana', empresa_id: null },
  { id: 'DSS-003', fecha: '2026-11-20', tipo: 'sin_servicio', motivo: 'Mantenimiento de cocinas', empresa_id: null },
  { id: 'DSS-004', fecha: '2026-11-03', tipo: 'cierre_empresa', motivo: 'Inventario anual', empresa_id: 'EMP-002' },
  { id: 'DSS-005', fecha: '2026-12-25', tipo: 'festivo', motivo: 'Navidad', empresa_id: null },
]

export const cortes: Corte[] = [
  { id: 'CORTE-001', fecha_entrega: '2026-10-27', ejecutado_en: '2026-10-26T18:00:04', ejecutado_por: null, estado: 'fallido', sin_cambios: false, elecciones_cerradas: 0, empleados_por_defecto: 0, ordenes_generadas: 0, entregas_consolidadas: 0, rutas_creadas: 0, mensaje: 'No hay menú publicado para esa fecha.' },
  { id: 'CORTE-002', fecha_entrega: '2026-10-27', ejecutado_en: '2026-10-26T18:42:10', ejecutado_por: 'USR-ADM-01', estado: 'correcto', sin_cambios: false, elecciones_cerradas: 312, empleados_por_defecto: 41, ordenes_generadas: 5, entregas_consolidadas: 8, rutas_creadas: 3, mensaje: null },
  { id: 'CORTE-003', fecha_entrega: '2026-10-28', ejecutado_en: '2026-10-27T18:00:03', ejecutado_por: null, estado: 'correcto', sin_cambios: false, elecciones_cerradas: 318, empleados_por_defecto: 37, ordenes_generadas: 5, entregas_consolidadas: 8, rutas_creadas: 3, mensaje: null },
  { id: 'CORTE-004', fecha_entrega: '2026-10-29', ejecutado_en: '2026-10-28T18:00:05', ejecutado_por: null, estado: 'con_advertencias', sin_cambios: false, elecciones_cerradas: 301, empleados_por_defecto: 44, ordenes_generadas: 5, entregas_consolidadas: 8, rutas_creadas: 3, mensaje: '2 empleados sin cupo en ningún platillo.' },
  { id: 'CORTE-005', fecha_entrega: '2026-10-30', ejecutado_en: '2026-10-29T18:00:02', ejecutado_por: null, estado: 'correcto', sin_cambios: false, elecciones_cerradas: 325, empleados_por_defecto: 39, ordenes_generadas: 5, entregas_consolidadas: 8, rutas_creadas: 3, mensaje: null },
  { id: 'CORTE-006', fecha_entrega: '2026-10-30', ejecutado_en: '2026-10-29T19:15:40', ejecutado_por: 'USR-ADM-01', estado: 'correcto', sin_cambios: true, elecciones_cerradas: 325, empleados_por_defecto: 39, ordenes_generadas: 5, entregas_consolidadas: 8, rutas_creadas: 3, mensaje: 'Sin cambios; ya estaba cerrado.' },
]

/** Detalle de los cortes ejecutados en esta sesión (los del historial inicial solo tienen sus totales). */
export const resultados_corte: Record<string, ResultadoCorte> = {}

// Bitácora: acciones previas del equipo (las nuevas las registra /lib/api.ts).
registros_auditoria.push(...([
  ['2026-10-01T08:05:12', 'USR-ADM-01', 'generar_facturacion', 'factura_mensual', '2026-09', 'Generó la facturación de Septiembre 2026 (8 facturas)'],
  ['2026-10-05T10:20:41', 'USR-ADM-01', 'actualizar', 'configuracion_operacion', 'operacion', 'Cambió la hora de corte de 5:00 p. m. a 6:00 p. m.'],
  ['2026-10-08T12:31:09', 'USR-ADM-01', 'marcar_pagada', 'factura_mensual', 'EL-202609-002', 'Marcó como pagada la factura EL-202609-002'],
  ['2026-10-11T17:02:55', 'USR-ADM-01', 'marcar_pagada', 'liquidacion_comedor', 'LIQ-2026-09-COM-002', 'Marcó como pagada la liquidación de Sabor Casero Centro (2026-09)'],
  ['2026-10-12T09:44:20', 'USR-EMP-01', 'crear', 'empleado', 'EMP-001', 'Agregó 3 empleados a Grupo Industrial Nexo'],
  ['2026-10-14T15:18:33', 'USR-SOP-01', 'reprogramar', 'pedido', 'GDL-20261115-0048', 'Reprogramó la entrega del pedido GDL-20261115-0048'],
  ['2026-10-15T11:30:02', 'USR-ADM-01', 'renovar', 'contrato_empresa', 'EMP-007', 'Renovó el contrato de Alimentos Casa Real por 6 meses'],
  ['2026-10-15T16:12:47', 'USR-SOP-01', 'cerrar', 'ticket', 'TKT-4804', 'Cerró el ticket TKT-4804'],
  ['2026-10-19T08:50:18', 'USR-EMP-01', 'desactivar', 'empleado', 'EMP-001-E011', 'Desactivó al empleado Valeria Gómez'],
  ['2026-10-20T13:25:36', 'USR-ADM-01', 'crear', 'dia_sin_servicio', 'DSS-002', 'Marcó el 2026-11-16 como festivo: Revolución Mexicana'],
  ['2026-10-22T09:05:14', 'USR-SOP-02', 'responder', 'ticket', 'TKT-4799', 'Respondió el ticket TKT-4799'],
  ['2026-10-25T18:40:29', 'USR-COM-01', 'actualizar', 'disponibilidad_dia', 'lote', 'Actualizó la disponibilidad (6 cambios)'],
  ['2026-10-26T12:15:08', 'USR-COM-01', 'enviar_revision', 'platillo', 'P-002', 'Envió a revisión el platillo Avena con frutos rojos'],
  ['2026-10-27T10:33:51', 'USR-ADM-01', 'rechazar', 'platillo', 'P-005', 'Rechazó el platillo Tinga de setas'],
  ['2026-10-28T19:02:44', 'USR-ADM-01', 'crear', 'dia_sin_servicio', 'DSS-004', 'Cerró Logística Pacífico el 2026-11-03'],
  ['2026-10-28T14:10:22', 'USR-COM-01', 'marcar_lista', 'orden_produccion', 'COM-001:2026-10-29', 'Marcó como lista la producción del 2026-10-29'],
  ['2026-10-29T16:40:05', 'USR-ADM-01', 'actualizar', 'contrato_empresa', 'EMP-004', 'Cambió el subsidio de Textiles La Aurora de 60 % a 40 %'],
  ['2026-10-29T18:00:02', null, 'ejecutar_corte', 'corte', 'CORTE-005', 'Ejecutó el corte del 2026-10-30'],
  ['2026-10-29T11:21:37', 'USR-REP-01', 'entregar', 'ruta', 'R-1029-01', 'Marcó como entregada la parada de Alimentos Casa Real'],
  ['2026-10-30T09:14:26', 'USR-ADM-01', 'aprobar', 'platillo', 'P-001', 'Aprobó el platillo Pollo teriyaki con arroz'],
] as [string, string | null, string, string, string, string][]).map(([creado_en, usuario_id, accion, entidad, entidad_id, detalle], i) => ({ id: `AUD-HIST-${String(i + 1).padStart(3, '0')}`, usuario_id, accion, entidad, entidad_id, detalle, creado_en })))

// ---------------------------------------------------------------- cuentas: invitaciones de equipos y enlaces de restablecimiento

export const tokens_restablecimiento: TokenRestablecimiento[] = []

const invitar = (id: string, usuario_id: string, nombre: string, correo: string, rol: Invitacion['rol'], organizacion_tipo: Invitacion['organizacion_tipo'], organizacion_id: string | null, enviada_en: string, vence_en: string, aceptada_en: string | null) => {
  if (!usuarios.some((u) => u.id === usuario_id)) usuarios.push({ id: usuario_id, nombre, correo, telefono: null, rol, activo: aceptada_en !== null })
  invitaciones.push({ id, usuario_id, correo, rol, organizacion_tipo, organizacion_id, token: `inv-${id.toLowerCase()}`, enviada_en, vence_en, aceptada_en })
}

// Equipo de los comedores y de las empresas (además del contacto titular).
invitar('INV-COM-001-A', 'USR-COM-001-A', 'Ramiro Ortega', 'ramiro.ortega@cocinanorte.mx', 'comedor', 'comedor', 'COM-001', '2026-10-27T11:00:00', '2026-11-03T11:00:00', null)
invitar('INV-COM-001-B', 'USR-COM-001-B', 'Itzel Barajas', 'itzel.barajas@cocinanorte.mx', 'comedor', 'comedor', 'COM-001', '2026-10-08T16:40:00', '2026-10-15T16:40:00', null)
invitar('INV-COM-001-C', 'USR-COM-001-C', 'Daniel Quezada', 'daniel.quezada@cocinanorte.mx', 'comedor', 'comedor', 'COM-001', '2026-08-03T09:00:00', '2026-08-10T09:00:00', '2026-08-04T12:20:00')
invitar('INV-COM-002-A', 'USR-COM-002-A', 'Paola Reyes', 'paola.reyes@saborcasero.mx', 'comedor', 'comedor', 'COM-002', '2026-10-25T10:10:00', '2026-11-01T10:10:00', '2026-10-25T18:02:00')
invitar('INV-EMP-001-A', 'USR-EMP-001-A', 'Gabriel Soto', 'gabriel.soto@nexo.mx', 'empresa', 'empresa', 'EMP-001', '2026-10-26T13:00:00', '2026-11-02T13:00:00', null)
invitar('INV-EMP-001-B', 'USR-EMP-001-B', 'Lorena Pineda', 'lorena.pineda@nexo.mx', 'empresa', 'empresa', 'EMP-001', '2026-09-02T08:45:00', '2026-09-09T08:45:00', '2026-09-02T15:30:00')
// Equipo de la plataforma.
invitar('INV-ADM-02', 'USR-SOP-01', 'Ana Ruiz', 'ana.ruiz@easylunch.mx', 'soporte', 'plataforma', null, '2026-06-01T09:00:00', '2026-06-08T09:00:00', '2026-06-01T09:40:00')
invitar('INV-ADM-03', 'USR-SOP-02', 'Carlos Méndez', 'carlos.mendez@easylunch.mx', 'soporte', 'plataforma', null, '2026-06-01T09:00:00', '2026-06-08T09:00:00', '2026-06-02T10:05:00')
invitar('INV-ADM-04', 'USR-ADM-02', 'Valeria Ibarra', 'valeria.ibarra@easylunch.mx', 'administrador', 'plataforma', null, '2026-10-29T17:20:00', '2026-11-05T17:20:00', null)

/** Mensajes que llegan desde el formulario de contacto del sitio público. */
export const mensajes_contacto: MensajeContacto[] = []
