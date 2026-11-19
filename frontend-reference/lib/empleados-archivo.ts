// Lectura del archivo de empleados ("nombre" y "correo") que usan el portal de la empresa y el alta de empresa del administrador.
// Acepta CSV (coma o punto y coma) y Excel .xlsx. El correo puede ir vacío.

import type { CorreoOmitido } from './types'

export type PersonaDelArchivo = { nombre: string; correo: string }

const quitarComillas = (valor: string) => valor.trim().replace(/^"|"$/g, '').trim()

/** Un correo con forma válida (el backend vuelve a revisarlo: aquí solo se evita mandar un archivo que sabemos que rechazará). */
export const correoValido = (correo: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo)

/** Filas ya separadas en celdas → personas. Se salta las filas vacías y el encabezado (nombre / correo). */
function filasAPersonas(filas: string[][]): PersonaDelArchivo[] {
  return filas
    .map((celdas) => celdas.map((c) => c.trim()))
    .filter(([nombre]) => nombre && !/^(nombre|name)\b/i.test(nombre))
    .map(([nombre, correo = '']) => ({ nombre, correo }))
}

/** Lee un CSV "nombre,correo" (con o sin encabezado, separado por coma o punto y coma). */
export function leerCsvDeEmpleados(texto: string): PersonaDelArchivo[] {
  return filasAPersonas(texto.replace(/^﻿/, '').split(/\r?\n/).map((linea) => linea.split(/[;,]/).map(quitarComillas)))
}

const esXlsx = (archivo: File) => /\.xlsx$/i.test(archivo.name) || /spreadsheetml\.sheet$/i.test(archivo.type)
/** Otros formatos de hoja de cálculo (Excel antiguo, LibreOffice, Numbers): no se leen. */
const esOtraHoja = (archivo: File) => /\.(xlsm|xlsb|xls|ods|numbers)$/i.test(archivo.name) || /ms-excel|opendocument\.spreadsheet/i.test(archivo.type)

/** Texto de una celda de Excel: los números (un teléfono, un id) y las fechas se pasan a texto; lo vacío queda ''. */
const textoDeCelda = (celda: unknown) => (celda === null || celda === undefined ? '' : celda instanceof Date ? celda.toISOString().slice(0, 10) : String(celda))

/** Lee la primera hoja de un .xlsx (columna A = nombre, columna B = correo). La librería se carga solo cuando hace falta. */
async function leerXlsxDeEmpleados(archivo: File): Promise<PersonaDelArchivo[]> {
  const { readSheet } = await import('read-excel-file/web-worker')
  const filas = (await readSheet(archivo)) as unknown[][]
  return filasAPersonas(filas.map((fila) => fila.map(textoDeCelda)))
}

/**
 * Personas de un archivo subido: .xlsx (Excel) o CSV. Si el archivo es de otra hoja de cálculo o no se puede leer,
 * lanza un error con un mensaje listo para mostrarse.
 */
export async function leerArchivoDeEmpleados(archivo: File): Promise<PersonaDelArchivo[]> {
  if (esXlsx(archivo)) {
    try {
      return await leerXlsxDeEmpleados(archivo)
    } catch {
      throw new Error(`No se pudo leer «${archivo.name}». Revisa que sea un Excel .xlsx válido, o guárdalo como CSV UTF-8 y súbelo otra vez.`)
    }
  }
  if (esOtraHoja(archivo)) {
    throw new Error(`«${archivo.name}» no es un formato que podamos leer. Ábrelo en Excel y guárdalo como Libro de Excel (.xlsx) o como CSV UTF-8, y súbelo otra vez.`)
  }
  return leerCsvDeEmpleados(await archivo.text())
}

/** Qué archivos acepta el selector. */
export const ARCHIVOS_DE_EMPLEADOS = '.csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'

export const MOTIVOS_DE_OMISION: Record<CorreoOmitido['motivo'], string> = {
  ya_tiene_cuenta: 'ya tiene una cuenta en EasyLunch',
  repetido_en_el_archivo: 'está repetido en el archivo',
}
