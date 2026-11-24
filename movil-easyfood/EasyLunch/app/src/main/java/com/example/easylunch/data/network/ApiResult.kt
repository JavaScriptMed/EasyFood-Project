package com.example.easylunch.data.network

import com.google.gson.JsonObject
import com.google.gson.JsonParser
import retrofit2.Response
import java.io.IOException

/** Error ya traducido para mostrar a la persona. Nunca contiene JSON crudo. */
data class ApiError(
    val http: Int?,
    val codigo: String?,
    val mensaje: String,
    val campos: Map<String, List<String>> = emptyMap(),
    val retryAfter: Int? = null
) {
    val sinConexion: Boolean get() = http == null && codigo == "sin_conexion"
    fun campo(nombre: String): String? = campos[nombre]?.firstOrNull()
}

sealed interface ApiResult<out T> {
    data class Ok<T>(val datos: T) : ApiResult<T>
    data class Err(val error: ApiError) : ApiResult<Nothing>
}

object ApiErrors {

    /** Textos para cuando el servidor no manda `mensaje` y sugerencias de qué hacer. */
    private val textos = mapOf(
        // Cliente
        "sin_porciones" to "Ya no quedan porciones de ese platillo para ese día. Elige otro.",
        "sin_cobertura" to "Esa dirección está fuera de la zona de entrega. Elige o agrega una dirección dentro de cobertura.",
        "platillos_incompletos" to "Faltan platillos: llena todos los días del paquete.",
        "comidas_por_dia_invalidas" to "Cada día lleva de 1 a 3 comidas.",
        "comidas_distintas_por_dia" to "Todos los días deben llevar los mismos tiempos de comida.",
        "extra_distinto_por_dia" to "El extra va en todos los días o en ninguno.",
        "platillo_de_otro_tiempo" to "Ese platillo no es de ese tiempo de comida.",
        "platillo_no_disponible" to "Ese platillo ya no está disponible. Elige otro.",
        "paquete_inactivo" to "Ese paquete ya no está disponible.",
        "sin_dia_de_entrega" to "Por ahora no hay sábados de entrega disponibles.",
        "pedido_no_pagable" to "Este pedido ya no se puede pagar.",
        "pedido_vencido" to "Se venció el plazo para pagar este pedido. Haz el pedido de nuevo.",
        "pagos_no_configurados" to "Los pagos con tarjeta no están disponibles. Intenta de nuevo en un momento.",
        "direccion_no_encontrada" to "No pudimos ubicar esa dirección. Marca el punto en el mapa.",
        "direccion_en_uso" to "Esta dirección tiene pedidos y no se puede eliminar. Puedes editarla.",
        // Empleado
        "corte_cerrado" to "Ya pasó el corte de ese día: la elección no se puede cambiar.",
        "dia_sin_servicio" to "Ese día no hay servicio para tu empresa.",
        "sin_contrato_vigente" to "Tu empresa no tiene contrato vigente para ese día.",
        "empleado_inactivo" to "Tu cuenta de empleado o tu empresa están desactivadas.",
        "sin_eleccion" to "No hay una elección para ese día y tiempo de comida.",
        // Calificaciones
        "fuera_de_plazo" to "Solo se califica lo entregado en los últimos 14 días.",
        "sin_entrega_que_calificar" to "Todavía no hay una entrega recibida que calificar.",
        "sin_platillo_que_calificar" to "Ese platillo no se puede calificar.",
        "ya_calificada" to "Ya lo calificaste.",
        // Repartidor
        "ruta_en_curso" to "Tienes una ruta en curso: termínala antes de ponerte fuera de línea.",
        "fuera_de_linea" to "Ponte en línea para iniciar paradas.",
        "ruta_completada" to "Esa ruta ya se completó.",
        "ruta_de_otro_dia" to "Solo puedes iniciar las rutas de hoy.",
        "otra_ruta_en_curso" to "Ya tienes otra ruta en curso.",
        "parada_en_camino" to "Esa parada ya está en camino: confirma la entrega o márcala no entregada.",
        "parada_no_iniciada" to "Primero inicia esta parada.",
        "parada_ya_atendida" to "Esa parada ya se atendió.",
        "orden_de_paradas" to "Hay una parada anterior sin atender.",
        // Cuentas
        "enlace_invalido" to "El enlace ya no sirve. Pide uno nuevo.",
        // Generales
        "no_autenticado" to "Tu sesión terminó. Inicia sesión de nuevo.",
        "sin_permiso" to "Tu cuenta no tiene permiso para esto.",
        "no_encontrado" to "No encontramos lo que buscas.",
        "demasiados_intentos" to "Demasiados intentos: espera un momento y vuelve a intentarlo."
    )

    fun textoDeCodigo(codigo: String?): String? = codigo?.let { textos[it] }

    private fun porHttp(code: Int): String = when (code) {
        401 -> "Tu sesión terminó. Inicia sesión de nuevo."
        403 -> "Tu cuenta no tiene permiso para esto."
        404 -> "No encontramos lo que buscas."
        408 -> "El servidor tardó demasiado. Intenta de nuevo."
        422 -> "Revisa los datos e intenta de nuevo."
        429 -> "Demasiados intentos: espera un momento y vuelve a intentarlo."
        in 500..599 -> "El servidor tuvo un problema. Intenta de nuevo en un momento."
        else -> "Algo salió mal (código $code). Intenta de nuevo."
    }

    fun parse(response: Response<*>): ApiError {
        val code = response.code()
        val retry = response.headers()["Retry-After"]?.toIntOrNull()
        val raw = try { response.errorBody()?.string() } catch (_: Exception) { null }
        val obj: JsonObject? = try {
            raw?.takeIf { it.isNotBlank() }?.let { JsonParser.parseString(it) }?.takeIf { it.isJsonObject }?.asJsonObject
        } catch (_: Exception) { null }

        val codigo = obj?.get("codigo")?.takeIf { it.isJsonPrimitive }?.asString
        val campos = mutableMapOf<String, List<String>>()
        val erroresEl = obj?.get("errores") ?: obj?.get("errors")
        if (erroresEl != null && erroresEl.isJsonObject) {
            erroresEl.asJsonObject.entrySet().forEach { (k, v) ->
                val lista = if (v.isJsonArray) v.asJsonArray.mapNotNull { e -> e.takeIf { it.isJsonPrimitive }?.asString }
                else if (v.isJsonPrimitive) listOf(v.asString) else emptyList()
                if (lista.isNotEmpty()) campos[k] = lista
            }
        }
        val servidor = (obj?.get("mensaje") ?: obj?.get("message"))?.takeIf { it.isJsonPrimitive }?.asString

        val esRolEquivocado = code == 403 && obj?.has("required_roles") == true
        val mensaje = when {
            esRolEquivocado -> "Esta función no corresponde a tu tipo de cuenta."
            code == 401 -> porHttp(401)
            code >= 500 && codigo == null -> porHttp(code)
            campos.isNotEmpty() && codigo == null -> campos.values.flatten().distinct().joinToString("\n")
            !servidor.isNullOrBlank() && servidor != "Unauthenticated." -> servidor
            else -> textoDeCodigo(codigo) ?: porHttp(code)
        }
        return ApiError(http = code, codigo = codigo, mensaje = mensaje, campos = campos, retryAfter = retry)
    }

    fun deExcepcion(e: Throwable): ApiError = when (e) {
        is IOException -> ApiError(null, "sin_conexion", "No hay conexión con el servidor. Revisa tu internet e intenta de nuevo.")
        else -> ApiError(null, "error_app", "Ocurrió un error inesperado. Intenta de nuevo.")
    }
}

suspend fun <T> safeCall(block: suspend () -> Response<T>): ApiResult<T> = try {
    val r = block()
    val body = r.body()
    if (r.isSuccessful && body != null) ApiResult.Ok(body)
    else if (r.isSuccessful) ApiResult.Err(ApiError(r.code(), null, "El servidor respondió sin datos."))
    else ApiResult.Err(ApiErrors.parse(r))
} catch (e: kotlinx.coroutines.CancellationException) {
    throw e
} catch (e: Exception) {
    ApiResult.Err(ApiErrors.deExcepcion(e))
}
