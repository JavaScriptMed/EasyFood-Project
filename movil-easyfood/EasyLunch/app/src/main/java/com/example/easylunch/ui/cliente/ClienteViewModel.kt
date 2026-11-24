package com.example.easylunch.ui.cliente

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.example.easylunch.data.geo.Cobertura
import com.example.easylunch.data.geo.ZonaGeo
import com.example.easylunch.data.model.*
import com.example.easylunch.data.network.ApiError
import com.example.easylunch.data.network.ApiResult
import com.example.easylunch.data.network.RetrofitClient
import com.example.easylunch.data.network.safeCall
import com.example.easylunch.ui.common.Carga
import com.example.easylunch.ui.common.Formato
import com.example.easylunch.ui.common.ZONA_GDL
import com.example.easylunch.ui.common.aCarga
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.time.LocalDate
import java.time.temporal.ChronoUnit

/** Datos de la semana que arma la pantalla de pedido y la de menú. */
data class SemanaCliente(
    val entrega: ProximaEntregaResponse,
    val menu: Map<String, MenuSemanalDiaDto>
)

class ClienteViewModel : ViewModel() {
    private val api get() = RetrofitClient.apiService

    // ---------- Semana: próxima entrega + menú semanal ----------
    var semana by mutableStateOf<Carga<SemanaCliente>>(Carga.Cargando)
        private set
    var promedioMenu by mutableStateOf<CalificacionesMenuSemanal?>(null)
        private set

    fun cargarSemana() {
        viewModelScope.launch {
            semana = Carga.Cargando
            semana = when (val e = safeCall { api.getProximaEntrega() }) {
                is ApiResult.Err -> Carga.Falla(e.error)
                is ApiResult.Ok -> {
                    val primero = e.datos.dias_de_comida.firstOrNull()
                    if (primero == null) Carga.Listo(SemanaCliente(e.datos, emptyMap()))
                    else when (val m = safeCall { api.getMenuSemanal(primero) }) {
                        is ApiResult.Err -> Carga.Falla(m.error)
                        is ApiResult.Ok -> Carga.Listo(SemanaCliente(e.datos, m.datos.associateBy { it.fecha }))
                    }
                }
            }
            (safeCall { api.getCalificacionesMenuSemanal() } as? ApiResult.Ok)?.let { promedioMenu = it.datos }
        }
    }

    // ---------- Paquetes ----------
    var paquetes by mutableStateOf<Carga<List<PaqueteDto>>>(Carga.Cargando)
        private set

    fun cargarPaquetes() {
        viewModelScope.launch { paquetes = Carga.Cargando; paquetes = safeCall { api.getPaquetes() }.aCarga() }
    }

    // ---------- Direcciones ----------
    var direcciones by mutableStateOf<Carga<List<DireccionDto>>>(Carga.Cargando)
        private set
    var errorDirecciones by mutableStateOf<String?>(null)
        private set

    fun cargarDirecciones() {
        viewModelScope.launch {
            if (direcciones !is Carga.Listo) direcciones = Carga.Cargando
            direcciones = safeCall { api.getDirecciones() }.aCarga()
        }
    }

    fun eliminarDireccion(id: Int) {
        viewModelScope.launch {
            errorDirecciones = null
            when (val r = safeCall { api.eliminarDireccion(id) }) {
                is ApiResult.Ok -> cargarDirecciones()
                is ApiResult.Err -> errorDirecciones = r.error.mensaje
            }
        }
    }

    // ---------- Zonas (endpoint pendiente en el backend) ----------
    var zonas by mutableStateOf<List<ZonaGeo>?>(null)
        private set
    var zonasError by mutableStateOf<String?>(null)
        private set

    /** Falla con elegancia: si el endpoint no existe, la cobertura se valida al guardar. */
    fun cargarZonas() {
        if (zonas != null) return
        viewModelScope.launch {
            when (val r = safeCall { api.getZonas() }) {
                is ApiResult.Ok -> { zonas = Cobertura.leerZonas(r.datos); zonasError = null }
                is ApiResult.Err -> zonasError = if (r.error.http == 404)
                    "Las zonas de cobertura todavía no se pueden mostrar en el mapa. La cobertura se confirma al guardar."
                else "No se pudieron cargar las zonas (${r.error.mensaje}). La cobertura se confirma al guardar."
            }
        }
    }

    var guardandoDireccion by mutableStateOf(false)
        private set
    var errorGuardarDireccion by mutableStateOf<ApiError?>(null)
        private set

    fun limpiarErrorDireccion() { errorGuardarDireccion = null }

    fun guardarDireccion(id: Int?, req: DireccionRequest, alGuardar: (DireccionDto) -> Unit) {
        if (guardandoDireccion) return
        viewModelScope.launch {
            guardandoDireccion = true; errorGuardarDireccion = null
            val r = if (id == null) safeCall { api.crearDireccion(req) } else safeCall { api.actualizarDireccion(id, req) }
            when (r) {
                is ApiResult.Ok -> { cargarDirecciones(); alGuardar(r.datos) }
                is ApiResult.Err -> errorGuardarDireccion = r.error
            }
            guardandoDireccion = false
        }
    }

    // ---------- Pedidos ----------
    var pedidos by mutableStateOf<Carga<List<PedidoDto>>>(Carga.Cargando)
        private set

    fun cargarPedidos() {
        viewModelScope.launch {
            if (pedidos !is Carga.Listo) pedidos = Carga.Cargando
            pedidos = safeCall { api.getPedidos() }.aCarga()
        }
    }

    var detalle by mutableStateOf<Carga<PedidoDto>>(Carga.Cargando)
        private set
    /** Momento en que se pidió el detalle: sirve para la cuenta regresiva con `segundos_para_pagar`. */
    var detalleLeidoEn by mutableStateOf(System.currentTimeMillis())
        private set

    fun cargarDetalle(id: Int, silencioso: Boolean = false) {
        viewModelScope.launch {
            if (!silencioso) detalle = Carga.Cargando
            val r = safeCall { api.getPedido(id) }
            if (r is ApiResult.Ok || !silencioso) {
                detalle = r.aCarga()
                detalleLeidoEn = System.currentTimeMillis()
            }
        }
    }

    // ---------- Crear pedido ----------
    var creandoPedido by mutableStateOf(false)
        private set
    var errorPedido by mutableStateOf<ApiError?>(null)
        private set
    /** Platillos que el servidor rechazó (fecha → ids) para que la persona cambie. */
    var rechazados by mutableStateOf<Map<String, Set<Int>>>(emptyMap())
        private set

    fun limpiarErrorPedido() { errorPedido = null }

    fun reiniciarPedido() { errorPedido = null; rechazados = emptyMap() }

    fun crearPedido(req: CrearPedidoRequest, nombresPorFecha: Map<String, Map<Int, String>>, alCrear: (PedidoDto) -> Unit) {
        if (creandoPedido) return
        viewModelScope.launch {
            creandoPedido = true; errorPedido = null
            when (val r = safeCall { api.crearPedido(req) }) {
                is ApiResult.Ok -> { cargarPedidos(); alCrear(r.datos) }
                is ApiResult.Err -> {
                    errorPedido = r.error
                    // El mensaje de sin_porciones / platillo_no_disponible dice qué platillo y qué fecha.
                    if (r.error.codigo == "sin_porciones" || r.error.codigo == "platillo_no_disponible") {
                        val msg = r.error.mensaje
                        val nuevos = rechazados.toMutableMap()
                        nombresPorFecha.forEach { (fecha, platillos) ->
                            if (msg.contains(fecha)) {
                                val ids = platillos.filter { (_, nombre) -> msg.contains(nombre) }.keys
                                    .ifEmpty { if (r.error.codigo == "platillo_no_disponible") platillos.keys else emptySet() }
                                if (ids.isNotEmpty()) nuevos[fecha] = (nuevos[fecha] ?: emptySet()) + ids
                            }
                        }
                        rechazados = nuevos
                    }
                }
            }
            creandoPedido = false
        }
    }

    // ---------- Pago ----------
    var pago by mutableStateOf<EstadoPago>(EstadoPago.Nada)
        private set
    private var sondeo: Job? = null

    fun reiniciarPago() { sondeo?.cancel(); pago = EstadoPago.Nada }

    fun iniciarPago(pedidoId: Int, alListo: (String) -> Unit) {
        if (pago is EstadoPago.Preparando) return
        viewModelScope.launch {
            pago = EstadoPago.Preparando
            when (val r = safeCall { api.iniciarPago(pedidoId) }) {
                is ApiResult.Ok -> {
                    val secreto = r.datos.client_secret
                    if (secreto.isNullOrBlank()) pago = EstadoPago.Error("Intenta de nuevo en un momento.")
                    else { pago = EstadoPago.Nada; alListo(secreto) }
                }
                is ApiResult.Err -> {
                    val e = r.error
                    pago = EstadoPago.Error(
                        if (e.codigo == "pagos_no_configurados" || (e.http ?: 0) >= 500) "Intenta de nuevo en un momento." else e.mensaje
                    )
                    if (e.codigo == "pedido_vencido" || e.codigo == "pedido_no_pagable") cargarDetalle(pedidoId, silencioso = true)
                }
            }
        }
    }

    fun pagoCancelado() { pago = EstadoPago.Nada }
    fun pagoFallido(msg: String) { pago = EstadoPago.Error(msg) }

    /** Tras PaymentSheet: la API no se confirma desde la app; se consulta hasta que el webhook marque "recibido". */
    fun esperarConfirmacion(pedidoId: Int) {
        sondeo?.cancel()
        sondeo = viewModelScope.launch {
            pago = EstadoPago.Confirmando
            repeat(30) {
                val r = safeCall { api.getPedido(pedidoId) }
                if (r is ApiResult.Ok) {
                    detalle = Carga.Listo(r.datos); detalleLeidoEn = System.currentTimeMillis()
                    if (r.datos.estado == "recibido" || r.datos.estado == "activo" || r.datos.estado == "completado") {
                        pago = EstadoPago.Confirmado
                        cargarPedidos()
                        return@launch
                    }
                }
                delay(3000)
            }
            pago = EstadoPago.Error("Tu pago se está confirmando. Actualiza el pedido en un momento.")
        }
    }

    // ---------- Calificaciones ----------
    var calificando by mutableStateOf(false)
        private set
    var resultadoCalificacion by mutableStateOf<Pair<Boolean, String>?>(null)
        private set

    fun limpiarCalificacion() { resultadoCalificacion = null }

    fun calificarEntrega(pedidoId: Int, req: CalificarEntregaRequest) = calificar {
        safeCall { api.calificarEntregaCliente(pedidoId, req) }
    }

    fun calificarPlatillo(pedidoId: Int, req: CalificarPlatilloClienteRequest) = calificar {
        safeCall { api.calificarPlatilloCliente(pedidoId, req) }
    }

    private fun calificar(llamada: suspend () -> ApiResult<CalificacionResponse>) {
        if (calificando) return
        viewModelScope.launch {
            calificando = true
            resultadoCalificacion = when (val r = llamada()) {
                is ApiResult.Ok -> true to (r.datos.mensaje ?: "Gracias por calificar.")
                is ApiResult.Err -> false to r.error.mensaje
            }
            calificando = false
        }
    }

    companion object {
        /** Solo si la entrega ya está "entregada" y fue hace 14 días o menos (el servidor decide al final). */
        fun sePuedeCalificar(p: PedidoDto): Boolean {
            val entregado = p.dias?.any { it.estado == "entregada" } == true
            val fecha = Formato.fecha(p.fecha_entrega) ?: return false
            val dias = ChronoUnit.DAYS.between(fecha, LocalDate.now(ZONA_GDL))
            return entregado && dias in 0..14
        }
    }
}

sealed interface EstadoPago {
    data object Nada : EstadoPago
    data object Preparando : EstadoPago
    data object Confirmando : EstadoPago
    data object Confirmado : EstadoPago
    data class Error(val mensaje: String) : EstadoPago
}
