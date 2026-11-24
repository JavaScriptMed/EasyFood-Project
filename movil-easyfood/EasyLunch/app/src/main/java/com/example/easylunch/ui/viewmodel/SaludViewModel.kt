package com.example.easylunch.ui.viewmodel

import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.example.easylunch.data.network.RetrofitClient
import kotlinx.coroutines.launch

class SaludViewModel : ViewModel() {

    var estadoRespuesta by mutableStateOf("Presiona el botón para probar la conexión")
        private set

    fun verificarSalud() {
        viewModelScope.launch {
            estadoRespuesta = "Conectando al servidor..."
            try {
                val response = RetrofitClient.apiService.checkSalud()
                if (response.isSuccessful) {
                    val cuerpo = response.body()
                    estadoRespuesta = "¡Conexión exitosa!\n" +
                            "API Estada: ${cuerpo?.estado}\n" +
                            "BD Motor: ${cuerpo?.base_de_datos?.motor}\n" +
                            "PostGIS: ${cuerpo?.base_de_datos?.postgis}\n" +
                            "Latencia: ${cuerpo?.base_de_datos?.latencia_ms}ms"
                } else {
                    estadoRespuesta = "Error en el servidor: Código ${response.code()}"
                }
            } catch (e: Exception) {
                estadoRespuesta = "Fallo de conexión: ${e.localizedMessage ?: "Revisa tu red o IP"}"
            }
        }
    }
}