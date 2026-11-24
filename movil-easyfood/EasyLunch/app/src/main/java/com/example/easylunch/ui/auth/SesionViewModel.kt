package com.example.easylunch.ui.auth

import android.content.Context
import android.net.Uri
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.example.easylunch.data.model.*
import com.example.easylunch.data.network.ApiError
import com.example.easylunch.data.network.ApiResult
import com.example.easylunch.data.network.RetrofitClient
import com.example.easylunch.data.network.safeCall
import com.example.easylunch.ui.common.Carga
import com.example.easylunch.ui.common.aCarga
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody

class SesionViewModel : ViewModel() {
    private val api get() = RetrofitClient.apiService

    var token by mutableStateOf(RetrofitClient.authToken)
        private set
    var rol by mutableStateOf(RetrofitClient.role)
        private set
    var usuario by mutableStateOf<UserDto?>(null)
        private set
    var perfil by mutableStateOf<Carga<UserDto>>(Carga.Cargando)
        private set
    var configuracion by mutableStateOf<ConfiguracionOperacion?>(null)
        private set

    var enviando by mutableStateOf(false)
        private set
    var error by mutableStateOf<ApiError?>(null)
        private set
    var avisoLogin by mutableStateOf<String?>(null)
        private set
    var mensajePerfil by mutableStateOf<String?>(null)
        private set

    init {
        if (token != null) alIniciar()
    }

    fun limpiarError() { error = null }

    private fun alIniciar() {
        cargarPerfil()
        cargarConfiguracion()
    }

    private fun entrar(resp: AuthResponse) {
        val t = resp.token
        if (t.isNullOrBlank()) {
            error = ApiError(null, null, "El servidor no devolvió la sesión. Intenta de nuevo.")
            return
        }
        RetrofitClient.guardarSesion(t, resp.user?.role)
        usuario = resp.user
        perfil = resp.user?.let { Carga.Listo(it) } ?: Carga.Cargando
        rol = resp.user?.role
        token = t
        avisoLogin = null
        cargarConfiguracion()
        if (resp.user == null) cargarPerfil()
    }

    fun iniciarSesion(email: String, password: String) {
        if (enviando) return
        viewModelScope.launch {
            enviando = true; error = null
            when (val r = safeCall { api.login(LoginRequest(email.trim(), password)) }) {
                is ApiResult.Ok -> entrar(r.datos)
                is ApiResult.Err -> error = r.error
            }
            enviando = false
        }
    }

    fun registrar(nombre: String, apellido: String, email: String, telefono: String, password: String) {
        if (enviando) return
        viewModelScope.launch {
            enviando = true; error = null
            val req = RegisterRequest(
                name = "${nombre.trim()} ${apellido.trim()}".trim(),
                email = email.trim(), password = password,
                first_name = nombre.trim(), last_name = apellido.trim(),
                phone = telefono.trim().ifEmpty { null }
            )
            when (val r = safeCall { api.register(req) }) {
                is ApiResult.Ok -> entrar(r.datos)
                is ApiResult.Err -> error = r.error
            }
            enviando = false
        }
    }

    fun cargarPerfil() {
        viewModelScope.launch {
            perfil = Carga.Cargando
            val r = safeCall { api.getProfile() }
            when (r) {
                is ApiResult.Ok -> {
                    val u = r.datos.user
                    if (u != null) {
                        usuario = u
                        perfil = Carga.Listo(u)
                        if (u.role != rol) {
                            rol = u.role
                            RetrofitClient.guardarSesion(token, u.role)
                        }
                    } else perfil = Carga.Falla(ApiError(200, null, "El servidor no devolvió tu perfil."))
                }
                is ApiResult.Err -> perfil = Carga.Falla(r.error)
            }
        }
    }

    fun cargarConfiguracion() {
        viewModelScope.launch {
            (safeCall { api.getConfiguracion() } as? ApiResult.Ok)?.let { configuracion = it.datos }
        }
    }

    fun actualizarPerfil(context: Context, nombre: String, apellido: String, telefono: String, avatar: Uri?) {
        if (enviando) return
        viewModelScope.launch {
            enviando = true; error = null; mensajePerfil = null
            val texto = "text/plain".toMediaType()
            var parteAvatar: MultipartBody.Part? = null
            if (avatar != null) {
                val tipo = context.contentResolver.getType(avatar) ?: "image/jpeg"
                val bytes = context.contentResolver.openInputStream(avatar)?.use { it.readBytes() }
                if (bytes == null) {
                    error = ApiError(null, null, "No se pudo leer la imagen."); enviando = false; return@launch
                }
                if (bytes.size > 2 * 1024 * 1024) {
                    error = ApiError(null, null, "La foto pesa más de 2 MB. Elige una más ligera."); enviando = false; return@launch
                }
                if (tipo !in listOf("image/jpeg", "image/jpg", "image/png", "image/webp")) {
                    error = ApiError(null, null, "La foto debe ser JPG, PNG o WEBP."); enviando = false; return@launch
                }
                val ext = when (tipo) { "image/png" -> "png"; "image/webp" -> "webp"; else -> "jpg" }
                parteAvatar = MultipartBody.Part.createFormData("avatar", "avatar.$ext", bytes.toRequestBody(tipo.toMediaType()))
            }
            val r = safeCall {
                api.updateProfile(
                    method = "PUT".toRequestBody(texto),
                    name = "${nombre.trim()} ${apellido.trim()}".trim().toRequestBody(texto),
                    firstName = nombre.trim().toRequestBody(texto),
                    lastName = apellido.trim().toRequestBody(texto),
                    phone = telefono.trim().toRequestBody(texto),
                    avatar = parteAvatar
                )
            }
            when (r) {
                is ApiResult.Ok -> {
                    mensajePerfil = r.datos.message ?: "Perfil actualizado."
                    cargarPerfil() // refrescar desde el servidor
                }
                is ApiResult.Err -> error = r.error
            }
            enviando = false
        }
    }

    /** Cierra la sesión en el servidor; el token local se borra aunque falle la red. */
    fun cerrarSesion() {
        viewModelScope.launch {
            enviando = true
            safeCall { api.logout() }
            limpiarLocal(null)
            enviando = false
        }
    }

    /** El servidor respondió 401: la sesión ya no sirve. */
    fun sesionVencida() {
        if (token == null) return
        limpiarLocal("Tu sesión terminó. Inicia sesión de nuevo.")
    }

    private fun limpiarLocal(aviso: String?) {
        RetrofitClient.guardarSesion(null, null)
        token = null; rol = null; usuario = null; perfil = Carga.Cargando
        configuracion = null; error = null; mensajePerfil = null
        avisoLogin = aviso
    }

    // ---------- Olvidé mi contraseña ----------
    var olvideMensaje by mutableStateOf<String?>(null)
        private set
    var olvideCorreoOculto by mutableStateOf<String?>(null)
        private set
    var olvideTokenValido by mutableStateOf<String?>(null)
        private set
    var olvideListo by mutableStateOf(false)
        private set

    fun reiniciarOlvide() {
        olvideMensaje = null; olvideCorreoOculto = null; olvideTokenValido = null; olvideListo = false; error = null
    }

    fun solicitarRestablecer(correo: String) {
        if (enviando) return
        viewModelScope.launch {
            enviando = true; error = null; olvideMensaje = null
            when (val r = safeCall { api.solicitarRestablecer(SolicitarRestablecerRequest(correo.trim())) }) {
                // La respuesta es la misma exista o no el correo: no revelamos nada.
                is ApiResult.Ok -> olvideMensaje = "Si el correo está registrado, recibirás un enlace para restablecer tu contraseña. El enlace vence en 60 minutos."
                is ApiResult.Err -> error = r.error
            }
            enviando = false
        }
    }

    /** Acepta el enlace completo o solo el token. */
    fun validarEnlace(enlace: String) {
        val tokenEnlace = enlace.trim().trimEnd('/').substringAfterLast('/').substringBefore('?')
        if (tokenEnlace.isBlank() || enviando) return
        viewModelScope.launch {
            enviando = true; error = null
            when (val r = safeCall { api.validarRestablecer(tokenEnlace) }) {
                is ApiResult.Ok -> if (r.datos.valido) {
                    olvideTokenValido = tokenEnlace
                    olvideCorreoOculto = r.datos.correo_oculto
                } else {
                    error = ApiError(200, "enlace_invalido", when (r.datos.motivo) {
                        "usado" -> "Ese enlace ya se usó. Pide uno nuevo."
                        "vencido" -> "Ese enlace venció. Pide uno nuevo."
                        else -> "Ese enlace no es válido. Revisa que lo copiaste completo."
                    })
                }
                is ApiResult.Err -> error = r.error
            }
            enviando = false
        }
    }

    fun restablecer(contrasena: String) {
        val t = olvideTokenValido ?: return
        if (enviando) return
        viewModelScope.launch {
            enviando = true; error = null
            when (val r = safeCall { api.restablecer(t, RestablecerRequest(contrasena)) }) {
                is ApiResult.Ok -> { olvideListo = true; olvideMensaje = r.datos.texto ?: "Listo: ya puedes entrar con tu contraseña nueva." }
                is ApiResult.Err -> error = r.error
            }
            enviando = false
        }
    }
}
