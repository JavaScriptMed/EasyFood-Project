package com.example.easylunch.data.model

data class ConfiguracionOperacion(
    val hora_corte: String?,
    val zona_horaria: String?,
    val dias_entrega: String?,
    val regla_sin_eleccion: String?
)

data class PaqueteDto(
    val id: Int,
    val nombre: String,
    val dias: Int,
    val descuento_porcentaje: Double?,
    val detalle: String?,
    val destacado: Boolean?
)

data class CalificacionesMenuSemanal(
    val semana_inicio: String?,
    val semana_fin: String?,
    val promedio: Double?,
    val calificaciones: Int?
)

/** Respuesta 201 de cualquier calificación. */
data class CalificacionResponse(val id: Int?, val mensaje: String?)
