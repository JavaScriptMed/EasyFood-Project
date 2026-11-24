package com.example.easylunch.data.model

data class LoginRequest(val email: String, val password: String)

data class RegisterRequest(
    val name: String,
    val email: String,
    val password: String,
    val first_name: String,
    val last_name: String,
    val phone: String? = null
)

data class AuthResponse(
    val message: String?,
    val token: String?,
    val user: UserDto?
)

data class UserDto(
    val id: Int,
    val name: String?,
    val email: String?,
    val role: String?,
    val status: String?,
    val profile: UserProfileDto?
)

data class UserProfileDto(
    val first_name: String?,
    val last_name: String?,
    val phone: String?,
    val avatar_url: String?
)

/** Respuestas que solo traen un texto: `/api/v1` usa `mensaje`, `/api/auth` usa `message`. */
data class MensajeResponse(val mensaje: String? = null, val message: String? = null) {
    val texto: String? get() = mensaje ?: message
}

data class SolicitarRestablecerRequest(val correo: String)
data class SolicitarRestablecerResponse(val enviado: Boolean?)
data class ValidarRestablecerResponse(val valido: Boolean, val correo_oculto: String?, val motivo: String?)
data class RestablecerRequest(val contrasena: String)
