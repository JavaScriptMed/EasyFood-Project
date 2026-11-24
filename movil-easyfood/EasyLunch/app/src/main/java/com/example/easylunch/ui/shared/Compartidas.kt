package com.example.easylunch.ui.shared

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material.icons.filled.Computer
import androidx.compose.material.icons.filled.Person
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.example.easylunch.data.model.UserDto
import com.example.easylunch.ui.auth.CampoTexto
import com.example.easylunch.ui.auth.SesionViewModel
import com.example.easylunch.ui.common.*

data class Pestana(val id: String, val titulo: String, val icono: ImageVector)

@Composable
fun BarraInferior(pestanas: List<Pestana>, seleccion: String, onSeleccion: (String) -> Unit) {
    Box(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = 20.dp, vertical = 12.dp), contentAlignment = Alignment.Center) {
        Row(
            Modifier.height(64.dp).clip(RoundedCornerShape(50.dp)).background(BarraOscura).padding(horizontal = 8.dp),
            horizontalArrangement = Arrangement.SpaceEvenly,
            verticalAlignment = Alignment.CenterVertically
        ) {
            pestanas.forEach { p ->
                val sel = p.id == seleccion
                Surface(
                    modifier = Modifier.height(48.dp).weight(1f),
                    shape = RoundedCornerShape(30.dp),
                    color = if (sel) Mostaza else Color.Transparent,
                    onClick = { onSeleccion(p.id) }
                ) {
                    Row(Modifier.fillMaxSize(), horizontalArrangement = Arrangement.Center, verticalAlignment = Alignment.CenterVertically) {
                        Icon(p.icono, contentDescription = p.titulo, tint = if (sel) Color.Black else Color.LightGray, modifier = Modifier.size(22.dp))
                        if (sel) {
                            Spacer(Modifier.width(6.dp))
                            Text(p.titulo, color = Color.Black, fontSize = 12.sp, maxLines = 1)
                        }
                    }
                }
            }
        }
    }
}

fun nombreRol(rol: String?): String = when (rol) {
    "customer" -> "Cliente"
    "employee" -> "Empleado"
    "driver" -> "Repartidor"
    else -> rol ?: "—"
}

@Composable
fun PerfilScreen(vm: SesionViewModel, modifier: Modifier = Modifier, extra: @Composable ColumnScope.() -> Unit = {}) {
    var confirmarSalida by remember { mutableStateOf(false) }
    Column(modifier.fillMaxSize().background(FondoApp)) {
        BarraSuperior("Mi perfil")
        CargaView(vm.perfil, onReintentar = vm::cargarPerfil) { u ->
            Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp)) {
                EditorPerfil(vm, u)
                Spacer(Modifier.height(16.dp))
                extra()
                Spacer(Modifier.height(16.dp))
                OutlinedButton(
                    onClick = { confirmarSalida = true }, enabled = !vm.enviando,
                    modifier = Modifier.fillMaxWidth().height(52.dp), shape = RoundedCornerShape(16.dp),
                    colors = ButtonDefaults.outlinedButtonColors(contentColor = RojoError)
                ) {
                    Icon(Icons.AutoMirrored.Filled.Logout, contentDescription = null)
                    Spacer(Modifier.width(8.dp))
                    Text("Cerrar sesión", fontWeight = FontWeight.Bold)
                }
                Spacer(Modifier.height(100.dp))
            }
        }
    }
    if (confirmarSalida) DialogoConfirmar("Cerrar sesión", "¿Quieres salir de tu cuenta en este teléfono?", "Cerrar sesión",
        onConfirmar = { confirmarSalida = false; vm.cerrarSesion() }, onCancelar = { confirmarSalida = false })
}

@Composable
private fun EditorPerfil(vm: SesionViewModel, u: UserDto) {
    val context = LocalContext.current
    var nombre by remember(u) { mutableStateOf(u.profile?.first_name ?: "") }
    var apellido by remember(u) { mutableStateOf(u.profile?.last_name ?: "") }
    var telefono by remember(u) { mutableStateOf(u.profile?.phone ?: "") }
    var avatar by remember(u) { mutableStateOf<Uri?>(null) }
    val selector = rememberLauncherForActivityResult(ActivityResultContracts.PickVisualMedia()) { uri -> if (uri != null) avatar = uri }
    val err = vm.error

    Tarjeta {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(
                Modifier.size(72.dp).clip(CircleShape).background(MostazaClaro),
                contentAlignment = Alignment.Center
            ) {
                val imagen: Any? = avatar ?: u.profile?.avatar_url
                if (imagen != null) AsyncImage(model = imagen, contentDescription = "Foto de perfil", contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                else Icon(Icons.Default.Person, contentDescription = null, tint = Mostaza, modifier = Modifier.size(40.dp))
            }
            Spacer(Modifier.width(16.dp))
            Column(Modifier.weight(1f)) {
                Text(u.name ?: "—", fontWeight = FontWeight.Bold, fontSize = 18.sp, color = TextoTitulo)
                Text(u.email ?: "—", color = TextoSuave, fontSize = 14.sp)
                Spacer(Modifier.height(4.dp))
                Etiqueta(nombreRol(u.role))
            }
        }
        TextButton(onClick = { selector.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) }) {
            Text(if (avatar == null) "Cambiar foto (JPG, PNG o WEBP, máx. 2 MB)" else "Foto nueva lista para guardar", color = Color(0xFF8A6100))
        }
        CampoTexto(nombre, { nombre = it }, "Nombre", error = err?.campo("first_name"))
        CampoTexto(apellido, { apellido = it }, "Apellido", error = err?.campo("last_name"))
        CampoTexto(telefono, { telefono = it }, "Teléfono", teclado = KeyboardType.Phone, error = err?.campo("phone"))
        Text("El correo no se puede cambiar desde la app.", color = TextoSuave, fontSize = 12.sp)
        Spacer(Modifier.height(8.dp))
        AvisoError(err?.mensaje?.takeIf { err.campos.isEmpty() || err.campo("avatar") != null })
        AvisoOk(vm.mensajePerfil)
        Spacer(Modifier.height(8.dp))
        BotonPrincipal("Guardar cambios", { vm.actualizarPerfil(context, nombre, apellido, telefono, avatar) },
            habilitado = nombre.isNotBlank(), cargando = vm.enviando)
    }
}

/** Roles sin app móvil (admin, support, company, provider). */
@Composable
fun PanelWebScreen(vm: SesionViewModel) {
    Column(
        Modifier.fillMaxSize().background(MostazaClaro).systemBarsPadding().padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center
    ) {
        Icon(Icons.Default.Computer, contentDescription = null, tint = Mostaza, modifier = Modifier.size(64.dp))
        Spacer(Modifier.height(16.dp))
        Text("Esta cuenta usa el panel web", fontSize = 22.sp, fontWeight = FontWeight.Bold, color = TextoTitulo, textAlign = TextAlign.Center)
        Spacer(Modifier.height(8.dp))
        Text("La app móvil es para clientes, empleados y repartidores. Entra desde el panel web de EasyLunch.",
            color = TextoSuave, textAlign = TextAlign.Center)
        Spacer(Modifier.height(24.dp))
        BotonPrincipal("Cerrar sesión", vm::cerrarSesion, cargando = vm.enviando)
    }
}
