package com.example.easylunch.ui.auth

import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.easylunch.R
import com.example.easylunch.ui.common.*

@Composable
private fun MarcoAuth(titulo: String, subtitulo: String, contenido: @Composable ColumnScope.() -> Unit) {
    Box(
        Modifier.fillMaxSize().background(MostazaClaro).systemBarsPadding().imePadding(),
        contentAlignment = Alignment.Center
    ) {
        Card(
            modifier = Modifier.fillMaxWidth().padding(20.dp),
            shape = RoundedCornerShape(32.dp),
            colors = CardDefaults.cardColors(containerColor = Color.White),
            elevation = CardDefaults.cardElevation(8.dp)
        ) {
            Column(
                Modifier.verticalScroll(rememberScrollState()).padding(24.dp),
                horizontalAlignment = Alignment.CenterHorizontally
            ) {
                Box(
                    Modifier.size(84.dp).clip(RoundedCornerShape(24.dp)).background(MostazaClaro),
                    contentAlignment = Alignment.Center
                ) {
                    Image(painterResource(R.drawable.logo_easylunch), contentDescription = "EasyLunch", modifier = Modifier.size(64.dp))
                }
                Spacer(Modifier.height(16.dp))
                Text(titulo, fontSize = 24.sp, fontWeight = FontWeight.Bold, color = TextoTitulo, textAlign = TextAlign.Center)
                Text(subtitulo, color = TextoSuave, textAlign = TextAlign.Center)
                Spacer(Modifier.height(20.dp))
                contenido()
            }
        }
    }
}

@Composable
fun CampoTexto(
    valor: String, onCambio: (String) -> Unit, etiqueta: String,
    modifier: Modifier = Modifier, error: String? = null, contrasena: Boolean = false,
    teclado: KeyboardType = KeyboardType.Text, lineas: Int = 1
) {
    OutlinedTextField(
        value = valor, onValueChange = onCambio, label = { Text(etiqueta) },
        modifier = modifier.fillMaxWidth().padding(vertical = 4.dp),
        singleLine = lineas == 1, minLines = lineas,
        isError = error != null,
        supportingText = error?.let { { Text(it) } },
        visualTransformation = if (contrasena) PasswordVisualTransformation() else androidx.compose.ui.text.input.VisualTransformation.None,
        keyboardOptions = KeyboardOptions(keyboardType = if (contrasena) KeyboardType.Password else teclado),
        shape = RoundedCornerShape(16.dp)
    )
}

@Composable
fun LoginScreen(vm: SesionViewModel, onRegistro: () -> Unit, onOlvide: () -> Unit) {
    var email by rememberSaveable { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    val err = vm.error
    MarcoAuth("¡Bienvenido a EasyLunch!", "Tu comida favorita directo a tu puerta") {
        vm.avisoLogin?.let { AvisoInfo(it); Spacer(Modifier.height(8.dp)) }
        CampoTexto(email, { email = it; vm.limpiarError() }, "Correo electrónico", teclado = KeyboardType.Email, error = err?.campo("email")?.takeIf { err.campos.size > 1 })
        CampoTexto(password, { password = it; vm.limpiarError() }, "Contraseña", contrasena = true, error = err?.campo("password"))
        Spacer(Modifier.height(4.dp))
        AvisoError(err?.mensaje)
        Spacer(Modifier.height(12.dp))
        BotonPrincipal("Iniciar sesión", { vm.iniciarSesion(email, password) },
            habilitado = email.isNotBlank() && password.isNotBlank(), cargando = vm.enviando)
        TextButton(onClick = { vm.limpiarError(); onOlvide() }) { Text("Olvidé mi contraseña", color = TextoSuave) }
        TextButton(onClick = { vm.limpiarError(); onRegistro() }) {
            Text("¿No tienes cuenta? Regístrate aquí", color = TextoTitulo, fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
fun RegistroScreen(vm: SesionViewModel, onLogin: () -> Unit) {
    var nombre by rememberSaveable { mutableStateOf("") }
    var apellido by rememberSaveable { mutableStateOf("") }
    var email by rememberSaveable { mutableStateOf("") }
    var telefono by rememberSaveable { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    val err = vm.error
    val passCorta = password.isNotEmpty() && password.length < 8
    MarcoAuth("Crea tu cuenta", "Regístrate como cliente para pedir tu comida de la semana") {
        CampoTexto(nombre, { nombre = it }, "Nombre", error = err?.campo("first_name"))
        CampoTexto(apellido, { apellido = it }, "Apellido", error = err?.campo("last_name"))
        CampoTexto(email, { email = it }, "Correo electrónico", teclado = KeyboardType.Email, error = err?.campo("email"))
        CampoTexto(telefono, { telefono = it }, "Teléfono (opcional)", teclado = KeyboardType.Phone, error = err?.campo("phone"))
        CampoTexto(password, { password = it }, "Contraseña (mínimo 8 caracteres)", contrasena = true,
            error = if (passCorta) "Usa al menos 8 caracteres." else err?.campo("password"))
        AvisoError(err?.mensaje?.takeIf { err.campos.isEmpty() })
        Spacer(Modifier.height(12.dp))
        BotonPrincipal("Registrarme", { vm.registrar(nombre, apellido, email, telefono, password) },
            habilitado = nombre.isNotBlank() && apellido.isNotBlank() && email.isNotBlank() && password.length >= 8,
            cargando = vm.enviando)
        TextButton(onClick = { vm.limpiarError(); onLogin() }) { Text("Ya tengo cuenta: iniciar sesión", color = TextoTitulo) }
    }
}

@Composable
fun OlvideScreen(vm: SesionViewModel, onLogin: () -> Unit) {
    var correo by rememberSaveable { mutableStateOf("") }
    var enlace by rememberSaveable { mutableStateOf("") }
    var nueva by remember { mutableStateOf("") }
    val err = vm.error
    LaunchedEffect(Unit) { vm.reiniciarOlvide() }
    val nuevaValida = nueva.length in 8..128 && nueva.any { it.isLetter() } && nueva.any { it.isDigit() }

    MarcoAuth("Restablecer contraseña", "Te enviaremos un enlace a tu correo") {
        when {
            vm.olvideListo -> {
                AvisoOk(vm.olvideMensaje)
                Spacer(Modifier.height(12.dp))
                BotonPrincipal("Ir a iniciar sesión", { vm.reiniciarOlvide(); onLogin() })
            }
            vm.olvideTokenValido != null -> {
                Text("Cuenta: ${vm.olvideCorreoOculto ?: "—"}", color = TextoSuave)
                CampoTexto(nueva, { nueva = it }, "Contraseña nueva", contrasena = true,
                    error = if (nueva.isNotEmpty() && !nuevaValida) "De 8 a 128 caracteres, con letras y números." else err?.campo("contrasena"))
                AvisoError(err?.mensaje?.takeIf { err.campos.isEmpty() })
                Spacer(Modifier.height(12.dp))
                BotonPrincipal("Guardar contraseña", { vm.restablecer(nueva) }, habilitado = nuevaValida, cargando = vm.enviando)
            }
            else -> {
                // Bloqueado por backend: a clientes y repartidores todavía no se les envía el correo.
                AvisoInfo("¿Eres cliente o repartidor? Por ahora pide ayuda a administración para restablecer tu contraseña: el correo todavía no se envía a esas cuentas.")
                Spacer(Modifier.height(12.dp))
                Text("Empleados: 1. Escribe tu correo", fontWeight = FontWeight.SemiBold, modifier = Modifier.fillMaxWidth())
                CampoTexto(correo, { correo = it }, "Correo electrónico", teclado = KeyboardType.Email, error = err?.campo("correo"))
                if (vm.olvideMensaje != null) AvisoOk(vm.olvideMensaje)
                Spacer(Modifier.height(8.dp))
                BotonPrincipal("Enviar enlace", { vm.solicitarRestablecer(correo) }, habilitado = correo.contains("@"), cargando = vm.enviando)
                Spacer(Modifier.height(20.dp))
                Text("2. Pega aquí el enlace que te llegó", fontWeight = FontWeight.SemiBold, modifier = Modifier.fillMaxWidth())
                CampoTexto(enlace, { enlace = it }, "Enlace del correo")
                AvisoError(err?.mensaje?.takeIf { err.campos.isEmpty() })
                Spacer(Modifier.height(8.dp))
                OutlinedButton(onClick = { vm.validarEnlace(enlace) }, enabled = enlace.isNotBlank() && !vm.enviando,
                    modifier = Modifier.fillMaxWidth()) { Text("Continuar") }
            }
        }
        TextButton(onClick = { vm.reiniciarOlvide(); onLogin() }) { Text("Volver a iniciar sesión", color = TextoTitulo) }
    }
}
