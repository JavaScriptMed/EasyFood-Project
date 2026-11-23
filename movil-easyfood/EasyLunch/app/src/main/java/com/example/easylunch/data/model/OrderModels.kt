package com.example.easylunch.data.model

import com.google.gson.annotations.SerializedName

data class PaqueteDto(
    val id: Int,
    val nombre: String,
    val dias: Int,
    val descuento_porcentaje: Double,
    val detalle: String?,
    val destacado: Boolean
)

data class PedidoMenuSummaryDto(
    val id: Int? = null,
    val name: String? = null,
    val price: Double? = null
)

data class PedidoPlatilloItemDto(
    @SerializedName("tiempo_comida")
    val tiempo_comida: String? = null,
    @SerializedName("es_extra")
    val es_extra: Boolean = false,
    @SerializedName(value = "platillo_id", alternate = ["menu_id"])
    val platillo_id: Int? = null,
    @SerializedName("platillo_nombre")
    val platillo_nombre: String? = null,
    @SerializedName("precio_congelado")
    val precio_congelado: Double? = null
)

data class PedidoDiaDto(
    val id: Int? = null,
    val fecha: String? = null,
    @SerializedName("fecha_entrega")
    val fecha_entrega: String? = null,
    val estado: String? = null,
    val platillos: List<PedidoPlatilloItemDto>? = null,
    // Compatibilidad con respuestas planas o anteriores
    @SerializedName(value = "platillo_id", alternate = ["menu_id"])
    val platillo_id: Int? = null,
    @SerializedName("platillo_nombre")
    val platillo_nombre: String? = null,
    val precio_congelado: Double? = null,
    val menu: PedidoMenuSummaryDto? = null
) {
    val resolvedPlatilloId: Int?
        get() = platillo_id ?: menu?.id

    val resolvedPlatilloNombre: String?
        get() = platillo_nombre ?: menu?.name
}

data class PedidoDto(
    val id: Int,
    val folio: String?,
    @SerializedName(value = "plan_id", alternate = ["paquete_id"])
    val plan_id: Int? = null,
    @SerializedName("plan_nombre")
    val plan_nombre: String? = null,
    val direccion_id: Int? = null,
    @SerializedName("direccion_entrega")
    val direccion_entrega: String? = null,
    @SerializedName("referencias")
    val referencias: String? = null,
    val zona: String? = null,
    @SerializedName("fecha_entrega")
    val fecha_entrega: String? = null,
    val fecha_inicio: String?,
    val fecha_fin: String?,
    val total: Double,
    val estado: String,
    val metodo_pago: String?,
    val ultimos_digitos: String?,
    val pagado_en: String? = null,
    val vence_pago_en: String?,
    val segundos_para_pagar: Int?,
    @SerializedName("comidas_por_dia")
    val comidas_por_dia: Int? = null,
    @SerializedName("con_extra")
    val con_extra: Boolean? = null,
    @SerializedName("reembolsado_en")
    val reembolsado_en: String? = null,
    @SerializedName("monto_reembolsado")
    val monto_reembolsado: Double? = null,
    @SerializedName("stripe_refund_id")
    val stripe_refund_id: String? = null,
    val paquete: PaqueteDto? = null,
    val direccion: AddressDto? = null,
    val dias: List<PedidoDiaDto>? = null
) {
    val resolvedNombrePaquete: String
        get() = paquete?.nombre?.takeIf { it.isNotBlank() }
            ?: plan_nombre?.takeIf { it.isNotBlank() }
            ?: "Paquete Estándar"

    val resolvedDireccionTexto: String?
        get() = when {
            pedidoDireccionCompleta != null -> pedidoDireccionCompleta
            !direccion_entrega.isNullOrBlank() -> direccion_entrega
            else -> null
        }

    private val pedidoDireccionCompleta: String?
        get() = direccion?.let { dir ->
            val etiqueta = dir.label?.takeIf { it.isNotBlank() }
            if (etiqueta != null) "$etiqueta — ${dir.street_address}" else dir.street_address
        }
}

data class PlatillosDiaRequest(
    val desayuno: Int? = null,
    val comida: Int? = null,
    val cena: Int? = null,
    val extra: Int? = null
)

data class CreateOrderRequest(
    val paquete_id: Int,
    val direccion_id: Int,
    val platillos: List<PlatillosDiaRequest>
)

data class PayOrderRequest(
    val metodo_pago: String,
    val ultimos_digitos: String
)

data class StripePaymentIntentResponse(
    val pedido_id: Int,
    val payment_intent_id: String,
    val client_secret: String,
    val monto: String,
    val moneda: String
)

data class ProximaEntregaResponse(
    val fecha_entrega: String,
    val cierra_en: String?,
    val dias_de_comida: List<String> = emptyList()
)

data class CalificarEntregaRequest(
    val fecha: String,
    val puntualidad: Int,
    val trato_chofer: Int,
    val empaque: Int,
    val comentario: String? = null
)

data class CalificarPlatilloRequest(
    val fecha: String,
    val platillo_id: Int,
    val estrellas: Int,
    val comentario: String? = null
)