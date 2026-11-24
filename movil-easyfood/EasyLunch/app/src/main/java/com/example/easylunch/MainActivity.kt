package com.example.easylunch

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.lifecycle.viewmodel.compose.viewModel
import com.example.easylunch.data.network.RetrofitClient
import com.example.easylunch.ui.auth.LoginScreen
import com.example.easylunch.ui.auth.OlvideScreen
import com.example.easylunch.ui.auth.RegistroScreen
import com.example.easylunch.ui.auth.SesionViewModel
import com.example.easylunch.ui.cliente.ClienteHome
import com.example.easylunch.ui.empleado.EmpleadoHome
import com.example.easylunch.ui.repartidor.RepartidorHome
import com.example.easylunch.ui.shared.PanelWebScreen
import com.example.easylunch.ui.theme.EasyLunchTheme

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        RetrofitClient.init(this)
        enableEdgeToEdge()
        setContent {
            EasyLunchTheme {
                val sesion: SesionViewModel = viewModel()

                // 401 en cualquier petición con sesión → volver al login.
                val vencida = RetrofitClient.sesionVencida
                LaunchedEffect(vencida) { if (vencida > 0) sesion.sesionVencida() }

                if (sesion.token == null) {
                    var pantalla by rememberSaveable { mutableStateOf("login") }
                    when (pantalla) {
                        "registro" -> RegistroScreen(sesion, onLogin = { pantalla = "login" })
                        "olvide" -> OlvideScreen(sesion, onLogin = { pantalla = "login" })
                        else -> LoginScreen(sesion, onRegistro = { pantalla = "registro" }, onOlvide = { pantalla = "olvide" })
                    }
                } else {
                    // `user.role` (en inglés) decide la pantalla de inicio.
                    when (sesion.rol) {
                        "customer" -> ClienteHome(sesion)
                        "employee" -> EmpleadoHome(sesion)
                        "driver" -> RepartidorHome(sesion)
                        null -> com.example.easylunch.ui.common.CargaView(sesion.perfil, onReintentar = sesion::cargarPerfil) { }
                        else -> PanelWebScreen(sesion)
                    }
                }
            }
        }
    }
}
