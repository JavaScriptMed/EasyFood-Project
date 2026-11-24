package com.example.easylunch.ui.viewmodel

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.example.easylunch.data.model.CalificarEntregaRequest
import com.example.easylunch.data.model.CalificarPlatilloRequest
import com.example.easylunch.data.model.CreateOrderRequest
import com.example.easylunch.data.model.PaqueteDto
import com.example.easylunch.data.model.PedidoDto
import com.example.easylunch.data.model.PlatillosDiaRequest
import com.example.easylunch.data.model.ProximaEntregaResponse
import com.example.easylunch.data.model.StripePaymentIntentResponse
import com.example.easylunch.data.network.RetrofitClient
import kotlinx.coroutines.launch

class OrderViewModel : ViewModel() {
    var paquetes by mutableStateOf<List<PaqueteDto>>(emptyList())
        private set
    var pedidos by mutableStateOf<List<PedidoDto>>(emptyList())
        private set
    var proximaEntrega by mutableStateOf<ProximaEntregaResponse?>(null)
        private set
    var ultimoPaymentIntent by mutableStateOf<StripePaymentIntentResponse?>(null)
        private set
    var estadoOrden by mutableStateOf("")
        private set
    var estadoCalificacion by mutableStateOf("")
        private set

    fun limpiarEstadoCalificacion() {
        estadoCalificacion = ""
    }

    fun cargarPaquetes() {
        viewModelScope.launch {
            try {
                val response = RetrofitClient.apiService.getPaquetes()
                if (response.isSuccessful) {
                    paquetes = response.body() ?: emptyList()
                }
            } catch (_: Exception) {}
        }
    }

    fun cargarProximaEntrega() {
        viewModelScope.launch {
            try {
                val response = RetrofitClient.apiService.getProximaEntrega()
                if (response.isSuccessful) {
                    proximaEntrega = response.body()
                }
            } catch (_: Exception) {}
        }
    }

    fun cargarPedidos() {
        viewModelScope.launch {
            try {
                val response = RetrofitClient.apiService.getPedidos()
                if (response.isSuccessful) {
                    pedidos = response.body() ?: emptyList()
                }
            } catch (_: Exception) {}
        }
    }

    fun crearPedido(
        paqueteId: Int,
        direccionId: Int,
        platillosPorDia: List<PlatillosDiaRequest>,
        onSuccess: (PedidoDto?) -> Unit
    ) {
        viewModelScope.launch {
            estadoOrden = "Generando pedido..."
            try {
                val request = CreateOrderRequest(
                    paquete_id = paqueteId,
                    direccion_id = direccionId,
                    platillos = platillosPorDia
                )
                val response = RetrofitClient.apiService.crearPedido(request)
                if (response.isSuccessful) {
                    val creado = response.body()
                    estadoOrden = "¡Pedido creado con éxito!"
                    cargarPedidos()
                    onSuccess(creado)
                } else {
                    val errorBody = response.errorBody()?.string()
                    estadoOrden = if (!errorBody.isNullOrBlank()) {
                        "Error (${response.code()}): $errorBody"
                    } else {
                        "Error del servidor: Código ${response.code()}"
                    }
                }
            } catch (e: Exception) {
                estadoOrden = "Fallo de red: ${e.localizedMessage}"
            }
        }
    }

    /**
     * Solicita al backend el PaymentIntent de Stripe (`POST v1/cliente/pedidos/{id}/pago`).
     * El cambio de estado a "recibido" ocurre de forma asíncrona vía webhook cuando Stripe confirma el cobro.
     */
    fun iniciarPagoStripe(
        pedidoId: Int,
        onError: (String) -> Unit = {},
        onReady: (StripePaymentIntentResponse) -> Unit
    ) {
        viewModelScope.launch {
            estadoOrden = "Preparando pago con Stripe..."
            try {
                val response = RetrofitClient.apiService.iniciarPagoStripe(pedidoId)
                if (response.isSuccessful && response.body() != null) {
                    val intentData = response.body()!!
                    ultimoPaymentIntent = intentData
                    estadoOrden = "Intento de pago listo (${intentData.monto} ${intentData.moneda})"
                    onReady(intentData)
                } else if (response.code() == 503) {
                    val msg = "Los pagos con tarjeta todavía no están configurados en el servidor (503)."
                    estadoOrden = msg
                    onError(msg)
                } else {
                    val errorBody = response.errorBody()?.string()
                    val msg = if (!errorBody.isNullOrBlank()) {
                        "No se pudo iniciar el pago (${response.code()}): $errorBody"
                    } else {
                        "No se pudo iniciar el pago: Código ${response.code()}"
                    }
                    estadoOrden = msg
                    onError(msg)
                }
            } catch (e: Exception) {
                val msg = "Fallo de red: ${e.localizedMessage ?: e.javaClass.simpleName}"
                estadoOrden = msg
                onError(msg)
            }
        }
    }

    fun refrescarTrasPago() {
        viewModelScope.launch {
            cargarPedidos()
        }
    }

    fun calificarEntrega(
        pedidoId: Int,
        fecha: String,
        puntualidad: Int,
        tratoChofer: Int,
        empaque: Int,
        comentario: String?,
        onSuccess: () -> Unit = {}
    ) {
        viewModelScope.launch {
            estadoCalificacion = "Enviando calificación de entrega..."
            try {
                val request = CalificarEntregaRequest(
                    fecha = fecha,
                    puntualidad = puntualidad,
                    trato_chofer = tratoChofer,
                    empaque = empaque,
                    comentario = comentario?.trim()?.ifEmpty { null }
                )
                val response = RetrofitClient.apiService.calificarEntrega(pedidoId, request)
                if (response.isSuccessful) {
                    estadoCalificacion = "¡Gracias por calificar la entrega!"
                    onSuccess()
                } else {
                    val err = response.errorBody()?.string()
                    estadoCalificacion = if (!err.isNullOrBlank()) {
                        "Error (${response.code()}): $err"
                    } else {
                        "No se pudo calificar la entrega: Código ${response.code()}"
                    }
                }
            } catch (e: Exception) {
                estadoCalificacion = "Fallo de red: ${e.localizedMessage}"
            }
        }
    }

    fun calificarPlatillo(
        pedidoId: Int,
        fecha: String,
        platilloId: Int,
        estrellas: Int,
        comentario: String?,
        onSuccess: () -> Unit = {}
    ) {
        viewModelScope.launch {
            estadoCalificacion = "Enviando calificación del platillo..."
            try {
                val request = CalificarPlatilloRequest(
                    fecha = fecha,
                    platillo_id = platilloId,
                    estrellas = estrellas,
                    comentario = comentario?.trim()?.ifEmpty { null }
                )
                val response = RetrofitClient.apiService.calificarPlatillo(pedidoId, request)
                if (response.isSuccessful) {
                    estadoCalificacion = "¡Gracias por calificar el platillo!"
                    onSuccess()
                } else {
                    val err = response.errorBody()?.string()
                    estadoCalificacion = if (!err.isNullOrBlank()) {
                        "Error (${response.code()}): $err"
                    } else {
                        "No se pudo calificar el platillo: Código ${response.code()}"
                    }
                }
            } catch (e: Exception) {
                estadoCalificacion = "Fallo de red: ${e.localizedMessage}"
            }
        }
    }

    fun cancelarPedido(pedidoId: Int, onSuccess: () -> Unit) {
        viewModelScope.launch {
            estadoOrden = "Cancelando pedido..."
            try {
                pedidos = pedidos.map { pedido ->
                    if (pedido.id == pedidoId) pedido.copy(estado = "cancelado", segundos_para_pagar = null)
                    else pedido
                }
                estadoOrden = "Pedido cancelado."
                onSuccess()
            } catch (e: Exception) {
                estadoOrden = "No se pudo cancelar: ${e.localizedMessage}"
            }
        }
    }
}