package com.example.easylunch.data.model

data class DisponibilidadDto(
    val en_linea: Boolean,
    val estado: String?,
    val ruta_en_curso_id: Int?
)

data class DisponibilidadRequest(val en_linea: Boolean)

data class UbicacionDto(val latitud: Double, val longitud: Double)

data class PlatilloParadaDto(
    val nombre: String?,
    val tiempo_comida: String?,
    val cantidad: Int?,
    val dia_de_comida: String?,
    val es_extra: Boolean?
)

data class ParadaDto(
    val id: Int,
    val orden: Int,
    val estado: String,
    val tipo: String?,
    val destinatario: String?,
    val direccion: String?,
    val ubicacion: UbicacionDto?,
    val hora_estimada: String?,
    val platillos: List<PlatilloParadaDto>?,
    val total_platillos: Int?,
    val iniciada_en: String?,
    val cerrada_en: String?,
    val evidencia_foto_url: String?,
    val motivo_no_entrega: String?,
    val referencias: String?,
    val contacto_nombre: String?,
    val telefono: String?,
    val folio: String?
)

data class RutaDto(
    val id: Int,
    val fecha: String?,
    val estado: String,
    val zona: String?,
    val tipo: String?,
    val ventana_inicio: String?,
    val ventana_fin: String?,
    val entregas_asignadas: Int?,
    val platillos_asignados: Int?,
    val iniciada_en: String?,
    val completada_en: String?,
    val paradas_totales: Int?,
    val paradas_cerradas: Int?,
    val siguiente_parada_id: Int?,
    val paradas: List<ParadaDto>? = null
)

data class AccionParadaResponse(val parada: ParadaDto, val ruta: RutaDto)

data class NoEntregadaRequest(val motivo: String)
