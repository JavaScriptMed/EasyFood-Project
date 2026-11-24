package com.example.easylunch.ui.repartidor

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
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
import com.example.easylunch.ui.common.ZONA_GDL
import com.example.easylunch.ui.common.aCarga
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.ByteArrayOutputStream
import java.time.LocalDate

class RepartidorViewModel : ViewModel() {
    private val api get() = RetrofitClient.apiService

    var disponibilidad by mutableStateOf<Carga<DisponibilidadDto>>(Carga.Cargando)
        private set
    var cambiandoDisponibilidad by mutableStateOf(false)
        private set
    var errorDisponibilidad by mutableStateOf<String?>(null)
        private set

    fun cargarDisponibilidad() {
        viewModelScope.launch {
            if (disponibilidad !is Carga.Listo) disponibilidad = Carga.Cargando
            disponibilidad = safeCall { api.getDisponibilidad() }.aCarga()
        }
    }

    fun cambiarDisponibilidad(enLinea: Boolean) {
        if (cambiandoDisponibilidad) return
        viewModelScope.launch {
            cambiandoDisponibilidad = true; errorDisponibilidad = null
            when (val r = safeCall { api.setDisponibilidad(DisponibilidadRequest(enLinea)) }) {
                is ApiResult.Ok -> disponibilidad = Carga.Listo(r.datos)
                is ApiResult.Err -> { errorDisponibilidad = r.error.mensaje; cargarDisponibilidad() }
            }
            cambiandoDisponibilidad = false
        }
    }

    // ---------- Rutas ----------
    var fecha by mutableStateOf(LocalDate.now(ZONA_GDL))
        private set
    var rutas by mutableStateOf<Carga<List<RutaDto>>>(Carga.Cargando)
        private set

    fun cambiarFecha(nueva: LocalDate) { fecha = nueva; cargarRutas() }

    fun cargarRutas(silencioso: Boolean = false) {
        viewModelScope.launch {
            if (!silencioso) rutas = Carga.Cargando
            val r = safeCall { api.getRutas(fecha.toString()) }
            if (r is ApiResult.Ok || !silencioso) rutas = r.aCarga()
        }
    }

    // ---------- Acciones sobre paradas ----------
    var procesando by mutableStateOf<Int?>(null)
        private set
    var errorParada by mutableStateOf<String?>(null)
        private set
    var okParada by mutableStateOf<String?>(null)
        private set

    fun limpiarMensajes() { errorParada = null; okParada = null }

    /** Reemplaza la parada y los datos de la ruta con lo que devolvió el servidor. */
    private fun aplicar(resp: AccionParadaResponse) {
        val actual = (rutas as? Carga.Listo)?.datos ?: return
        rutas = Carga.Listo(actual.map { ruta ->
            if (ruta.id != resp.ruta.id) ruta
            else resp.ruta.copy(paradas = ruta.paradas?.map { if (it.id == resp.parada.id) resp.parada else it })
        })
    }

    private fun accion(paradaId: Int, exito: String, llamada: suspend () -> ApiResult<AccionParadaResponse>, alTerminar: () -> Unit = {}) {
        if (procesando != null) return
        viewModelScope.launch {
            procesando = paradaId; errorParada = null; okParada = null
            when (val r = llamada()) {
                is ApiResult.Ok -> { aplicar(r.datos); okParada = exito; alTerminar() }
                is ApiResult.Err -> errorParada = r.error.mensaje
            }
            procesando = null
            // Refrescar desde el servidor: disponibilidad (en_ruta/libre) y rutas.
            cargarDisponibilidad()
            cargarRutas(silencioso = true)
        }
    }

    fun iniciar(paradaId: Int) = accion(paradaId, "Parada iniciada: vas en camino.", { safeCall { api.iniciarParada(paradaId) } })

    fun noEntregada(paradaId: Int, motivo: String, alTerminar: () -> Unit) =
        accion(paradaId, "Parada marcada como no entregada.", { safeCall { api.noEntregada(paradaId, NoEntregadaRequest(motivo.trim())) } }, alTerminar)

    fun entregar(context: Context, paradaId: Int, foto: Uri, alTerminar: () -> Unit) {
        if (procesando != null) return
        viewModelScope.launch {
            val bytes = withContext(Dispatchers.IO) { comprimir(context, foto) }
            if (bytes == null) { errorParada = "No se pudo leer la foto. Toma otra."; return@launch }
            if (bytes.size > 5 * 1024 * 1024) { errorParada = "La foto pesa más de 5 MB. Toma otra."; return@launch }
            val parte = MultipartBody.Part.createFormData("foto", "evidencia.jpg", bytes.toRequestBody("image/jpeg".toMediaType()))
            accion(paradaId, "¡Entrega confirmada con foto!", { safeCall { api.entregarParada(paradaId, parte) } }, alTerminar)
        }
    }

    /** Reduce la foto de la cámara a JPEG ≤ 1600 px para respetar el límite de 5 MB. */
    private fun comprimir(context: Context, uri: Uri): ByteArray? = try {
        val opciones = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        context.contentResolver.openInputStream(uri)?.use { BitmapFactory.decodeStream(it, null, opciones) }
        var muestra = 1
        while (maxOf(opciones.outWidth, opciones.outHeight) / muestra > 3200) muestra *= 2
        val bmp = context.contentResolver.openInputStream(uri)?.use {
            BitmapFactory.decodeStream(it, null, BitmapFactory.Options().apply { inSampleSize = muestra })
        } ?: throw IllegalStateException()
        val escala = 1600f / maxOf(bmp.width, bmp.height)
        val final = if (escala < 1f) Bitmap.createScaledBitmap(bmp, (bmp.width * escala).toInt(), (bmp.height * escala).toInt(), true) else bmp
        ByteArrayOutputStream().use { out -> final.compress(Bitmap.CompressFormat.JPEG, 82, out); out.toByteArray() }
    } catch (_: Exception) { null }
}
