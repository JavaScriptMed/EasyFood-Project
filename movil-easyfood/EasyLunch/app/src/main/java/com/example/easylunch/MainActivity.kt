package com.example.easylunch

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Scaffold
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.lifecycle.viewmodel.compose.viewModel
import com.example.easylunch.data.network.RetrofitClient
import com.example.easylunch.ui.screens.LoginScreen
import com.example.easylunch.ui.screens.MainContainerScreen
import com.example.easylunch.ui.screens.RegisterScreen
import com.example.easylunch.ui.theme.EasyLunchTheme
import com.example.easylunch.ui.viewmodel.AuthViewModel

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // Inicializamos las preferencias de Retrofit al abrir la app para recuperar el token guardado
        RetrofitClient.init(this)

        enableEdgeToEdge()
        setContent {
            EasyLunchTheme {
                val authViewModel: AuthViewModel = viewModel()
                val context = LocalContext.current
                var currentAuthScreen by remember { mutableStateOf("login") }

                // Si hay un token guardado en memoria/dispositivo, muestra directamente la app principal
                if (authViewModel.tokenAcceso != null) {
                    MainContainerScreen(
                        authViewModel = authViewModel,
                        onLogout = {
                            authViewModel.cerrarSesion(context)
                        }
                    )
                } else {
                    Scaffold(modifier = Modifier.fillMaxSize()) { innerPadding ->
                        if (currentAuthScreen == "login") {
                            LoginScreen(
                                modifier = Modifier.padding(innerPadding),
                                viewModel = authViewModel,
                                onNavigateToRegister = { currentAuthScreen = "register" }
                            )
                        } else {
                            RegisterScreen(
                                modifier = Modifier.padding(innerPadding),
                                viewModel = authViewModel,
                                onNavigateToLogin = { currentAuthScreen = "login" }
                            )
                        }
                    }
                }
            }
        }
    }
}