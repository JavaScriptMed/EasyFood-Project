package com.example.easylunch.data.model

import com.google.gson.JsonElement

data class DireccionDto(
    val id: Int,
    val etiqueta: String?,
    val direccion: String,
    val numero_interior: String?,
    val codigo_postal: String?,
    val latitud: Double?,
    val longitud: Double?,
    val referencias: String?,
    val predeterminada: Boolean?,
    val zona: String?,
    val sin_cobertura: Boolean?
) {
    val fueraDeCobertura: Boolean get() = sin_cobertura == true
}

data class DireccionRequest(
    val etiqueta: String?,
    val direccion: String,
    val numero_interior: String?,
    val codigo_postal: String?,
    val latitud: Double,
    val longitud: Double,
    val referencias: String?,
    val predeterminada: Boolean
)

/** Zona activa con su polígono (endpoint `GET /api/v1/zonas`, pendiente en el backend). */
data class ZonaDto(val id: Int, val nombre: String, val geojson: JsonElement?)

data class ProximaEntregaResponse(
    val fecha_entrega: String,
    val cierra_en: String?,
    val dias_de_comida: List<String> = emptyList()
)

data class CeldaMenuDto(
    val platillo_id: Int,
    val platillo_nombre: String,
    val precio_venta: Double?,
    val porciones_maximas: Int?,
    /** Hoy el backend no lo manda en menu-semanal; si lo agrega, la app ya muestra la foto. */
    val foto_url: String? = null
)

data class MenuSemanalDiaDto(
    val fecha: String,
    val celdas: Map<String, CeldaMenuDto>? = null
)

data class PedidoPlatilloDto(
    val tiempo_comida: String?,
    val es_extra: Boolean?,
    val platillo_id: Int?,
    val platillo_nombre: String?,
    val precio_congelado: Double?
)

data class PedidoDiaDto(
    val id: Int?,
    val fecha: String?,
    val fecha_entrega: String?,
    val estado: String?,
    val platillos: List<PedidoPlatilloDto>?
)

data class PedidoDto(
    val id: Int,
    val folio: String?,
    val plan_id: Int?,
    val plan_nombre: String?,
    val fecha_entrega: String?,
    val fecha_inicio: String?,
    val fecha_fin: String?,
    val zona: String?,
    val total: Double?,
    val estado: String,
    val entregas_totales: Int?,
    val entregas_completadas: Int?,
    val direccion_entrega: String?,
    val referencias: String?,
    val metodo_pago: String?,
    val ultimos_digitos: String?,
    val tiene_evidencia: Boolean?,
    val vence_pago_en: String?,
    val segundos_para_pagar: Long?,
    val comidas_por_dia: Int?,
    val con_extra: Boolean?,
    val reembolsado_en: String?,
    val monto_reembolsado: Double?,
    val dias: List<PedidoDiaDto>?
)

data class PlatillosDiaRequest(
    val desayuno: Int? = null,
    val comida: Int? = null,
    val cena: Int? = null,
    val extra: Int? = null
)

data class CrearPedidoRequest(
    val paquete_id: Int,
    val direccion_id: Int,
    val platillos: List<PlatillosDiaRequest>
)

data class PagoResponse(
    val pedido_id: Int?,
    val payment_intent_id: String?,
    val client_secret: String?,
    val monto: String?,
    val moneda: String?
)

data class CalificarEntregaRequest(
    val fecha: String,
    val puntualidad: Int,
    val trato_chofer: Int,
    val empaque: Int,
    val comentario: String? = null
)

data class CalificarPlatilloClienteRequest(
    val fecha: String,
    val platillo_id: Int,
    val estrellas: Int,
    val comentario: String? = null
)
