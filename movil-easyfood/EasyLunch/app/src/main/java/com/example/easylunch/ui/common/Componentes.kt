package com.example.easylunch.ui.common

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.CloudOff
import androidx.compose.material.icons.filled.ErrorOutline
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.Inbox
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.filled.StarBorder
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.easylunch.data.network.ApiError
import com.example.easylunch.data.network.ApiResult
import kotlinx.coroutines.delay
import java.time.Instant

val Mostaza = Color(0xFFFFB300)
val MostazaClaro = Color(0xFFFFF4D6)
val FondoApp = Color(0xFFFAFAFA)
val BarraOscura = Color(0xFF1E1E1E)
val TextoTitulo = Color(0xFF2B2B2B)
val TextoSuave = Color(0xFF6B6B6B)
val VerdeOk = Color(0xFF2E7D32)
val RojoError = Color(0xFFC62828)

/** Estado de una carga de datos: cargando, listo o falla (vacío lo decide cada pantalla). */
sealed interface Carga<out T> {
    data object Cargando : Carga<Nothing>
    data class Listo<T>(val datos: T) : Carga<T>
    data class Falla(val error: ApiError) : Carga<Nothing>
}

fun <T> ApiResult<T>.aCarga(): Carga<T> = when (this) {
    is ApiResult.Ok -> Carga.Listo(datos)
    is ApiResult.Err -> Carga.Falla(error)
}

@Composable
fun <T> CargaView(
    carga: Carga<T>,
    onReintentar: () -> Unit,
    modifier: Modifier = Modifier,
    esVacio: (T) -> Boolean = { false },
    vacio: @Composable () -> Unit = {},
    contenido: @Composable (T) -> Unit
) {
    when (carga) {
        is Carga.Cargando -> PantallaCargando(modifier)
        is Carga.Falla -> PantallaError(carga.error, onReintentar, modifier)
        is Carga.Listo -> if (esVacio(carga.datos)) vacio() else contenido(carga.datos)
    }
}

@Composable
fun PantallaCargando(modifier: Modifier = Modifier) {
    Box(modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
        CircularProgressIndicator(color = Mostaza)
    }
}

@Composable
fun PantallaError(error: ApiError, onReintentar: () -> Unit, modifier: Modifier = Modifier) {
    Column(
        modifier.fillMaxSize().padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center
    ) {
        Icon(
            if (error.sinConexion) Icons.Default.CloudOff else Icons.Default.ErrorOutline,
            contentDescription = null, tint = RojoError, modifier = Modifier.size(48.dp)
        )
        Spacer(Modifier.height(12.dp))
        Text(error.mensaje, textAlign = TextAlign.Center, color = TextoTitulo)
        Spacer(Modifier.height(16.dp))
        Button(onClick = onReintentar, colors = ButtonDefaults.buttonColors(containerColor = Mostaza, contentColor = Color.Black)) {
            Text("Reintentar")
        }
    }
}

@Composable
fun PantallaVacia(titulo: String, texto: String, modifier: Modifier = Modifier, icono: ImageVector = Icons.Default.Inbox, accion: (@Composable () -> Unit)? = null) {
    Column(
        modifier.fillMaxSize().padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center
    ) {
        Icon(icono, contentDescription = null, tint = TextoSuave, modifier = Modifier.size(48.dp))
        Spacer(Modifier.height(12.dp))
        Text(titulo, fontWeight = FontWeight.Bold, color = TextoTitulo, textAlign = TextAlign.Center)
        Spacer(Modifier.height(4.dp))
        Text(texto, color = TextoSuave, textAlign = TextAlign.Center)
        if (accion != null) {
            Spacer(Modifier.height(16.dp))
            accion()
        }
    }
}

@Composable
fun AvisoError(texto: String?, modifier: Modifier = Modifier) {
    if (texto.isNullOrBlank()) return
    Aviso(texto, Color(0xFFFDECEA), RojoError, Icons.Default.ErrorOutline, modifier)
}

@Composable
fun AvisoInfo(texto: String, modifier: Modifier = Modifier, color: Color = Color(0xFF8A6100)) {
    Aviso(texto, MostazaClaro, color, Icons.Default.Info, modifier)
}

@Composable
fun AvisoOk(texto: String?, modifier: Modifier = Modifier) {
    if (texto.isNullOrBlank()) return
    Aviso(texto, Color(0xFFE8F5E9), VerdeOk, Icons.Default.Info, modifier)
}

@Composable
private fun Aviso(texto: String, fondo: Color, color: Color, icono: ImageVector, modifier: Modifier) {
    Row(
        modifier.fillMaxWidth().background(fondo, RoundedCornerShape(12.dp)).padding(12.dp),
        verticalAlignment = Alignment.Top
    ) {
        Icon(icono, contentDescription = null, tint = color, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(8.dp))
        Text(texto, color = color, fontSize = 14.sp)
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun BarraSuperior(titulo: String, onBack: (() -> Unit)? = null, acciones: @Composable RowScope.() -> Unit = {}) {
    TopAppBar(
        title = { Text(titulo, fontWeight = FontWeight.Bold) },
        navigationIcon = {
            if (onBack != null) IconButton(onClick = onBack) {
                Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Regresar")
            }
        },
        actions = acciones,
        colors = TopAppBarDefaults.topAppBarColors(containerColor = Color.White)
    )
}

@Composable
fun BotonPrincipal(texto: String, onClick: () -> Unit, modifier: Modifier = Modifier, habilitado: Boolean = true, cargando: Boolean = false) {
    Button(
        onClick = onClick,
        enabled = habilitado && !cargando,
        modifier = modifier.fillMaxWidth().height(52.dp),
        shape = RoundedCornerShape(16.dp),
        colors = ButtonDefaults.buttonColors(containerColor = Mostaza, contentColor = Color.Black)
    ) {
        if (cargando) CircularProgressIndicator(Modifier.size(22.dp), color = Color.Black, strokeWidth = 2.dp)
        else Text(texto, fontWeight = FontWeight.Bold)
    }
}

@Composable
fun Tarjeta(modifier: Modifier = Modifier, onClick: (() -> Unit)? = null, contenido: @Composable ColumnScope.() -> Unit) {
    val m = modifier.fillMaxWidth()
    if (onClick != null) {
        Card(onClick = onClick, modifier = m, shape = RoundedCornerShape(16.dp),
            colors = CardDefaults.cardColors(containerColor = Color.White), elevation = CardDefaults.cardElevation(2.dp)) {
            Column(Modifier.padding(16.dp), content = contenido)
        }
    } else {
        Card(modifier = m, shape = RoundedCornerShape(16.dp),
            colors = CardDefaults.cardColors(containerColor = Color.White), elevation = CardDefaults.cardElevation(2.dp)) {
            Column(Modifier.padding(16.dp), content = contenido)
        }
    }
}

@Composable
fun Etiqueta(texto: String, fondo: Color = MostazaClaro, color: Color = Color(0xFF8A6100)) {
    Text(
        texto, color = color, fontSize = 12.sp, fontWeight = FontWeight.SemiBold,
        modifier = Modifier.background(fondo, RoundedCornerShape(8.dp)).padding(horizontal = 8.dp, vertical = 3.dp)
    )
}

@Composable
fun FilaDato(etiqueta: String, valor: String, negrita: Boolean = false) {
    Row(Modifier.fillMaxWidth().padding(vertical = 3.dp), horizontalArrangement = Arrangement.SpaceBetween) {
        Text(etiqueta, color = TextoSuave, modifier = Modifier.weight(1f))
        Spacer(Modifier.width(8.dp))
        Text(valor, color = TextoTitulo, fontWeight = if (negrita) FontWeight.Bold else FontWeight.Normal, textAlign = TextAlign.End)
    }
}

@Composable
fun Estrellas(valor: Int, onCambio: (Int) -> Unit, etiqueta: String? = null) {
    Column {
        if (etiqueta != null) Text(etiqueta, color = TextoSuave, fontSize = 13.sp)
        Row {
            (1..5).forEach { n ->
                Icon(
                    if (n <= valor) Icons.Default.Star else Icons.Default.StarBorder,
                    contentDescription = "$n estrellas",
                    tint = Mostaza,
                    modifier = Modifier.size(36.dp).clickable { onCambio(n) }.padding(2.dp)
                )
            }
        }
    }
}

/** Cuenta regresiva hacia un momento que manda el servidor. */
@Composable
fun CuentaRegresiva(objetivo: Instant?, prefijo: String, alVencer: () -> Unit = {}, color: Color = RojoError) {
    if (objetivo == null) return
    var restante by remember(objetivo) { mutableLongStateOf(objetivo.epochSecond - Instant.now().epochSecond) }
    LaunchedEffect(objetivo) {
        while (restante > 0) {
            delay(1000)
            restante = objetivo.epochSecond - Instant.now().epochSecond
        }
        alVencer()
    }
    Text(
        if (restante > 0) "$prefijo ${Formato.cuentaRegresiva(restante)}" else "$prefijo vencido",
        color = color, fontWeight = FontWeight.Bold, fontSize = 14.sp
    )
}

@Composable
fun DialogoConfirmar(titulo: String, texto: String, confirmar: String, onConfirmar: () -> Unit, onCancelar: () -> Unit) {
    AlertDialog(
        onDismissRequest = onCancelar,
        title = { Text(titulo) },
        text = { Text(texto) },
        confirmButton = { TextButton(onClick = onConfirmar) { Text(confirmar, color = RojoError) } },
        dismissButton = { TextButton(onClick = onCancelar) { Text("Cancelar") } }
    )
}
