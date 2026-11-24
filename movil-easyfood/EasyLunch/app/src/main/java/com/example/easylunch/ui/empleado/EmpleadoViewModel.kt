package com.example.easylunch.ui.empleado

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.example.easylunch.data.model.*
import com.example.easylunch.data.network.ApiResult
import com.example.easylunch.data.network.RetrofitClient
import com.example.easylunch.data.network.safeCall
import com.example.easylunch.ui.common.Carga
import com.example.easylunch.ui.common.aCarga
import kotlinx.coroutines.launch

class EmpleadoViewModel : ViewModel() {
    private val api get() = RetrofitClient.apiService

    // ---------- Mi comida ----------
    /** null = la próxima fecha con el corte abierto (la decide el servidor). */
    var fecha by mutableStateOf<String?>(null)
        private set
    var tiempo by mutableStateOf("comida")
        private set
    var menu by mutableStateOf<Carga<EmpleadoMenuDto>>(Carga.Cargando)
        private set
    var guardando by mutableStateOf(false)
        private set
    var errorAccion by mutableStateOf<String?>(null)
        private set
    var okAccion by mutableStateOf<String?>(null)
        private set

    fun cargarMenu(nuevaFecha: String? = fecha, nuevoTiempo: String = tiempo, silencioso: Boolean = false) {
        fecha = nuevaFecha; tiempo = nuevoTiempo
        viewModelScope.launch {
            if (!silencioso) { menu = Carga.Cargando; errorAccion = null; okAccion = null }
            val r = safeCall { api.getMenuEmpleado(nuevaFecha, nuevoTiempo) }
            menu = r.aCarga()
            // Fijamos la fecha que devolvió el servidor para navegar desde ahí.
            if (r is ApiResult.Ok) fecha = r.datos.fecha
        }
    }

    fun elegir(m: EmpleadoMenuDto, platilloId: Int) {
        if (guardando) return
        viewModelScope.launch {
            guardando = true; errorAccion = null; okAccion = null
            when (val r = safeCall { api.elegir(EleccionRequest(m.fecha, m.tiempo_comida, platilloId)) }) {
                is ApiResult.Ok -> { okAccion = "Listo: guardamos tu elección."; cargarMenu(m.fecha, m.tiempo_comida, silencioso = true) }
                is ApiResult.Err -> { errorAccion = r.error.mensaje; cargarMenu(m.fecha, m.tiempo_comida, silencioso = true) }
            }
            guardando = false
        }
    }

    fun quitar(m: EmpleadoMenuDto) {
        if (guardando) return
        viewModelScope.launch {
            guardando = true; errorAccion = null; okAccion = null
            when (val r = safeCall { api.quitarEleccion(QuitarEleccionRequest(m.fecha, m.tiempo_comida)) }) {
                is ApiResult.Ok -> { okAccion = r.datos.texto ?: "Listo: quitamos tu elección."; cargarMenu(m.fecha, m.tiempo_comida, silencioso = true) }
                is ApiResult.Err -> { errorAccion = r.error.mensaje; cargarMenu(m.fecha, m.tiempo_comida, silencioso = true) }
            }
            guardando = false
        }
    }

    // ---------- Historial ----------
    var historial by mutableStateOf<Carga<List<HistorialEleccionDto>>>(Carga.Cargando)
        private set

    fun cargarHistorial() {
        viewModelScope.launch { historial = Carga.Cargando; historial = safeCall { api.getHistorialElecciones(30) }.aCarga() }
    }

    // ---------- Por calificar ----------
    var pendientes by mutableStateOf<Carga<List<PendienteCalificacionDto>>>(Carga.Cargando)
        private set
    var calificando by mutableStateOf(false)
        private set
    var resultadoCalificacion by mutableStateOf<Pair<Boolean, String>?>(null)
        private set

    fun limpiarCalificacion() { resultadoCalificacion = null }

    fun cargarPendientes(silencioso: Boolean = false) {
        viewModelScope.launch {
            if (!silencioso) pendientes = Carga.Cargando
            pendientes = safeCall { api.getPendientesEmpleado() }.aCarga()
        }
    }

    fun calificarEntrega(req: CalificarEntregaRequest) = calificar { safeCall { api.calificarEntregaEmpleado(req) } }
    fun calificarPlatillo(req: CalificarPlatilloEmpleadoRequest) = calificar { safeCall { api.calificarPlatilloEmpleado(req) } }

    private fun calificar(llamada: suspend () -> ApiResult<CalificacionResponse>) {
        if (calificando) return
        viewModelScope.launch {
            calificando = true
            resultadoCalificacion = when (val r = llamada()) {
                is ApiResult.Ok -> true to (r.datos.mensaje ?: "Gracias por calificar.")
                is ApiResult.Err -> false to r.error.mensaje
            }
            calificando = false
            cargarPendientes(silencioso = true)
        }
    }
}
