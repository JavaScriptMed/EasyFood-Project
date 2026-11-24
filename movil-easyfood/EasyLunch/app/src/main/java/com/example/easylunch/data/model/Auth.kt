package com.example.easylunch.data.model

import com.google.gson.annotations.SerializedName

// Solicitud para el Login
data class LoginRequest(
    val email: String,
    val password: String
)

// Solicitud para el Registro (según los campos validados en tu AuthController)
data class RegisterRequest(
    val name: String,
    val email: String,
    val password: String,
    val first_name: String,
    val last_name: String,
    val phone: String? = null
)

// Respuesta general de autenticación (Login / Register)
data class AuthResponse(
    val message: String?,
    val token: String?,
    val user: UserDto?
)

data class UserDto(
    val id: Int,
    val name: String,
    val email: String,
    val role: String?,
    val status: String?,
    val profile: UserProfileDto?
)

data class UserProfileDto(
    val id: Int,
    val user_id: Int,
    val first_name: String?,
    val last_name: String?,
    val phone: String?,
    val avatar_url: String?,
    val bio: String?,
    val birth_date: String?
)

// DTO para enviar solo los datos a modificar
data class UpdateProfileRequest(
    val name: String? = null,
    val first_name: String? = null,
    val last_name: String? = null,
    val phone: String? = null,
    val avatar_url: String? = null,
    val bio: String? = null,
    val birth_date: String? = null
)

data class AddressDto(
    val id: Int,
    @SerializedName("user_id") val user_id: Int? = null,
    @SerializedName("etiqueta") val label: String?,
    @SerializedName("direccion") val street_address: String,
    @SerializedName("numero_interior") val internal_number: String?,
    @SerializedName("codigo_postal") val postal_code: String?,
    @SerializedName("latitud") val latitude: Double?,
    @SerializedName("longitud") val longitude: Double?,
    @SerializedName("referencias") val delivery_instructions: String?,
    @SerializedName("predeterminada") val is_default: Boolean,
    @SerializedName("zona") val zona: String? = null,
    @SerializedName("sin_cobertura") val sin_cobertura: Boolean? = false
)

data class AddressRequestDto(
    val etiqueta: String? = "Casa",
    val direccion: String,
    val numero_interior: String? = null,
    val codigo_postal: String? = null,
    val latitud: Double? = null,
    val longitud: Double? = null,
    val referencias: String? = null,
    val predeterminada: Boolean? = false
)

data class AddressResponseDto(
    val message: String? = null,
    val address: AddressDto? = null
)