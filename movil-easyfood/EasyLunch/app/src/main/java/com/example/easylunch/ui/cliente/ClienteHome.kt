package com.example.easylunch.ui.cliente

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material.icons.Icons
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
import com.example.easylunch.data.model.DireccionDto
import com.example.easylunch.ui.auth.SesionViewModel
import com.example.easylunch.ui.common.*
import com.example.easylunch.ui.shared.BarraInferior
import com.example.easylunch.ui.shared.PerfilScreen
import com.example.easylunch.ui.shared.Pestana

/** Pantallas encima de las pestañas. */
sealed interface RutaCliente {
    data object CrearPedido : RutaCliente
    data class Detalle(val id: Int) : RutaCliente
    data class EditarDireccion(val direccion: DireccionDto?) : RutaCliente
}

private val PESTANAS = listOf(
    Pestana("menu", "Menú", Icons.Default.RestaurantMenu),
    Pestana("pedidos", "Pedidos", Icons.Default.Receipt),
    Pestana("perfil", "Perfil", Icons.Default.Person)
)

@Composable
fun ClienteHome(sesion: SesionViewModel) {
    val vm: ClienteViewModel = viewModel()
    var pestana by rememberSaveable { mutableStateOf("menu") }
    val pila = remember { mutableStateListOf<RutaCliente>() }
    val ir: (RutaCliente) -> Unit = { pila.add(it) }
    val volver: () -> Unit = { if (pila.isNotEmpty()) pila.removeAt(pila.lastIndex) }
    BackHandler(enabled = pila.isNotEmpty()) { volver() }

    val horaCorte = sesion.configuracion?.hora_corte

    when (val ruta = pila.lastOrNull()) {
        is RutaCliente.CrearPedido -> CrearPedidoScreen(vm, horaCorte, onBack = volver,
            onAgregarDireccion = { ir(RutaCliente.EditarDireccion(null)) },
            onCreado = { p -> pila.clear(); pestana = "pedidos"; ir(RutaCliente.Detalle(p.id)) })
        is RutaCliente.Detalle -> DetallePedidoScreen(vm, ruta.id, onBack = volver, onPedirDeNuevo = { pila.clear(); ir(RutaCliente.CrearPedido) })
        is RutaCliente.EditarDireccion -> EditarDireccionScreen(vm, ruta.direccion, onBack = volver)
        null -> Scaffold(
            containerColor = FondoApp,
            bottomBar = { BarraInferior(PESTANAS, pestana) { pestana = it } }
        ) { padding ->
            Box(Modifier.padding(bottom = padding.calculateBottomPadding())) {
                when (pestana) {
                    "menu" -> MenuSemanaScreen(vm, horaCorte, onPedir = { ir(RutaCliente.CrearPedido) })
                    "pedidos" -> PedidosScreen(vm, onNuevo = { ir(RutaCliente.CrearPedido) }, onAbrir = { ir(RutaCliente.Detalle(it)) })
                    else -> PerfilScreen(sesion) {
                        DireccionesSeccion(vm, onAgregar = { ir(RutaCliente.EditarDireccion(null)) }, onEditar = { ir(RutaCliente.EditarDireccion(it)) })
                    }
                }
            }
        }
    }
}

/** Menú de la semana que se va a pedir (desde menu-semanal) con el promedio de calificaciones. */
@Composable
fun MenuSemanaScreen(vm: ClienteViewModel, horaCorte: String?, onPedir: () -> Unit) {
    LaunchedEffect(Unit) { vm.cargarSemana() }
    var vista by rememberVistaPlatillos()
    Column(Modifier.fillMaxSize().background(FondoApp)) {
        BarraSuperior("Menú de la semana")
        CargaView(vm.semana, onReintentar = vm::cargarSemana) { semana ->
            LazyColumn(contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                item { EncabezadoEntrega(semana.entrega.fecha_entrega, semana.entrega.cierra_en) }
                item {
                    val p = vm.promedioMenu
                    if (p != null) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Icon(Icons.Default.Star, contentDescription = null, tint = Mostaza)
                            Spacer(Modifier.width(4.dp))
                            Text(
                                if (p.promedio != null) "%.1f de 5 · %d calificaciones esta semana".format(p.promedio, p.calificaciones ?: 0)
                                else "El menú de esta semana aún no tiene calificaciones",
                                color = TextoSuave, fontSize = 14.sp
                            )
                        }
                    }
                }
                item { BotonPrincipal("Armar mi pedido", onPedir) }
                val dias = semana.entrega.dias_de_comida
                val sinMenu = dias.isNotEmpty() && dias.all { semana.menu[it]?.celdas.isNullOrEmpty() }
                if (dias.isEmpty()) item { AvisoInfo("Todavía no hay días de comida disponibles.") }
                if (sinMenu) item { AvisoInfo("Aún no se publica el menú de esta semana. Vuelve más tarde para armar tu pedido.") }
                if (!sinMenu && dias.isNotEmpty()) item { SelectorVista(vista, { vista = it }, Modifier.fillMaxWidth()) }
                items(dias) { fecha -> DiaMenu(fecha, semana.menu[fecha]?.celdas.orEmpty(), vista) }
                item { Spacer(Modifier.height(80.dp)) }
            }
        }
    }
}

private val TIEMPOS_MENU = listOf("desayuno", "comida", "cena")

@Composable
private fun DiaMenu(fecha: String, celdas: Map<String, com.example.easylunch.data.model.CeldaMenuDto>, vista: VistaPlatillos) {
    Tarjeta {
        Text(Formato.diaCorto(fecha), fontWeight = FontWeight.Bold, color = TextoTitulo, fontSize = 17.sp)
        Spacer(Modifier.height(8.dp))
        if (celdas.isEmpty()) { Text("Sin platillos publicados este día", color = TextoSuave, fontSize = 14.sp); return@Tarjeta }
        when (vista) {
            VistaPlatillos.LISTA -> TIEMPOS_MENU.forEach { t ->
                val c = celdas[t]
                Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                    FotoPlatillo(c?.foto_url, Modifier.size(48.dp))
                    Spacer(Modifier.width(10.dp))
                    Column(Modifier.weight(1f)) {
                        Text(Formato.tiempoComida(t), color = TextoSuave, fontSize = 12.sp)
                        Text(c?.platillo_nombre ?: "Sin platillo este día", color = if (c != null) TextoTitulo else TextoSuave)
                    }
                    c?.let { Text(Formato.dinero(it.precio_venta), color = Color(0xFF8A6100), fontSize = 13.sp) }
                }
            }
            VistaPlatillos.CUADRICULA -> Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                TIEMPOS_MENU.forEach { t ->
                    val c = celdas[t]
                    Column(Modifier.weight(1f)) {
                        FotoPlatillo(c?.foto_url, Modifier.fillMaxWidth().aspectRatio(1f))
                        Text(Formato.tiempoComida(t), color = TextoSuave, fontSize = 11.sp)
                        Text(c?.platillo_nombre ?: "—", color = TextoTitulo, fontSize = 13.sp, maxLines = 2)
                        c?.let { Text(Formato.dinero(it.precio_venta), color = Color(0xFF8A6100), fontSize = 11.sp) }
                    }
                }
            }
            VistaPlatillos.GRANDE -> TIEMPOS_MENU.forEach { t ->
                val c = celdas[t] ?: return@forEach
                Column(Modifier.padding(vertical = 6.dp)) {
                    FotoPlatillo(c.foto_url, Modifier.fillMaxWidth().height(170.dp))
                    Row(Modifier.padding(top = 6.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text(Formato.tiempoComida(t), color = TextoSuave, fontSize = 12.sp)
                            Text(c.platillo_nombre, fontWeight = FontWeight.Bold, color = TextoTitulo, fontSize = 16.sp)
                        }
                        Text(Formato.dinero(c.precio_venta), color = Color(0xFF8A6100))
                    }
                }
            }
        }
    }
}

@Composable
fun EncabezadoEntrega(fechaEntrega: String, cierraEn: String?) {
    Tarjeta {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(Icons.Default.LocalShipping, contentDescription = null, tint = Mostaza)
            Spacer(Modifier.width(8.dp))
            Text("Se entrega el ${Formato.diaLargo(fechaEntrega)}", fontWeight = FontWeight.Bold, color = TextoTitulo)
        }
        if (cierraEn != null) {
            Spacer(Modifier.height(4.dp))
            Text("Cierra el ${Formato.momentoLargo(cierraEn)}", color = TextoSuave)
            CuentaRegresiva(Formato.momento(cierraEn)?.toInstant(), "Cierra en", color = Color(0xFF8A6100))
        }
    }
}

@Composable
fun DireccionesSeccion(vm: ClienteViewModel, onAgregar: () -> Unit, onEditar: (DireccionDto) -> Unit) {
    LaunchedEffect(Unit) { vm.cargarDirecciones() }
    var aBorrar by remember { mutableStateOf<DireccionDto?>(null) }
    Text("Mis direcciones", fontWeight = FontWeight.Bold, fontSize = 18.sp, color = TextoTitulo)
    Spacer(Modifier.height(8.dp))
    AvisoError(vm.errorDirecciones)
    when (val c = vm.direcciones) {
        is Carga.Cargando -> Box(Modifier.fillMaxWidth().padding(16.dp), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = Mostaza) }
        is Carga.Falla -> {
            AvisoError(c.error.mensaje)
            TextButton(onClick = vm::cargarDirecciones) { Text("Reintentar") }
        }
        is Carga.Listo -> {
            if (c.datos.isEmpty()) Text("Aún no tienes direcciones. Agrega una para poder pedir.", color = TextoSuave)
            c.datos.forEach { d ->
                Tarjeta(Modifier.padding(vertical = 4.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Text(d.etiqueta ?: "Dirección", fontWeight = FontWeight.Bold, color = TextoTitulo)
                                if (d.predeterminada == true) { Spacer(Modifier.width(6.dp)); Etiqueta("Predeterminada") }
                            }
                            Text(d.direccion + (d.numero_interior?.let { " int. $it" } ?: ""), color = TextoSuave, fontSize = 14.sp)
                            Spacer(Modifier.height(4.dp))
                            if (d.fueraDeCobertura) Etiqueta("Fuera de cobertura · no sirve para pedir", Color(0xFFFDECEA), RojoError)
                            else d.zona?.let { Etiqueta("Zona: $it", Color(0xFFE8F5E9), VerdeOk) }
                        }
                        IconButton(onClick = { onEditar(d) }) { Icon(Icons.Default.Edit, contentDescription = "Editar") }
                        IconButton(onClick = { aBorrar = d }) { Icon(Icons.Default.Delete, contentDescription = "Eliminar", tint = RojoError) }
                    }
                }
            }
        }
    }
    Spacer(Modifier.height(8.dp))
    OutlinedButton(onClick = onAgregar, modifier = Modifier.fillMaxWidth()) {
        Icon(Icons.Default.AddLocationAlt, contentDescription = null); Spacer(Modifier.width(8.dp)); Text("Agregar dirección")
    }
    aBorrar?.let { d ->
        DialogoConfirmar("Eliminar dirección", "¿Eliminar \"${d.etiqueta ?: d.direccion}\"?", "Eliminar",
            onConfirmar = { aBorrar = null; vm.eliminarDireccion(d.id) }, onCancelar = { aBorrar = null })
    }
}
