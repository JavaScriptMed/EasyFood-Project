package com.example.easylunch.ui.common

import android.content.Context
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ViewList
import androidx.compose.material.icons.filled.GridView
import androidx.compose.material.icons.filled.Restaurant
import androidx.compose.material.icons.filled.ViewAgenda
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import coil.compose.SubcomposeAsyncImage

/** Cómo se muestran los platillos al elegir. */
enum class VistaPlatillos(val titulo: String) { LISTA("Lista"), CUADRICULA("Cuadrícula"), GRANDE("Grande") }

private const val PREFS = "easylunch_prefs"
private const val CLAVE_VISTA = "vista_platillos"

/** La preferencia se guarda en el teléfono (es solo de presentación). */
@Composable
fun rememberVistaPlatillos(): MutableState<VistaPlatillos> {
    val context = LocalContext.current
    val estado = remember {
        val guardada = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(CLAVE_VISTA, null)
        mutableStateOf(VistaPlatillos.entries.firstOrNull { it.name == guardada } ?: VistaPlatillos.LISTA)
    }
    LaunchedEffect(estado.value) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString(CLAVE_VISTA, estado.value.name).apply()
    }
    return estado
}

@Composable
fun SelectorVista(vista: VistaPlatillos, onCambio: (VistaPlatillos) -> Unit, modifier: Modifier = Modifier) {
    SingleChoiceSegmentedButtonRow(modifier) {
        VistaPlatillos.entries.forEachIndexed { i, v ->
            SegmentedButton(
                selected = v == vista, onClick = { onCambio(v) },
                shape = SegmentedButtonDefaults.itemShape(i, VistaPlatillos.entries.size),
                colors = SegmentedButtonDefaults.colors(activeContainerColor = MostazaClaro, activeContentColor = Color.Black),
                icon = {
                    Icon(
                        when (v) {
                            VistaPlatillos.LISTA -> Icons.AutoMirrored.Filled.ViewList
                            VistaPlatillos.CUADRICULA -> Icons.Default.GridView
                            VistaPlatillos.GRANDE -> Icons.Default.ViewAgenda
                        }, contentDescription = null, modifier = Modifier.size(18.dp)
                    )
                },
                label = { Text(v.titulo) }
            )
        }
    }
}

/** Foto del platillo que manda el servidor; si no hay URL, un marcador neutro (no se inventa imagen). */
@Composable
fun FotoPlatillo(url: String?, modifier: Modifier = Modifier, redondeo: Int = 12) {
    val forma = RoundedCornerShape(redondeo.dp)
    val marcador: @Composable () -> Unit = {
        Box(Modifier.fillMaxSize().background(MostazaClaro), contentAlignment = Alignment.Center) {
            Icon(Icons.Default.Restaurant, contentDescription = null, tint = Mostaza.copy(alpha = 0.7f), modifier = Modifier.size(28.dp))
        }
    }
    Box(modifier.clip(forma)) {
        if (url.isNullOrBlank()) marcador()
        else SubcomposeAsyncImage(
            model = url, contentDescription = null, contentScale = ContentScale.Crop,
            modifier = Modifier.fillMaxSize(),
            loading = { Box(Modifier.fillMaxSize().background(Color(0xFFF0F0F0))) },
            error = { marcador() }
        )
    }
}
