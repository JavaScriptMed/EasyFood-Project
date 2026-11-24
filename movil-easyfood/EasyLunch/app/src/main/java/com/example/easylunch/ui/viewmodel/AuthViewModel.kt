package com.example.easylunch.ui.viewmodel

import android.content.Context
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.example.easylunch.data.model.AddressDto
import com.example.easylunch.data.model.AddressRequestDto
import com.example.easylunch.data.model.LoginRequest
import com.example.easylunch.data.model.RegisterRequest
import com.example.easylunch.data.model.UserDto
import com.example.easylunch.data.network.RetrofitClient
import kotlinx.coroutines.launch
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.toRequestBody

class AuthViewModel : ViewModel() {

    var email by mutableStateOf("")
    var password by mutableStateOf("")
    var estadoLogin by mutableStateOf("Ingresa tus credenciales")
        private set

    var regName by mutableStateOf("")
    var regEmail by mutableStateOf("")
    var regPassword by mutableStateOf("")
    var regFirstName by mutableStateOf("")
    var regLastName by mutableStateOf("")
    var regPhone by mutableStateOf("")
    var estadoRegistro by mutableStateOf("Completa tus datos para registrarte")
        private set

    // Sincronizamos el estado inicial con el token guardado en RetrofitClient
    var tokenAcceso by mutableStateOf(RetrofitClient.authToken)
        private set

    var currentUser by mutableStateOf<UserDto?>(null)
        private set

    var addresses by mutableStateOf<List<AddressDto>>(emptyList())
        private set

    var estadoPerfil by mutableStateOf("")
        private set

    var estadoDireccion by mutableStateOf("")
        private set

    fun cerrarSesion(context: Context) {
        tokenAcceso = null
        currentUser = null
        addresses = emptyList()
        RetrofitClient.saveToken(context, null)
    }

    fun iniciarSesion(context: Context) {
        viewModelScope.launch {
            estadoLogin = "Iniciando sesión..."
            try {
                val request = LoginRequest(email = email, password = password)
                val response = RetrofitClient.apiService.login(request)

                if (response.isSuccessful) {
                    val body = response.body()
                    tokenAcceso = body?.token
                    currentUser = body?.user
                    RetrofitClient.saveToken(context, body?.token)
                    estadoLogin = "¡Bienvenido, ${body?.user?.name}!\nRol: ${body?.user?.role}"
                    cargarDirecciones()
                } else {
                    estadoLogin = "Error: Credenciales incorrectas o cuenta inactiva (Código ${response.code()})"
                }
            } catch (e: Exception) {
                estadoLogin = "Fallo de red: ${e.localizedMessage}"
            }
        }
    }

    fun registrarCliente(context: Context) {
        viewModelScope.launch {
            estadoRegistro = "Registrando cuenta..."
            try {
                val request = RegisterRequest(
                    name = regName,
                    email = regEmail,
                    password = regPassword,
                    first_name = regFirstName,
                    last_name = regLastName,
                    phone = regPhone.ifEmpty { null },
                )
                val response = RetrofitClient.apiService.register(request)

                if (response.isSuccessful) {
                    val body = response.body()
                    tokenAcceso = body?.token
                    currentUser = body?.user
                    RetrofitClient.saveToken(context, body?.token)
                    estadoRegistro = "¡Registro exitoso! Bienvenido, ${body?.user?.name}"
                } else {
                    estadoRegistro = "Error en el registro: Código ${response.code()}"
                }
            } catch (e: Exception) {
                estadoRegistro = "Fallo de red: ${e.localizedMessage}"
            }
        }
    }

    fun actualizarPerfil(
        context: Context,
        name: String,
        firstName: String,
        lastName: String,
        phone: String,
        imageUri: android.net.Uri?
    ) {
        viewModelScope.launch {
            estadoPerfil = "Actualizando perfil..."
            try {
                val nameBody = name.toRequestBody("text/plain".toMediaTypeOrNull())
                val firstNameBody = firstName.toRequestBody("text/plain".toMediaTypeOrNull())
                val lastNameBody = lastName.toRequestBody("text/plain".toMediaTypeOrNull())
                val phoneBody = phone.ifEmpty { null }?.toRequestBody("text/plain".toMediaTypeOrNull())

                var avatarPart: MultipartBody.Part? = null

                if (imageUri != null) {
                    val inputStream = context.contentResolver.openInputStream(imageUri)
                    val fileBytes = inputStream?.readBytes()
                    inputStream?.close()

                    if (fileBytes != null) {
                        val requestFile = fileBytes.toRequestBody("image/*".toMediaTypeOrNull())
                        avatarPart = MultipartBody.Part.createFormData("avatar", "avatar.jpg", requestFile)
                    }
                }

                val response = RetrofitClient.apiService.updateProfile(
                    name = nameBody,
                    firstName = firstNameBody,
                    lastName = lastNameBody,
                    phone = phoneBody,
                    avatar = avatarPart
                )

                if (response.isSuccessful) {
                    currentUser = response.body()?.user
                    estadoPerfil = "¡Perfil actualizado con éxito!"
                } else {
                    estadoPerfil = "Error al actualizar perfil: Código ${response.code()}"
                }
            } catch (e: Exception) {
                estadoPerfil = "Fallo de red: ${e.localizedMessage}"
            }
        }
    }

    fun cargarDirecciones() {
        viewModelScope.launch {
            try {
                val response = RetrofitClient.apiService.getAddresses()
                if (response.isSuccessful) {
                    addresses = response.body() ?: emptyList()
                }
            } catch (_: Exception) {}
        }
    }

    fun crearDireccion(
        label: String,
        street: String,
        intNum: String,
        cp: String,
        lat: Double? = null,
        lng: Double? = null,
        instructions: String,
        isDefault: Boolean,
        onSuccess: () -> Unit
    ) {
        viewModelScope.launch {
            estadoDireccion = "Guardando dirección..."
            try {
                val request = AddressRequestDto(
                    etiqueta = label,
                    direccion = street,
                    numero_interior = intNum.ifEmpty { null },
                    codigo_postal = cp.ifEmpty { null },
                    latitud = lat,
                    longitud = lng,
                    referencias = instructions.ifEmpty { null },
                    predeterminada = isDefault
                )
                val response = RetrofitClient.apiService.createAddress(request)
                if (response.isSuccessful) {
                    estadoDireccion = ""
                    cargarDirecciones()
                    onSuccess()
                } else {
                    val err = response.errorBody()?.string()
                    estadoDireccion = if (!err.isNullOrBlank()) {
                        "Error (${response.code()}): $err"
                    } else {
                        "Error al guardar: Código ${response.code()}"
                    }
                }
            } catch (e: Exception) {
                estadoDireccion = "Fallo de red: ${e.localizedMessage}"
            }
        }
    }

    fun actualizarDireccion(
        id: Int,
        label: String,
        street: String,
        intNum: String,
        cp: String,
        lat: Double? = null,
        lng: Double? = null,
        instructions: String,
        isDefault: Boolean,
        onSuccess: () -> Unit
    ) {
        viewModelScope.launch {
            estadoDireccion = "Actualizando dirección..."
            try {
                val request = AddressRequestDto(
                    etiqueta = label,
                    direccion = street,
                    numero_interior = intNum.ifEmpty { null },
                    codigo_postal = cp.ifEmpty { null },
                    latitud = lat,
                    longitud = lng,
                    referencias = instructions.ifEmpty { null },
                    predeterminada = isDefault
                )
                val response = RetrofitClient.apiService.updateAddress(id, request)
                if (response.isSuccessful) {
                    estadoDireccion = ""
                    cargarDirecciones()
                    onSuccess()
                } else {
                    val err = response.errorBody()?.string()
                    estadoDireccion = if (!err.isNullOrBlank()) {
                        "Error (${response.code()}): $err"
                    } else {
                        "Error al actualizar: Código ${response.code()}"
                    }
                }
            } catch (e: Exception) {
                estadoDireccion = "Error: ${e.localizedMessage}"
            }
        }
    }

    fun eliminarDireccion(id: Int) {
        viewModelScope.launch {
            try {
                val response = RetrofitClient.apiService.deleteAddress(id)
                if (response.isSuccessful) {
                    cargarDirecciones()
                }
            } catch (_: Exception) {}
        }
    }

    init {
        if (!tokenAcceso.isNullOrBlank()) {
            cargarPerfilUsuario()
            cargarDirecciones()
        }
    }

    fun cargarPerfilUsuario() {
        viewModelScope.launch {
            try {
                val response = RetrofitClient.apiService.getProfile()
                if (response.isSuccessful) {
                    currentUser = response.body()?.user
                }
            } catch (_: Exception) {}
        }
    }
}