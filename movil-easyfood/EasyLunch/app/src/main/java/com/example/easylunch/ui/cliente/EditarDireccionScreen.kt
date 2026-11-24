package com.example.easylunch.ui.cliente

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Place
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.easylunch.data.geo.Cobertura
import com.example.easylunch.data.geo.Coordenada
import com.example.easylunch.data.geo.LugarSugerido
import com.example.easylunch.data.geo.MapboxGeocoder
import com.example.easylunch.data.model.DireccionDto
import com.example.easylunch.data.model.DireccionRequest
import com.example.easylunch.ui.auth.CampoTexto
import com.example.easylunch.ui.common.*
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** Separa "calle y número, colonia" (formato con el que la app guarda `direccion`). */
private fun separar(direccion: String?): Pair<String, String> {
    if (direccion.isNullOrBlank()) return "" to ""
    val i = direccion.indexOf(", ")
    return if (i < 0) direccion to "" else direccion.substring(0, i) to direccion.substring(i + 2)
}

@Composable
fun EditarDireccionScreen(vm: ClienteViewModel, original: DireccionDto?, onBack: () -> Unit) {
    val scope = rememberCoroutineScope()
    LaunchedEffect(Unit) { vm.limpiarErrorDireccion(); vm.cargarZonas() }

    val (calle0, colonia0) = remember(original) { separar(original?.direccion) }
    var etiqueta by remember { mutableStateOf(original?.etiqueta ?: "") }
    var calle by remember { mutableStateOf(calle0) }
    var colonia by remember { mutableStateOf(colonia0) }
    var cp by remember { mutableStateOf(original?.codigo_postal ?: "") }
    var interior by remember { mutableStateOf(original?.numero_interior ?: "") }
    var referencias by remember { mutableStateOf(original?.referencias ?: "") }
    var predeterminada by remember { mutableStateOf(original?.predeterminada == true) }
    val puntoOriginal = original?.let { o -> if (o.latitud != null && o.longitud != null) Coordenada(o.latitud, o.longitud) else null }
    var pin by remember { mutableStateOf(puntoOriginal) }
    var centro by remember { mutableStateOf(puntoOriginal ?: Cobertura.CENTRO_GDL) }

    var busqueda by remember { mutableStateOf("") }
    var sugerencias by remember { mutableStateOf<List<LugarSugerido>>(emptyList()) }
    var errorBusqueda by remember { mutableStateOf<String?>(null) }
    var errorMapa by remember { mutableStateOf<String?>(null) }
    var guardada by remember { mutableStateOf<DireccionDto?>(null) }

    // Sugerencias mientras se escribe (limitadas a Guadalajara).
    LaunchedEffect(busqueda) {
        if (busqueda.trim().length < 3 || !MapboxGeocoder.disponible) { sugerencias = emptyList(); return@LaunchedEffect }
        delay(350)
        MapboxGeocoder.sugerencias(busqueda.trim())
            .onSuccess { sugerencias = it; errorBusqueda = if (it.isEmpty()) "Sin resultados en el área de Guadalajara." else null }
            .onFailure { sugerencias = emptyList(); errorBusqueda = "No se pudo buscar la dirección. Puedes marcar el punto en el mapa." }
    }

    fun aplicar(l: LugarSugerido) {
        pin = l.punto; centro = l.punto
        l.calleYNumero?.let { calle = it }
        l.colonia?.let { colonia = it }
        l.codigoPostal?.let { cp = it }
        sugerencias = emptyList(); busqueda = ""
    }

    fun moverPin(p: Coordenada) {
        pin = p
        // Si los campos están vacíos, se llenan con la dirección del punto.
        if (calle.isBlank() && MapboxGeocoder.disponible) scope.launch {
            MapboxGeocoder.inversa(p).onSuccess { l ->
                if (l != null && calle.isBlank()) {
                    l.calleYNumero?.let { calle = it }; l.colonia?.let { colonia = it }; l.codigoPostal?.let { cp = it }
                }
            }
        }
    }

    val zonas = vm.zonas
    val zonaPin = if (zonas != null && pin != null) Cobertura.zonaDe(pin!!, zonas) else null
    val fueraEnVivo = zonas != null && pin != null && zonaPin == null
    val err = vm.errorGuardarDireccion

    Column(Modifier.fillMaxSize().background(FondoApp)) {
        BarraSuperior(if (original == null) "Nueva dirección" else "Editar dirección", onBack = onBack)
        Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp)) {
            if (!MapboxGeocoder.disponible) {
                AvisoError("El mapa no está configurado en esta versión de la app (falta MAPBOX_ACCESS_TOKEN). " +
                    if (puntoOriginal != null) "Puedes editar los datos; se conserva el punto guardado." else "Sin mapa no se puede marcar el punto de entrega.")
                Spacer(Modifier.height(12.dp))
            } else {
                OutlinedTextField(
                    value = busqueda, onValueChange = { busqueda = it },
                    label = { Text("Buscar dirección o lugar") },
                    leadingIcon = { Icon(Icons.Default.Search, null) },
                    singleLine = true, modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(16.dp)
                )
                if (sugerencias.isNotEmpty()) {
                    Card(Modifier.fillMaxWidth().padding(top = 4.dp), colors = CardDefaults.cardColors(containerColor = Color.White), elevation = CardDefaults.cardElevation(4.dp)) {
                        sugerencias.forEach { s ->
                            Row(Modifier.fillMaxWidth().clickable { aplicar(s) }.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                                Icon(Icons.Default.Place, null, tint = Mostaza)
                                Spacer(Modifier.width(8.dp))
                                Column {
                                    Text(s.titulo, fontWeight = FontWeight.SemiBold)
                                    Text(s.detalle, color = TextoSuave, fontSize = 13.sp)
                                }
                            }
                        }
                    }
                }
                errorBusqueda?.let { Text(it, color = TextoSuave, fontSize = 13.sp) }
                Spacer(Modifier.height(8.dp))
                Box(Modifier.fillMaxWidth().height(300.dp).clip(RoundedCornerShape(16.dp))) {
                    MapaMapbox(
                        token = MapboxGeocoder.token, pin = pin, centrarEn = centro, zonas = zonas.orEmpty(),
                        onPin = ::moverPin, onError = { errorMapa = it }, modifier = Modifier.fillMaxSize()
                    )
                }
                Text("Toca el mapa o arrastra el pin para marcar el punto exacto de entrega.", color = TextoSuave, fontSize = 13.sp)
                AvisoError(errorMapa)
                Spacer(Modifier.height(8.dp))
                when {
                    pin == null -> AvisoInfo("Marca el punto de entrega en el mapa.")
                    zonaPin != null -> AvisoOk("Zona: ${zonaPin.nombre}")
                    fueraEnVivo -> AvisoError("Fuera de cobertura: mueve el pin dentro de una zona.")
                    else -> vm.zonasError?.let { AvisoInfo(it) }
                }
                Spacer(Modifier.height(12.dp))
            }

            CampoTexto(etiqueta, { if (it.length <= 50) etiqueta = it }, "Etiqueta (Casa, Oficina…)", error = err?.campo("etiqueta"))
            CampoTexto(calle, { calle = it }, "Calle y número", error = err?.campo("direccion"))
            CampoTexto(colonia, { colonia = it }, "Colonia")
            Row {
                CampoTexto(cp, { if (it.length <= 10) cp = it }, "Código postal", Modifier.weight(1f), teclado = KeyboardType.Number, error = err?.campo("codigo_postal"))
                Spacer(Modifier.width(8.dp))
                CampoTexto(interior, { if (it.length <= 20) interior = it }, "Núm. interior (opc.)", Modifier.weight(1f), error = err?.campo("numero_interior"))
            }
            CampoTexto(referencias, { if (it.length <= 500) referencias = it }, "Referencias (piso, acceso, entre X y Y)", lineas = 2, error = err?.campo("referencias"))
            Row(verticalAlignment = Alignment.CenterVertically) {
                Checkbox(checked = predeterminada, onCheckedChange = { predeterminada = it })
                Text("Usar como dirección predeterminada")
            }
            AvisoError(err?.mensaje?.takeIf { err.campos.isEmpty() || err.codigo != null })
            Spacer(Modifier.height(12.dp))
            BotonPrincipal(
                "Guardar dirección",
                onClick = {
                    val p = pin ?: return@BotonPrincipal
                    val texto = listOf(calle.trim(), colonia.trim()).filter { it.isNotEmpty() }.joinToString(", ")
                    vm.guardarDireccion(
                        original?.id,
                        DireccionRequest(
                            etiqueta = etiqueta.trim().ifEmpty { null }, direccion = texto,
                            numero_interior = interior.trim().ifEmpty { null }, codigo_postal = cp.trim().ifEmpty { null },
                            latitud = p.lat, longitud = p.lng,
                            referencias = referencias.trim().ifEmpty { null }, predeterminada = predeterminada
                        )
                    ) { d -> if (d.fueraDeCobertura) guardada = d else onBack() }
                },
                habilitado = pin != null && calle.isNotBlank() && !fueraEnVivo,
                cargando = vm.guardandoDireccion
            )
            Spacer(Modifier.height(32.dp))
        }
    }

    guardada?.let {
        AlertDialog(
            onDismissRequest = { guardada = null; onBack() },
            title = { Text("Fuera de cobertura") },
            text = { Text("Guardamos la dirección, pero el punto está fuera de las zonas de entrega: no la podrás usar para pedir. Puedes editarla y mover el punto.") },
            confirmButton = { TextButton(onClick = { guardada = null; onBack() }) { Text("Entendido") } }
        )
    }
}
