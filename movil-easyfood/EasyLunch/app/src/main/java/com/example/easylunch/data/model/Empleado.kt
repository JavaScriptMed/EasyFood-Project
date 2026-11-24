package com.example.easylunch.data.model

data class EmpleadoInfoDto(
    val id: Int?,
    val nombre: String?,
    val empresa_nombre: String?,
    val activo: Boolean?
)

data class PlatilloDto(
    val id: Int,
    val comedor_nombre: String?,
    val nombre: String,
    val descripcion: String?,
    val tiempo_comida: String?,
    val calorias: Int?,
    val alergenos: List<String>?,
    val etiquetas: List<String>?,
    val foto_url: String?
)

data class OpcionMenuDto(
    val platillo: PlatilloDto,
    val porciones_maximas: Int?,
    val porciones_tomadas: Int?,
    val porciones_restantes: Int?,
    val agotado: Boolean?
)

data class EleccionActualDto(
    val platillo_id: Int?,
    val platillo_nombre: String?,
    val origen: String?
)

data class EmpleadoMenuDto(
    val fecha: String,
    val tiempo_comida: String,
    val hora_corte: String?,
    val cerrado: Boolean?,
    val motivo_cerrado: String?,
    val empleado: EmpleadoInfoDto?,
    val hora_entrega: String?,
    val precio: Double?,
    val porcentaje_subsidio: Double?,
    val empresa_cubre: Double?,
    val empleado_paga: Double?,
    val opciones: List<OpcionMenuDto>?,
    val eleccion: EleccionActualDto?
)

data class EleccionRequest(val fecha: String, val tiempo_comida: String, val platillo_id: Int)
data class QuitarEleccionRequest(val fecha: String, val tiempo_comida: String)

data class EleccionResponse(
    val id: Int?,
    val fecha: String?,
    val tiempo_comida: String?,
    val platillo_id: Int?,
    val origen: String?
)

data class HistorialEleccionDto(
    val id: Int,
    val fecha: String,
    val tiempo_comida: String?,
    val platillo_nombre: String?,
    val precio: Double?,
    val empresa_cubre: Double?,
    val empleado_paga: Double?,
    val origen: String?,
    val cerrada: Boolean?
)

data class PendientePlatilloDto(
    val tiempo_comida: String,
    val platillo_id: Int?,
    val platillo_nombre: String?,
    val calificado: Boolean?
)

data class PendienteCalificacionDto(
    val fecha: String,
    val entrega_calificada: Boolean?,
    val platillos: List<PendientePlatilloDto>?
)

data class CalificarPlatilloEmpleadoRequest(
    val fecha: String,
    val tiempo_comida: String,
    val estrellas: Int,
    val comentario: String? = null
)
