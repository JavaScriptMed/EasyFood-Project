package com.example.easylunch.ui.empleado

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import com.example.easylunch.data.model.*
import com.example.easylunch.ui.auth.SesionViewModel
import com.example.easylunch.ui.cliente.DialogoCalificarEntrega
import com.example.easylunch.ui.cliente.DialogoCalificarPlatillo
import com.example.easylunch.ui.common.*
import com.example.easylunch.ui.shared.BarraInferior
import com.example.easylunch.ui.shared.PerfilScreen
import com.example.easylunch.ui.shared.Pestana
import java.time.LocalTime

private val PESTANAS = listOf(
    Pestana("comida", "Mi comida", Icons.Default.Restaurant),
    Pestana("historial", "Historial", Icons.Default.History),
    Pestana("calificar", "Calificar", Icons.Default.StarRate),
    Pestana("perfil", "Perfil", Icons.Default.Person)
)

@Composable
fun EmpleadoHome(sesion: SesionViewModel) {
    val vm: EmpleadoViewModel = viewModel()
    var pestana by rememberSaveable { mutableStateOf("comida") }
    Scaffold(containerColor = FondoApp, bottomBar = { BarraInferior(PESTANAS, pestana) { pestana = it } }) { padding ->
        Box(Modifier.padding(bottom = padding.calculateBottomPadding())) {
            when (pestana) {
                "comida" -> MiComidaScreen(vm, sesion.configuracion?.hora_corte)
                "historial" -> HistorialScreen(vm)
                "calificar" -> PorCalificarScreen(vm)
                else -> PerfilScreen(sesion)
            }
        }
    }
}

/** Momento del corte: `hora_corte` del día calendario anterior a la entrega (hora de Guadalajara). */
private fun corteDe(m: EmpleadoMenuDto, horaCorteConfig: String?): java.time.Instant? {
    val fecha = Formato.fecha(m.fecha) ?: return null
    val hora = (m.hora_corte ?: horaCorteConfig)?.let { runCatching { LocalTime.parse(it) }.getOrNull() } ?: return null
    return fecha.minusDays(1).atTime(hora).atZone(ZONA_GDL).toInstant()
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
fun MiComidaScreen(vm: EmpleadoViewModel, horaCorteConfig: String?) {
    LaunchedEffect(Unit) { if (vm.menu !is Carga.Listo) vm.cargarMenu() }
    Column(Modifier.fillMaxSize().background(FondoApp)) {
        BarraSuperior("Mi comida", acciones = { IconButton(onClick = { vm.cargarMenu() }) { Icon(Icons.Default.Refresh, "Actualizar") } })
        // Selector de fecha y tiempo de comida
        Row(Modifier.fillMaxWidth().padding(horizontal = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            val f = Formato.fecha(vm.fecha)
            IconButton(onClick = { f?.let { vm.cargarMenu(it.minusDays(1).toString()) } }, enabled = f != null) {
                Icon(Icons.AutoMirrored.Filled.KeyboardArrowLeft, "Día anterior")
            }
            Text(vm.fecha?.let { Formato.diaLargo(it).replaceFirstChar { c -> c.uppercase() } } ?: "Próximo día disponible",
                fontWeight = FontWeight.Bold, color = TextoTitulo, modifier = Modifier.weight(1f), textAlign = androidx.compose.ui.text.style.TextAlign.Center)
            IconButton(onClick = { f?.let { vm.cargarMenu(it.plusDays(1).toString()) } }, enabled = f != null) {
                Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, "Día siguiente")
            }
        }
        FlowRow(Modifier.padding(horizontal = 16.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            listOf("desayuno", "comida", "cena").forEach { t ->
                FilterChip(selected = vm.tiempo == t, onClick = { vm.cargarMenu(vm.fecha, t) }, label = { Text(Formato.tiempoComida(t)) })
            }
            TextButton(onClick = { vm.cargarMenu(null, vm.tiempo) }) { Text("Próximo abierto") }
        }
        var vista by rememberVistaPlatillos()
        CargaView(vm.menu, onReintentar = { vm.cargarMenu() }) { m ->
            // El estado abierto/cerrado viene SOLO del servidor (`cerrado`, `motivo_cerrado`).
            val cerrado = m.cerrado == true
            LazyColumn(contentPadding = PaddingValues(16.dp, 8.dp, 16.dp, 100.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                item {
                    Tarjeta {
                        m.empleado?.empresa_nombre?.let { Text(it, fontWeight = FontWeight.Bold, color = TextoTitulo) }
                        m.hora_entrega?.let { FilaDato("Hora de entrega", it) }
                        FilaDato("Precio", Formato.dinero(m.precio))
                        FilaDato("Tu empresa cubre", Formato.dinero(m.empresa_cubre))
                        FilaDato("Tú pagas", Formato.dinero(m.empleado_paga), negrita = true)
                        Spacer(Modifier.height(6.dp))
                        when {
                            m.motivo_cerrado == "empresa_cerrada" -> AvisoInfo("Tu empresa no tiene servicio este día.")
                            cerrado -> AvisoInfo("Ya pasó el corte de este día: la elección no se puede cambiar.")
                            else -> {
                                // Abierto según el servidor. La cuenta regresiva es solo informativa:
                                // si el cálculo con hora_corte no da un momento futuro, no se muestra.
                                val corte = corteDe(m, horaCorteConfig)?.takeIf { it.isAfter(java.time.Instant.now()) }
                                AvisoOk("Abierto: puedes elegir o cambiar.")
                                corte?.let {
                                    val z = it.atZone(ZONA_GDL)
                                    Text("Corte: ${Formato.diaLargo(z.toLocalDate())} a las ${"%02d:%02d".format(z.hour, z.minute)}", color = TextoSuave, fontSize = 14.sp)
                                    CuentaRegresiva(it, "Cierra en", alVencer = { vm.cargarMenu(silencioso = true) }, color = Color(0xFF8A6100))
                                }
                            }
                        }
                    }
                }
                item {
                    val e = m.eleccion
                    Tarjeta {
                        Text("Tu elección", color = TextoSuave, fontSize = 13.sp)
                        if (e == null) Text("Aún no eliges ${Formato.tiempoComida(m.tiempo_comida).lowercase()} para este día.", color = TextoTitulo)
                        else {
                            Text(e.platillo_nombre ?: "—", fontWeight = FontWeight.Bold, fontSize = 17.sp, color = TextoTitulo)
                            if (e.origen == "por_defecto") Etiqueta("Asignada por defecto")
                            if (!cerrado) TextButton(onClick = { vm.quitar(m) }, enabled = !vm.guardando, contentPadding = PaddingValues(0.dp)) {
                                Text("Quitar mi elección", color = RojoError)
                            }
                        }
                        AvisoOk(vm.okAccion)
                        AvisoError(vm.errorAccion)
                    }
                }
                val opciones = m.opciones.orEmpty()
                if (opciones.isEmpty() && m.motivo_cerrado != "empresa_cerrada") item {
                    AvisoInfo("No hay platillos publicados de ${Formato.tiempoComida(m.tiempo_comida).lowercase()} para este día.")
                }
                if (opciones.isNotEmpty()) item { SelectorVista(vista, { vista = it }, Modifier.fillMaxWidth()) }
                val alElegir: (OpcionMenuDto) -> Unit = { o -> vm.elegir(m, o.platillo.id) }
                if (vista == VistaPlatillos.CUADRICULA) {
                    items(opciones.chunked(2), key = { fila -> fila.first().platillo.id }) { fila ->
                        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                            fila.forEach { o -> Box(Modifier.weight(1f)) { TarjetaOpcion(o, m, vista, cerrado, vm.guardando, alElegir) } }
                            if (fila.size == 1) Spacer(Modifier.weight(1f))
                        }
                    }
                } else {
                    items(opciones, key = { it.platillo.id }) { o -> TarjetaOpcion(o, m, vista, cerrado, vm.guardando, alElegir) }
                }
            }
        }
    }
}

@Composable
private fun TarjetaOpcion(
    o: OpcionMenuDto, m: EmpleadoMenuDto, vista: VistaPlatillos, cerrado: Boolean, guardando: Boolean,
    onElegir: (OpcionMenuDto) -> Unit
) {
    val elegido = m.eleccion?.platillo_id == o.platillo.id
    val agotado = o.agotado == true
    val estado: @Composable () -> Unit = {
        when {
            elegido -> Etiqueta("Tu elección", Color(0xFFE8F5E9), VerdeOk)
            agotado -> Etiqueta("Agotado", Color(0xFFFDECEA), RojoError)
            o.porciones_restantes != null -> Etiqueta("Quedan ${o.porciones_restantes}")
        }
    }
    val boton: @Composable () -> Unit = {
        if (!elegido) {
            Button(
                onClick = { onElegir(o) }, enabled = !cerrado && !agotado && !guardando,
                modifier = if (vista == VistaPlatillos.LISTA) Modifier else Modifier.fillMaxWidth(),
                colors = ButtonDefaults.buttonColors(containerColor = Mostaza, contentColor = Color.Black)
            ) { Text(if (m.eleccion == null) "Elegir" else "Cambiar", maxLines = 1) }
            if (cerrado) Text("Cerrado: ya no se puede elegir.", color = TextoSuave, fontSize = 12.sp)
            else if (agotado) Text("Ya no quedan porciones.", color = TextoSuave, fontSize = 12.sp)
        }
    }
    val detalles = listOfNotNull(
        o.platillo.calorias?.let { "$it kcal" },
        o.platillo.alergenos?.takeIf { it.isNotEmpty() }?.let { "Alérgenos: ${it.joinToString()}" }
    ).joinToString(" · ")

    Card(
        shape = androidx.compose.foundation.shape.RoundedCornerShape(16.dp),
        colors = CardDefaults.cardColors(containerColor = if (elegido) Color(0xFFF1F8E9) else Color.White),
        elevation = CardDefaults.cardElevation(2.dp),
        modifier = Modifier.fillMaxWidth()
    ) {
        when (vista) {
            VistaPlatillos.LISTA -> Row(Modifier.padding(12.dp)) {
                FotoPlatillo(o.platillo.foto_url, Modifier.size(84.dp))
                Spacer(Modifier.width(12.dp))
                Column(Modifier.weight(1f)) {
                    Text(o.platillo.nombre, fontWeight = FontWeight.Bold, color = TextoTitulo)
                    o.platillo.comedor_nombre?.let { Text(it, color = TextoSuave, fontSize = 12.sp) }
                    o.platillo.descripcion?.let { Text(it, color = TextoTitulo, fontSize = 13.sp, maxLines = 2) }
                    if (detalles.isNotEmpty()) Text(detalles, color = TextoSuave, fontSize = 12.sp)
                    Spacer(Modifier.height(6.dp))
                    estado()
                    Spacer(Modifier.height(6.dp))
                    boton()
                }
            }
            VistaPlatillos.CUADRICULA -> Column {
                FotoPlatillo(o.platillo.foto_url, Modifier.fillMaxWidth().aspectRatio(1f), redondeo = 0)
                Column(Modifier.padding(10.dp)) {
                    Text(o.platillo.nombre, fontWeight = FontWeight.Bold, color = TextoTitulo, fontSize = 14.sp, maxLines = 2)
                    Spacer(Modifier.height(4.dp))
                    estado()
                    Spacer(Modifier.height(6.dp))
                    boton()
                }
            }
            VistaPlatillos.GRANDE -> Column {
                FotoPlatillo(o.platillo.foto_url, Modifier.fillMaxWidth().height(200.dp), redondeo = 0)
                Column(Modifier.padding(16.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(o.platillo.nombre, fontWeight = FontWeight.Bold, color = TextoTitulo, fontSize = 18.sp, modifier = Modifier.weight(1f))
                        estado()
                    }
                    o.platillo.comedor_nombre?.let { Text(it, color = TextoSuave, fontSize = 13.sp) }
                    o.platillo.descripcion?.let { Text(it, color = TextoTitulo, fontSize = 14.sp) }
                    if (detalles.isNotEmpty()) Text(detalles, color = TextoSuave, fontSize = 13.sp)
                    o.platillo.etiquetas?.takeIf { it.isNotEmpty() }?.let { Text(it.joinToString(" · "), color = Color(0xFF8A6100), fontSize = 12.sp) }
                    Spacer(Modifier.height(8.dp))
                    boton()
                }
            }
        }
    }
}

@Composable
fun HistorialScreen(vm: EmpleadoViewModel) {
    LaunchedEffect(Unit) { vm.cargarHistorial() }
    Column(Modifier.fillMaxSize().background(FondoApp)) {
        BarraSuperior("Historial", acciones = { IconButton(onClick = vm::cargarHistorial) { Icon(Icons.Default.Refresh, "Actualizar") } })
        CargaView(vm.historial, onReintentar = vm::cargarHistorial, esVacio = { it.isEmpty() },
            vacio = { PantallaVacia("Sin elecciones todavía", "Aquí verás lo que elegiste cada día.", icono = Icons.Default.History) }) { lista ->
            LazyColumn(contentPadding = PaddingValues(16.dp, 8.dp, 16.dp, 100.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                items(lista, key = { it.id }) { h ->
                    Tarjeta {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text("${Formato.diaCorto(h.fecha)} · ${Formato.tiempoComida(h.tiempo_comida)}", fontWeight = FontWeight.Bold, color = TextoTitulo, modifier = Modifier.weight(1f))
                            if (h.cerrada == true) Etiqueta("Cerrada", Color(0xFFEEEEEE), TextoSuave) else Etiqueta("Abierta", Color(0xFFE8F5E9), VerdeOk)
                        }
                        Text(h.platillo_nombre ?: "—", color = TextoTitulo)
                        if (h.origen == "por_defecto") Text("Asignada por defecto (no elegiste a tiempo)", color = TextoSuave, fontSize = 13.sp)
                        FilaDato("Precio", Formato.dinero(h.precio))
                        FilaDato("Empresa cubre", Formato.dinero(h.empresa_cubre))
                        FilaDato("Tú pagas", Formato.dinero(h.empleado_paga), negrita = true)
                    }
                }
            }
        }
    }
}

@Composable
fun PorCalificarScreen(vm: EmpleadoViewModel) {
    LaunchedEffect(Unit) { vm.cargarPendientes() }
    var entrega by remember { mutableStateOf<String?>(null) }
    var platillo by remember { mutableStateOf<Pair<String, PendientePlatilloDto>?>(null) }
    Column(Modifier.fillMaxSize().background(FondoApp)) {
        BarraSuperior("Por calificar", acciones = { IconButton(onClick = { vm.cargarPendientes() }) { Icon(Icons.Default.Refresh, "Actualizar") } })
        CargaView(vm.pendientes, onReintentar = { vm.cargarPendientes() }, esVacio = { it.isEmpty() },
            vacio = { PantallaVacia("Nada por calificar", "Aquí aparece lo que recibiste en los últimos 14 días y aún no calificas.", icono = Icons.Default.StarRate) }) { lista ->
            LazyColumn(contentPadding = PaddingValues(16.dp, 8.dp, 16.dp, 100.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                items(lista, key = { it.fecha }) { d ->
                    Tarjeta {
                        Text(Formato.diaLargo(d.fecha).replaceFirstChar { it.uppercase() }, fontWeight = FontWeight.Bold, color = TextoTitulo)
                        if (d.entrega_calificada != true) {
                            OutlinedButton(onClick = { vm.limpiarCalificacion(); entrega = d.fecha }, modifier = Modifier.fillMaxWidth()) { Text("Calificar la entrega") }
                        } else Text("Entrega calificada ✓", color = VerdeOk, fontSize = 14.sp)
                        d.platillos.orEmpty().forEach { p ->
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text("${Formato.tiempoComida(p.tiempo_comida)}: ${p.platillo_nombre ?: "—"}", modifier = Modifier.weight(1f), fontSize = 14.sp)
                                if (p.calificado == true) Text("✓", color = VerdeOk)
                                else TextButton(onClick = { vm.limpiarCalificacion(); platillo = d.fecha to p }) { Text("Calificar") }
                            }
                        }
                    }
                }
            }
        }
    }
    entrega?.let { f ->
        DialogoCalificarEntrega(vm.calificando, vm.resultadoCalificacion, onCerrar = { entrega = null }) { a, b, c, com ->
            vm.calificarEntrega(CalificarEntregaRequest(f, a, b, c, com.ifBlank { null }))
        }
    }
    platillo?.let { (f, p) ->
        DialogoCalificarPlatillo(p.platillo_nombre ?: "Platillo", vm.calificando, vm.resultadoCalificacion, onCerrar = { platillo = null }) { e, com ->
            vm.calificarPlatillo(CalificarPlatilloEmpleadoRequest(f, p.tiempo_comida, e, com.ifBlank { null }))
        }
    }
}
