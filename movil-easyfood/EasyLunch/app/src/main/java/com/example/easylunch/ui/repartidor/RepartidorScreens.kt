package com.example.easylunch.ui.repartidor

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import androidx.lifecycle.viewmodel.compose.viewModel
import coil.compose.AsyncImage
import com.example.easylunch.data.geo.MapboxGeocoder
import com.example.easylunch.data.model.DisponibilidadDto
import com.example.easylunch.data.model.ParadaDto
import com.example.easylunch.data.model.RutaDto
import com.example.easylunch.data.model.UbicacionDto
import com.example.easylunch.data.model.UserDto
import com.example.easylunch.ui.auth.CampoTexto
import com.example.easylunch.ui.auth.SesionViewModel
import com.example.easylunch.ui.common.*
import com.example.easylunch.ui.shared.BarraInferior
import com.example.easylunch.ui.shared.PerfilScreen
import com.example.easylunch.ui.shared.Pestana
import java.io.File
import java.time.LocalDate
import kotlin.math.roundToInt

private val AzulRuta = Color(0xFF3B82F6)
private val VerdeEntrega = Color(0xFF22A447)
private val Encabezado = Color(0xFF1C2033)
private val GrisSuave = Color(0xFFF1F3F6)

private val PESTANAS = listOf(
    Pestana("rutas", "Mis rutas", Icons.Default.LocalShipping),
    Pestana("perfil", "Perfil", Icons.Default.Person)
)

@Composable
fun RepartidorHome(sesion: SesionViewModel) {
    val vm: RepartidorViewModel = viewModel()
    var pestana by rememberSaveable { mutableStateOf("rutas") }
    var rutaAbierta by rememberSaveable { mutableStateOf<Int?>(null) }
    LaunchedEffect(Unit) { vm.cargarDisponibilidad(); vm.cargarRutas() }
    BackHandler(enabled = rutaAbierta != null) { rutaAbierta = null; vm.limpiarMensajes() }

    val ruta = rutaAbierta?.let { id -> (vm.rutas as? Carga.Listo)?.datos?.firstOrNull { it.id == id } }
    if (rutaAbierta != null && ruta != null) {
        DetalleRutaScreen(vm, ruta, sesion.usuario, onBack = { rutaAbierta = null; vm.limpiarMensajes() })
        return
    }
    Scaffold(containerColor = FondoApp, bottomBar = { BarraInferior(PESTANAS, pestana) { pestana = it } }) { padding ->
        Box(Modifier.padding(bottom = padding.calculateBottomPadding())) {
            if (pestana == "rutas") RutasScreen(vm, onAbrir = { rutaAbierta = it }) else PerfilScreen(sesion)
        }
    }
}

private fun textoEstadoRepartidor(d: DisponibilidadDto): String = when {
    !d.en_linea -> "Fuera de línea"
    d.estado == "en_ruta" -> "En ruta"
    d.estado == "asignado" -> "En línea · asignado"
    else -> "En línea"
}

@Composable
private fun InterruptorLinea(vm: RepartidorViewModel) {
    when (val d = vm.disponibilidad) {
        is Carga.Cargando -> LinearProgressIndicator(Modifier.fillMaxWidth(), color = Mostaza)
        is Carga.Falla -> AvisoError(d.error.mensaje)
        is Carga.Listo -> {
            val bloqueado = d.datos.en_linea && d.datos.ruta_en_curso_id != null
            Row(
                Modifier.fillMaxWidth().background(if (d.datos.en_linea) Color(0xFFE8F5E9) else Color(0xFFF1F1F1), RoundedCornerShape(14.dp))
                    .padding(horizontal = 14.dp, vertical = 10.dp),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Box(Modifier.size(10.dp).clip(CircleShape).background(if (d.datos.en_linea) VerdeEntrega else TextoSuave))
                Spacer(Modifier.width(10.dp))
                Column(Modifier.weight(1f)) {
                    Text(textoEstadoRepartidor(d.datos), fontWeight = FontWeight.Bold, color = TextoTitulo)
                    if (bloqueado) Text("Termina tu ruta en curso para salir de línea.", color = TextoSuave, fontSize = 12.sp)
                    else if (!d.datos.en_linea) Text("Ponte en línea para iniciar paradas.", color = TextoSuave, fontSize = 12.sp)
                }
                Switch(checked = d.datos.en_linea, onCheckedChange = { vm.cambiarDisponibilidad(it) },
                    enabled = !vm.cambiandoDisponibilidad && !bloqueado,
                    colors = SwitchDefaults.colors(checkedTrackColor = VerdeEntrega))
            }
            AvisoError(vm.errorDisponibilidad)
        }
    }
}

@Composable
fun RutasScreen(vm: RepartidorViewModel, onAbrir: (Int) -> Unit) {
    Column(Modifier.fillMaxSize().background(FondoApp)) {
        BarraSuperior("Mis rutas", acciones = {
            IconButton(onClick = { vm.cargarDisponibilidad(); vm.cargarRutas() }) { Icon(Icons.Default.Refresh, "Actualizar") }
        })
        Column(Modifier.padding(horizontal = 16.dp)) {
            InterruptorLinea(vm)
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconButton(onClick = { vm.cambiarFecha(vm.fecha.minusDays(1)) }) { Icon(Icons.AutoMirrored.Filled.KeyboardArrowLeft, "Día anterior") }
                val hoy = vm.fecha == LocalDate.now(ZONA_GDL)
                Text((if (hoy) "Hoy, " else "") + Formato.diaLargo(vm.fecha), fontWeight = FontWeight.Bold,
                    modifier = Modifier.weight(1f), textAlign = TextAlign.Center, color = TextoTitulo)
                IconButton(onClick = { vm.cambiarFecha(vm.fecha.plusDays(1)) }) { Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, "Día siguiente") }
            }
        }
        CargaView(vm.rutas, onReintentar = { vm.cargarRutas() }, esVacio = { it.isEmpty() },
            vacio = { PantallaVacia("Sin rutas este día", "Aquí aparecen tus rutas aprobadas por el administrador. Se asignan solas.", icono = Icons.Default.LocalShipping) }) { lista ->
            LazyColumn(contentPadding = PaddingValues(16.dp, 8.dp, 16.dp, 100.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                items(lista, key = { it.id }) { r -> TarjetaRuta(r) { onAbrir(r.id) } }
            }
        }
    }
}

@Composable
private fun TarjetaRuta(r: RutaDto, onClick: () -> Unit) {
    Tarjeta(onClick = onClick) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Default.Route, null, tint = AzulRuta)
            Spacer(Modifier.width(8.dp))
            Text("Ruta ${r.zona ?: ""}".trim(), fontWeight = FontWeight.Bold, color = TextoTitulo, modifier = Modifier.weight(1f))
            Etiqueta(Formato.estadoRuta(r.estado), Color(0xFFE3F2FD), AzulRuta)
        }
        Text(listOfNotNull(
            when (r.tipo) { "empresas" -> "Empresas"; "clientes" -> "Clientes"; "mixta" -> "Mixta"; else -> r.tipo },
            if (r.ventana_inicio != null) "${r.ventana_inicio}–${r.ventana_fin ?: "—"}" else null
        ).joinToString(" · "), color = TextoSuave, fontSize = 14.sp)
        val total = r.paradas_totales ?: 0
        val cerradas = r.paradas_cerradas ?: 0
        Spacer(Modifier.height(6.dp))
        LinearProgressIndicator(progress = { if (total > 0) cerradas / total.toFloat() else 0f }, modifier = Modifier.fillMaxWidth(), color = AzulRuta)
        Text("$cerradas de $total paradas · ${r.platillos_asignados ?: "—"} platillos", color = TextoSuave, fontSize = 13.sp)
    }
}

private fun textoTiempo(t: MapboxGeocoder.TrazoRuta?): String? {
    val s = t?.segundos ?: return null
    val m = t.metros ?: return null
    val min = (s / 60).roundToInt().coerceAtLeast(1)
    val dist = if (m >= 1000) "%.1f km".format(m / 1000) else "${m.roundToInt()} m"
    return "$min min ($dist)"
}

/** Pantalla del repartidor en la calle: mapa a pantalla completa y hoja inferior (paradas o entrega activa). */
@Composable
fun DetalleRutaScreen(vm: RepartidorViewModel, ruta: RutaDto, usuario: UserDto?, onBack: () -> Unit) {
    val context = LocalContext.current
    var noEntregada by remember { mutableStateOf<ParadaDto?>(null) }
    var errorMapa by remember { mutableStateOf<String?>(null) }
    var abierta by remember { mutableStateOf<Int?>(null) }
    var cambiarLinea by remember { mutableStateOf(false) }
    var resumen by remember { mutableStateOf<MapboxGeocoder.TrazoRuta?>(null) }
    val control = remember { ControlMapa() }

    // Ubicación solo para dibujar en el mapa (no se manda al servidor).
    fun tienePermiso() = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED ||
        ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) == PackageManager.PERMISSION_GRANTED
    var ubicacionPermitida by remember { mutableStateOf(tienePermiso()) }
    val pedirUbicacion = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { ubicacionPermitida = tienePermiso() }
    LaunchedEffect(Unit) {
        if (!ubicacionPermitida && MapboxGeocoder.disponible)
            pedirUbicacion.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
    }

    // Foto tomada por parada (se revisa antes de confirmar).
    val fotos = remember { mutableStateMapOf<Int, Uri>() }
    var fotoPara by remember { mutableStateOf<Int?>(null) }
    var uriPendiente by remember { mutableStateOf<Uri?>(null) }
    val camara = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { ok ->
        val id = fotoPara; val uri = uriPendiente
        if (ok && id != null && uri != null) fotos[id] = uri
        fotoPara = null
    }
    fun tomarFoto(paradaId: Int) {
        val archivo = File(File(context.cacheDir, "evidencias").apply { mkdirs() }, "parada_${paradaId}_${System.currentTimeMillis()}.jpg")
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", archivo)
        uriPendiente = uri; fotoPara = paradaId
        camara.launch(uri)
    }

    val disp = (vm.disponibilidad as? Carga.Listo<DisponibilidadDto>)?.datos
    val hoy = LocalDate.now(ZONA_GDL).toString()
    val paradas = ruta.paradas.orEmpty().sortedBy { it.orden }
    val enCamino = paradas.firstOrNull { it.estado == "en_camino" }
    val siguiente = paradas.firstOrNull { it.id == ruta.siguiente_parada_id }
    val bloqueoInicio = when {
        siguiente == null -> if (ruta.estado == "completada") "La ruta ya se completó." else null
        siguiente.estado != "pendiente" -> null
        disp?.en_linea == false -> "Ponte en línea para iniciar (toca tu estado arriba)."
        ruta.estado != "en_curso" && ruta.fecha != hoy -> "Solo puedes iniciar las rutas de hoy."
        disp?.ruta_en_curso_id != null && disp.ruta_en_curso_id != ruta.id -> "Ya tienes otra ruta en curso."
        else -> null
    }
    val gpsActivo = ubicacionPermitida && control.miUbicacion != null

    Column(Modifier.fillMaxSize().background(Encabezado)) {
        // ---------- Encabezado ----------
        Row(Modifier.fillMaxWidth().statusBarsPadding().padding(start = 4.dp, end = 12.dp, top = 6.dp, bottom = 10.dp), verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Filled.ArrowBack, "Regresar", tint = Color.White) }
            Box(Modifier.size(44.dp).clip(CircleShape).background(Color(0xFF3A3F55)), contentAlignment = Alignment.Center) {
                val foto = usuario?.profile?.avatar_url
                if (foto != null) AsyncImage(foto, null, contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                else Text((usuario?.profile?.first_name ?: usuario?.name ?: "?").take(1).uppercase(), color = Color.White, fontWeight = FontWeight.Bold)
            }
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                val nombre = usuario?.profile?.first_name?.let { n -> n + (usuario.profile.last_name?.take(1)?.let { " $it." } ?: "") } ?: usuario?.name ?: "Repartidor"
                Text(nombre, color = Color.White, fontWeight = FontWeight.Bold, fontSize = 17.sp, maxLines = 1)
                if (enCamino == null) Text("Ruta ${ruta.zona ?: ""} · ${ruta.paradas_totales ?: paradas.size} paradas".replace("  ", " "), color = Color(0xFFB8BCCB), fontSize = 13.sp)
            }
            disp?.let {
                Text(textoEstadoRepartidor(it) + if (gpsActivo) " · GPS" else "",
                    color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.clip(RoundedCornerShape(50)).background(if (it.en_linea) VerdeEntrega else Color(0xFF6B6F80))
                        .clickable { cambiarLinea = true }.padding(horizontal = 14.dp, vertical = 7.dp))
            }
        }

        // ---------- Mapa a pantalla completa con la hoja encima ----------
        BoxWithConstraints(Modifier.fillMaxSize().clip(RoundedCornerShape(topStart = 20.dp, topEnd = 20.dp)).background(Color(0xFFE9ECEF))) {
            val altoHoja = if (enCamino != null) maxHeight * 0.50f else maxHeight * 0.48f
            if (MapboxGeocoder.disponible) {
                MapaRuta(paradas, ruta.siguiente_parada_id, enCamino, control, ubicacionPermitida,
                    onResumen = { resumen = it }, onError = { errorMapa = it }, modifier = Modifier.fillMaxSize())
            } else {
                Column(Modifier.fillMaxWidth().height(maxHeight - altoHoja).padding(24.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                    Icon(Icons.Default.Map, null, tint = TextoSuave, modifier = Modifier.size(40.dp))
                    Text("Mapa no disponible en esta versión (falta MAPBOX_ACCESS_TOKEN).", color = TextoSuave, textAlign = TextAlign.Center, fontSize = 14.sp)
                }
            }

            // Arriba: título o indicación de la parada en camino
            if (enCamino != null) {
                Row(
                    Modifier.padding(12.dp).fillMaxWidth().shadow(8.dp, RoundedCornerShape(14.dp)).clip(RoundedCornerShape(14.dp)).background(Encabezado)
                        .clickable(enabled = enCamino.ubicacion != null) { enCamino.ubicacion?.let { navegar(context, it, enCamino.destinatario) } }
                        .padding(horizontal = 16.dp, vertical = 14.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Icon(Icons.Default.TurnRight, null, tint = Color(0xFF7FB2FF), modifier = Modifier.size(36.dp))
                    Spacer(Modifier.width(14.dp))
                    Column(Modifier.weight(1f)) {
                        Text(enCamino.direccion ?: "—", color = Color.White, fontWeight = FontWeight.Bold, fontSize = 18.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        Text(textoTiempo(resumen) ?: enCamino.hora_estimada?.let { "Hora estimada $it" } ?: "Parada ${enCamino.orden}",
                            color = Color(0xFFB8BCCB), fontSize = 14.sp)
                    }
                }
            } else {
                Text("Mapa de rutas", fontWeight = FontWeight.Bold, fontSize = 26.sp, color = TextoTitulo, modifier = Modifier.padding(start = 18.dp, top = 16.dp))
            }

            // Controles a la derecha
            if (MapboxGeocoder.disponible) {
                Column(Modifier.align(Alignment.TopEnd).padding(end = 12.dp, top = (maxHeight - altoHoja) * 0.45f), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    BotonMapa(Icons.Default.MyLocation, "Mi ubicación") {
                        if (!ubicacionPermitida) pedirUbicacion.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
                        else if (!control.centrarEnMi()) errorMapa = "Aún no se obtiene tu ubicación."
                    }
                    Column(Modifier.shadow(4.dp, RoundedCornerShape(12.dp)).clip(RoundedCornerShape(12.dp)).background(Color.White)) {
                        IconButton(onClick = { control.acercar(1.0) }) { Icon(Icons.Default.Add, "Acercar", tint = TextoSuave) }
                        HorizontalDivider(Modifier.width(48.dp), color = Color(0xFFEEEEEE))
                        IconButton(onClick = { control.acercar(-1.0) }) { Icon(Icons.Default.Remove, "Alejar", tint = TextoSuave) }
                    }
                }
            }
            errorMapa?.let { Text(it, color = RojoError, fontSize = 12.sp, modifier = Modifier.align(Alignment.TopStart).padding(top = 70.dp, start = 12.dp).background(Color.White, RoundedCornerShape(8.dp)).padding(6.dp)) }

            // ---------- Hoja inferior ----------
            Surface(
                Modifier.align(Alignment.BottomCenter).fillMaxWidth().height(altoHoja),
                shape = RoundedCornerShape(topStart = 26.dp, topEnd = 26.dp), color = Color.White, shadowElevation = 16.dp
            ) {
                Column(Modifier.navigationBarsPadding()) {
                    Box(Modifier.padding(top = 10.dp, bottom = 4.dp).align(Alignment.CenterHorizontally).size(44.dp, 5.dp).clip(RoundedCornerShape(3.dp)).background(Color(0xFFD9D9D9)))
                    if (enCamino != null) {
                        EntregaActiva(
                            enCamino, fotos[enCamino.id], procesando = vm.procesando == enCamino.id,
                            ok = vm.okParada, error = vm.errorParada,
                            onFoto = { tomarFoto(enCamino.id) },
                            onConfirmar = { fotos[enCamino.id]?.let { uri -> vm.entregar(context, enCamino.id, uri) { fotos.remove(enCamino.id) } } },
                            onNoEntregada = { noEntregada = enCamino },
                            onLlamar = { llamar(context, it) },
                            modifier = Modifier.weight(1f)
                        )
                    } else {
                        Text(if (ruta.fecha == hoy) "Paradas de hoy" else "Paradas · ${Formato.diaCorto(ruta.fecha)}",
                            fontWeight = FontWeight.Bold, fontSize = 24.sp, color = TextoTitulo, modifier = Modifier.padding(start = 20.dp, top = 6.dp, bottom = 6.dp))
                        LazyColumn(Modifier.weight(1f)) {
                            item { Column(Modifier.padding(horizontal = 16.dp)) { AvisoOk(vm.okParada); AvisoError(vm.errorParada) } }
                            items(paradas, key = { it.id }) { p ->
                                FilaParada(p, esSiguiente = p.id == ruta.siguiente_parada_id, abierta = abierta == p.id,
                                    onClick = { abierta = if (abierta == p.id) null else p.id },
                                    onNavegar = { navegar(context, it, p.destinatario) }, onLlamar = { llamar(context, it) })
                            }
                        }
                        when {
                            siguiente != null && siguiente.estado == "pendiente" -> Column(Modifier.padding(start = 20.dp, end = 20.dp, top = 6.dp, bottom = 14.dp)) {
                                Button(
                                    onClick = { vm.iniciar(siguiente.id) },
                                    enabled = bloqueoInicio == null && vm.procesando == null,
                                    modifier = Modifier.fillMaxWidth().height(56.dp), shape = RoundedCornerShape(50),
                                    colors = ButtonDefaults.buttonColors(containerColor = AzulRuta, contentColor = Color.White)
                                ) {
                                    if (vm.procesando == siguiente.id) CircularProgressIndicator(Modifier.size(22.dp), color = Color.White, strokeWidth = 2.dp)
                                    else Text("Iniciar siguiente parada", fontWeight = FontWeight.Bold, fontSize = 17.sp)
                                }
                                bloqueoInicio?.let { Text(it, color = TextoSuave, fontSize = 12.sp, modifier = Modifier.fillMaxWidth(), textAlign = TextAlign.Center) }
                            }
                            ruta.estado == "completada" -> Box(Modifier.padding(16.dp)) { AvisoOk("Ruta completada. ¡Buen trabajo!") }
                        }
                    }
                }
            }
        }
    }

    if (cambiarLinea && disp != null) {
        val bloqueado = disp.en_linea && disp.ruta_en_curso_id != null
        AlertDialog(
            onDismissRequest = { cambiarLinea = false },
            title = { Text(if (disp.en_linea) "Ponerte fuera de línea" else "Ponerte en línea") },
            text = { Text(if (bloqueado) "Tienes una ruta en curso: termínala antes de ponerte fuera de línea." else if (disp.en_linea) "No podrás iniciar paradas mientras estés fuera de línea." else "Podrás iniciar tus paradas.") },
            confirmButton = {
                if (!bloqueado) TextButton(onClick = { vm.cambiarDisponibilidad(!disp.en_linea); cambiarLinea = false }) { Text("Confirmar") }
                else TextButton(onClick = { cambiarLinea = false }) { Text("Entendido") }
            },
            dismissButton = { if (!bloqueado) TextButton(onClick = { cambiarLinea = false }) { Text("Cancelar") } }
        )
    }

    noEntregada?.let { p ->
        var motivo by remember(p.id) { mutableStateOf("") }
        val valido = motivo.trim().length in 3..255
        AlertDialog(
            onDismissRequest = { noEntregada = null },
            title = { Text("No se pudo entregar") },
            text = {
                Column {
                    Text("Se abrirá una incidencia para el administrador.", color = TextoSuave, fontSize = 14.sp)
                    CampoTexto(motivo, { if (it.length <= 255) motivo = it }, "Motivo (3 a 255 caracteres)", lineas = 3)
                }
            },
            confirmButton = { TextButton(onClick = { vm.noEntregada(p.id, motivo) { fotos.remove(p.id) }; noEntregada = null }, enabled = valido) { Text("Confirmar", color = RojoError) } },
            dismissButton = { TextButton(onClick = { noEntregada = null }) { Text("Cancelar") } }
        )
    }
}

@Composable
private fun BotonMapa(icono: androidx.compose.ui.graphics.vector.ImageVector, descripcion: String, onClick: () -> Unit) {
    Box(Modifier.size(48.dp).shadow(4.dp, RoundedCornerShape(12.dp)).clip(RoundedCornerShape(12.dp)).background(Color.White).clickable(onClick = onClick),
        contentAlignment = Alignment.Center) { Icon(icono, descripcion, tint = TextoSuave) }
}

@Composable
private fun IconoEstado(p: ParadaDto, esSiguiente: Boolean) {
    when {
        p.estado == "entregado" -> Box(Modifier.size(30.dp).clip(CircleShape).background(VerdeEntrega), contentAlignment = Alignment.Center) {
            Icon(Icons.Default.Check, null, tint = Color.White, modifier = Modifier.size(20.dp))
        }
        p.estado == "no_localizado" -> Box(Modifier.size(30.dp).clip(CircleShape).background(RojoError), contentAlignment = Alignment.Center) {
            Icon(Icons.Default.Close, null, tint = Color.White, modifier = Modifier.size(20.dp))
        }
        esSiguiente -> Text("Siguiente", color = Color.White, fontSize = 14.sp, fontWeight = FontWeight.SemiBold,
            modifier = Modifier.background(AzulRuta, RoundedCornerShape(50)).padding(horizontal = 14.dp, vertical = 5.dp))
        else -> Box(Modifier.size(30.dp).clip(CircleShape).background(Color(0xFFC4C4C4)), contentAlignment = Alignment.Center) {
            Box(Modifier.size(13.dp, 3.dp).background(Color.White))
        }
    }
}

@Composable
private fun FilaParada(p: ParadaDto, esSiguiente: Boolean, abierta: Boolean, onClick: () -> Unit, onNavegar: (UbicacionDto) -> Unit, onLlamar: (String) -> Unit) {
    Column(
        Modifier.fillMaxWidth().background(if (esSiguiente) Color(0xFFEFF2F7) else Color.Transparent)
            .clickable(onClick = onClick).padding(horizontal = 20.dp, vertical = 10.dp)
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("${p.orden}.", fontWeight = if (esSiguiente) FontWeight.Bold else FontWeight.Normal,
                color = if (esSiguiente) TextoTitulo else TextoSuave, modifier = Modifier.width(36.dp), fontSize = 19.sp)
            Column(Modifier.weight(1f)) {
                Text(p.destinatario ?: "—", fontWeight = FontWeight.Medium, color = TextoTitulo, fontSize = 18.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                // Como en el diseño: empresas muestran la hora; clientes la dirección.
                val sub = if (p.tipo == "empresa") p.hora_estimada ?: p.direccion else p.direccion ?: p.hora_estimada
                Text(sub ?: "—", color = TextoSuave, fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            Spacer(Modifier.width(8.dp))
            IconoEstado(p, esSiguiente)
        }
        if (abierta) {
            Column(Modifier.padding(start = 36.dp, top = 6.dp)) {
                Text(p.direccion ?: "—", fontSize = 14.sp, color = TextoTitulo)
                p.hora_estimada?.let { Text("Hora estimada: $it", color = TextoSuave, fontSize = 13.sp) }
                p.referencias?.let { Text("Referencias: $it", color = TextoSuave, fontSize = 13.sp) }
                p.contacto_nombre?.let { Text("Preguntar por: $it", color = TextoSuave, fontSize = 13.sp) }
                p.folio?.let { Text("Folio: $it", color = TextoSuave, fontSize = 13.sp) }
                ListaPlatillos(p)
                p.motivo_no_entrega?.let { Text("Motivo: $it", color = RojoError, fontSize = 13.sp) }
                p.cerrada_en?.let { Text("Cerrada a las ${Formato.hora(it)}", color = TextoSuave, fontSize = 12.sp) }
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    p.ubicacion?.let { u -> TextButton(onClick = { onNavegar(u) }) { Icon(Icons.Default.Navigation, null); Spacer(Modifier.width(4.dp)); Text("Navegar") } }
                    p.telefono?.let { t -> TextButton(onClick = { onLlamar(t) }) { Icon(Icons.Default.Call, null); Spacer(Modifier.width(4.dp)); Text("Llamar") } }
                }
                if (p.ubicacion == null) Text("Sin punto en el mapa: guíate por la dirección.", color = TextoSuave, fontSize = 12.sp)
            }
        }
    }
}

@Composable
private fun ListaPlatillos(p: ParadaDto) {
    Text("${if (p.tipo == "empresa") "Empresa" else "Cliente"} · ${p.total_platillos ?: 0} platillos", color = TextoSuave, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
    p.platillos.orEmpty().forEach { pl ->
        Text(
            "• ${pl.cantidad ?: 1}× ${pl.nombre ?: "—"} (${Formato.tiempoComida(pl.tiempo_comida).lowercase()}" +
                (pl.dia_de_comida?.let { ", ${Formato.diaCorto(it)}" } ?: "") + (if (pl.es_extra == true) ", extra" else "") + ")",
            fontSize = 13.sp, color = TextoTitulo
        )
    }
}

/** Parada en camino (diseño 2): contacto con botón de llamar, evidencia con vista previa y confirmación. Sin mensajes. */
@Composable
private fun EntregaActiva(
    p: ParadaDto, foto: Uri?, procesando: Boolean, ok: String?, error: String?,
    onFoto: () -> Unit, onConfirmar: () -> Unit, onNoEntregada: () -> Unit,
    onLlamar: (String) -> Unit, modifier: Modifier = Modifier
) {
    var verPlatillos by remember(p.id) { mutableStateOf(false) }
    Column(modifier.verticalScroll(rememberScrollState()).padding(horizontal = 20.dp, vertical = 8.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(58.dp).clip(CircleShape).background(MostazaClaro), contentAlignment = Alignment.Center) {
                Text((p.destinatario ?: "?").take(1).uppercase(), fontWeight = FontWeight.Bold, fontSize = 24.sp, color = Color(0xFF8A6100))
            }
            Spacer(Modifier.width(14.dp))
            Column(Modifier.weight(1f)) {
                Text(p.destinatario ?: "—", fontWeight = FontWeight.Bold, fontSize = 20.sp, color = TextoTitulo, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(p.direccion ?: "—", color = TextoSuave, fontSize = 14.sp, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
            p.telefono?.let { t ->
                Spacer(Modifier.width(8.dp))
                Box(Modifier.size(48.dp).clip(CircleShape).background(Color(0xFFE8F0FE)).clickable { onLlamar(t) }, contentAlignment = Alignment.Center) {
                    Icon(Icons.Default.Call, "Llamar", tint = AzulRuta)
                }
            }
        }
        val extra = listOfNotNull(p.contacto_nombre?.let { "Preguntar por $it" }, p.referencias, p.folio?.let { "Folio $it" })
        if (extra.isNotEmpty()) Text(extra.joinToString(" · "), color = TextoSuave, fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp))
        TextButton(onClick = { verPlatillos = !verPlatillos }, contentPadding = PaddingValues(0.dp)) {
            Text(if (verPlatillos) "Ocultar platillos" else "Ver platillos (${p.total_platillos ?: p.platillos?.size ?: 0})", color = AzulRuta)
        }
        if (verPlatillos) ListaPlatillos(p)

        AvisoOk(ok); AvisoError(error)
        Spacer(Modifier.height(4.dp))
        Text("Evidencia de entrega", color = TextoSuave, fontSize = 14.sp)
        Spacer(Modifier.height(8.dp))
        Row(verticalAlignment = Alignment.CenterVertically) {
            Surface(onClick = onFoto, enabled = !procesando, shape = RoundedCornerShape(16.dp), color = GrisSuave, modifier = Modifier.weight(1f).height(68.dp)) {
                Row(Modifier.padding(horizontal = 18.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Default.PhotoCamera, null, tint = TextoTitulo, modifier = Modifier.size(26.dp))
                    Spacer(Modifier.width(12.dp))
                    Text(if (foto == null) "Tomar foto de entrega" else "Tomar otra foto", fontSize = 16.sp, fontWeight = FontWeight.Medium, color = TextoTitulo)
                }
            }
            Spacer(Modifier.width(10.dp))
            Box(Modifier.size(92.dp, 68.dp).clip(RoundedCornerShape(12.dp)).background(GrisSuave), contentAlignment = Alignment.Center) {
                if (foto != null) AsyncImage(foto, "Foto de evidencia", contentScale = ContentScale.Crop, modifier = Modifier.fillMaxSize())
                else Icon(Icons.Default.Image, null, tint = Color(0xFFC4C4C4))
            }
        }
        Spacer(Modifier.height(14.dp))
        Button(
            onClick = onConfirmar, enabled = foto != null && !procesando,
            modifier = Modifier.fillMaxWidth().height(56.dp), shape = RoundedCornerShape(50),
            colors = ButtonDefaults.buttonColors(containerColor = VerdeEntrega, contentColor = Color.White, disabledContainerColor = VerdeEntrega.copy(alpha = 0.4f), disabledContentColor = Color.White)
        ) {
            if (procesando) CircularProgressIndicator(Modifier.size(22.dp), color = Color.White, strokeWidth = 2.dp)
            else Text("Confirmar entrega", fontWeight = FontWeight.Bold, fontSize = 17.sp)
        }
        if (foto == null) Text("Toma la foto para poder confirmar.", color = TextoSuave, fontSize = 12.sp, modifier = Modifier.fillMaxWidth(), textAlign = TextAlign.Center)
        TextButton(onClick = onNoEntregada, enabled = !procesando, modifier = Modifier.fillMaxWidth()) { Text("No se pudo entregar", color = RojoError) }
    }
}

private fun navegar(context: android.content.Context, u: UbicacionDto, nombre: String?) = abrir(
    context,
    Intent(Intent.ACTION_VIEW, Uri.parse("geo:${u.latitud},${u.longitud}?q=${u.latitud},${u.longitud}(${Uri.encode(nombre ?: "Parada")})")),
    "No hay una app de mapas instalada."
)

private fun llamar(context: android.content.Context, tel: String) =
    abrir(context, Intent(Intent.ACTION_DIAL, Uri.parse("tel:${tel.filter { it.isDigit() || it == '+' }}")), "No hay una app de teléfono.")

private fun abrir(context: android.content.Context, intent: Intent, siFalla: String) {
    try { context.startActivity(intent) } catch (_: android.content.ActivityNotFoundException) {
        android.widget.Toast.makeText(context, siFalla, android.widget.Toast.LENGTH_SHORT).show()
    }
}
