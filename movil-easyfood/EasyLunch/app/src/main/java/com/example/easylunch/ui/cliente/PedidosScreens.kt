package com.example.easylunch.ui.cliente

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.PhotoCamera
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Receipt
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.easylunch.BuildConfig
import com.example.easylunch.data.model.*
import com.example.easylunch.ui.auth.CampoTexto
import com.example.easylunch.ui.common.*
import com.stripe.android.PaymentConfiguration
import com.stripe.android.paymentsheet.PaymentSheet
import com.stripe.android.paymentsheet.PaymentSheetResult
import com.stripe.android.paymentsheet.rememberPaymentSheet
import java.time.Instant

private fun colorEstado(e: String): Pair<Color, Color> = when (e) {
    "pendiente_pago" -> Color(0xFFFFF4D6) to Color(0xFF8A6100)
    "recibido", "activo" -> Color(0xFFE3F2FD) to Color(0xFF1565C0)
    "completado" -> Color(0xFFE8F5E9) to VerdeOk
    "cancelado" -> Color(0xFFFDECEA) to RojoError
    else -> Color(0xFFEEEEEE) to TextoSuave
}

@Composable
private fun EtiquetaEstado(estado: String) {
    val (f, c) = colorEstado(estado)
    Etiqueta(Formato.estadoPedido(estado), f, c)
}

private fun venceEn(p: PedidoDto, leidoEn: Long): Instant? =
    if (p.estado != "pendiente_pago") null
    else Formato.momento(p.vence_pago_en)?.toInstant()
        ?: p.segundos_para_pagar?.let { Instant.ofEpochMilli(leidoEn).plusSeconds(it) }

@Composable
fun PedidosScreen(vm: ClienteViewModel, onNuevo: () -> Unit, onAbrir: (Int) -> Unit) {
    LaunchedEffect(Unit) { vm.cargarPedidos() }
    val leido = remember(vm.pedidos) { System.currentTimeMillis() }
    Scaffold(
        containerColor = FondoApp,
        topBar = { BarraSuperior("Mis pedidos", acciones = { IconButton(onClick = vm::cargarPedidos) { Icon(Icons.Default.Refresh, "Actualizar") } }) },
        floatingActionButton = {
            ExtendedFloatingActionButton(onClick = onNuevo, containerColor = Mostaza, contentColor = Color.Black,
                icon = { Icon(Icons.Default.Add, null) }, text = { Text("Nuevo pedido", fontWeight = FontWeight.Bold) })
        }
    ) { padding ->
        CargaView(
            vm.pedidos, onReintentar = vm::cargarPedidos, modifier = Modifier.padding(padding),
            esVacio = { it.isEmpty() },
            vacio = { PantallaVacia("Aún no tienes pedidos", "Arma tu pedido de la semana: llega todo junto el sábado.", Modifier.padding(padding), Icons.Default.Receipt) }
        ) { lista ->
            LazyColumn(Modifier.padding(padding), contentPadding = PaddingValues(16.dp, 16.dp, 16.dp, 96.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                items(lista, key = { it.id }) { p ->
                    Tarjeta(onClick = { onAbrir(p.id) }) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(p.folio ?: "Pedido ${p.id}", fontWeight = FontWeight.Bold, color = TextoTitulo, modifier = Modifier.weight(1f))
                            EtiquetaEstado(p.estado)
                        }
                        Spacer(Modifier.height(4.dp))
                        p.plan_nombre?.let { Text(it, color = TextoSuave, fontSize = 14.sp) }
                        Text("Entrega: ${Formato.diaLargo(p.fecha_entrega)}", color = TextoSuave, fontSize = 14.sp)
                        FilaDato("Total", Formato.dinero(p.total), negrita = true)
                        venceEn(p, leido)?.let { CuentaRegresiva(it, "Paga antes de:") }
                    }
                }
            }
        }
    }
}

@Composable
fun DetallePedidoScreen(vm: ClienteViewModel, id: Int, onBack: () -> Unit, onPedirDeNuevo: () -> Unit) {
    val context = LocalContext.current
    LaunchedEffect(id) { vm.reiniciarPago(); vm.limpiarCalificacion(); vm.cargarDetalle(id) }
    DisposableEffect(id) { onDispose { vm.reiniciarPago() } }

    val hoja = rememberPaymentSheet { r ->
        when (r) {
            is PaymentSheetResult.Completed -> vm.esperarConfirmacion(id)
            is PaymentSheetResult.Canceled -> vm.pagoCancelado()
            is PaymentSheetResult.Failed -> vm.pagoFallido(r.error.localizedMessage ?: "No se pudo completar el pago.")
        }
    }
    val pagar: () -> Unit = {
        val clave = BuildConfig.STRIPE_PUBLISHABLE_KEY
        if (clave.isBlank()) vm.pagoFallido("El pago con tarjeta no está configurado en esta versión de la app.")
        else vm.iniciarPago(id) { secreto ->
            PaymentConfiguration.init(context, clave)
            hoja.presentWithPaymentIntent(secreto, PaymentSheet.Configuration(merchantDisplayName = "EasyLunch"))
        }
    }
    var calificarEntrega by remember { mutableStateOf(false) }
    var calificarPlatillo by remember { mutableStateOf<Pair<String, PedidoPlatilloDto>?>(null) }

    Column(Modifier.fillMaxSize().background(FondoApp)) {
        BarraSuperior("Detalle del pedido", onBack = onBack, acciones = {
            IconButton(onClick = { vm.cargarDetalle(id) }) { Icon(Icons.Default.Refresh, "Actualizar") }
        })
        CargaView(vm.detalle, onReintentar = { vm.cargarDetalle(id) }) { p ->
            var vencido by remember(p) { mutableStateOf(false) }
            val vence = venceEn(p, vm.detalleLeidoEn)
            Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Tarjeta {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(p.folio ?: "Pedido ${p.id}", fontWeight = FontWeight.Bold, fontSize = 18.sp, color = TextoTitulo, modifier = Modifier.weight(1f))
                        EtiquetaEstado(p.estado)
                    }
                    Spacer(Modifier.height(8.dp))
                    p.plan_nombre?.let { FilaDato("Paquete", it) }
                    FilaDato("Entrega", Formato.diaLargo(p.fecha_entrega))
                    p.dias?.firstOrNull()?.estado?.let { FilaDato("Estado de la entrega", Formato.estadoEntrega(it)) }
                    p.zona?.let { FilaDato("Zona", it) }
                    p.direccion_entrega?.let { FilaDato("Dirección", it) }
                    p.referencias?.let { FilaDato("Referencias", it) }
                    if (p.tiene_evidencia == true) {
                        Spacer(Modifier.height(4.dp))
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Icon(Icons.Default.PhotoCamera, null, tint = VerdeOk, modifier = Modifier.size(18.dp))
                            Spacer(Modifier.width(6.dp))
                            Text("El repartidor dejó foto de evidencia de la entrega.", color = VerdeOk, fontSize = 14.sp)
                        }
                    }
                }

                Tarjeta {
                    FilaDato("Total", Formato.dinero(p.total), negrita = true)
                    p.metodo_pago?.let { FilaDato("Pago", it.replaceFirstChar { c -> c.uppercase() } + (p.ultimos_digitos?.let { d -> " · •••• $d" } ?: "")) }
                    if (p.reembolsado_en != null || p.monto_reembolsado != null) {
                        FilaDato("Reembolsado", Formato.momentoLargo(p.reembolsado_en))
                        FilaDato("Monto reembolsado", Formato.dinero(p.monto_reembolsado))
                    }
                    if (p.estado == "pendiente_pago") {
                        Spacer(Modifier.height(8.dp))
                        p.vence_pago_en?.let { Text("Paga antes del ${Formato.momentoLargo(it)}. Si no, el pedido se cancela.", color = TextoSuave, fontSize = 14.sp) }
                        CuentaRegresiva(vence, "Tiempo para pagar:", alVencer = { vencido = true })
                        Spacer(Modifier.height(8.dp))
                        when (val estado = vm.pago) {
                            is EstadoPago.Confirmando -> AvisoInfo("Confirmando tu pago con el banco…")
                            is EstadoPago.Error -> AvisoError(estado.mensaje)
                            else -> {}
                        }
                        Spacer(Modifier.height(8.dp))
                        BotonPrincipal(
                            if (vencido) "Plazo vencido" else "Pagar con tarjeta",
                            onClick = pagar,
                            habilitado = !vencido && vm.pago !is EstadoPago.Confirmando,
                            cargando = vm.pago is EstadoPago.Preparando
                        )
                        if (vencido) {
                            Text("El plazo para pagar terminó: haz el pedido de nuevo.", color = RojoError, fontSize = 14.sp)
                            TextButton(onClick = onPedirDeNuevo) { Text("Hacer un pedido nuevo") }
                        }
                    }
                    if (vm.pago is EstadoPago.Confirmado) AvisoOk("¡Pago confirmado! Tu pedido quedó recibido.")
                }

                if (ClienteViewModel.sePuedeCalificar(p)) {
                    OutlinedButton(onClick = { vm.limpiarCalificacion(); calificarEntrega = true }, modifier = Modifier.fillMaxWidth()) {
                        Text("Calificar la entrega")
                    }
                }

                Text("Días de comida", fontWeight = FontWeight.Bold, fontSize = 17.sp, color = TextoTitulo)
                val diasPedido = p.dias.orEmpty()
                if (diasPedido.isEmpty()) Text("—", color = TextoSuave)
                diasPedido.forEach { d ->
                    Tarjeta {
                        Text(Formato.diaCorto(d.fecha), fontWeight = FontWeight.Bold, color = TextoTitulo)
                        d.platillos.orEmpty().forEach { pl ->
                            Row(Modifier.fillMaxWidth().padding(vertical = 3.dp), verticalAlignment = Alignment.CenterVertically) {
                                Text(if (pl.es_extra == true) "Extra" else Formato.tiempoComida(pl.tiempo_comida),
                                    color = TextoSuave, fontSize = 14.sp, modifier = Modifier.width(84.dp))
                                Text(pl.platillo_nombre ?: "—", modifier = Modifier.weight(1f), color = TextoTitulo)
                                Text(Formato.dinero(pl.precio_congelado), fontSize = 13.sp, color = Color(0xFF8A6100))
                            }
                            if (ClienteViewModel.sePuedeCalificar(p) && pl.platillo_id != null && d.fecha != null) {
                                TextButton(onClick = { vm.limpiarCalificacion(); calificarPlatillo = d.fecha to pl }, contentPadding = PaddingValues(0.dp)) {
                                    Text("Calificar este platillo", fontSize = 13.sp)
                                }
                            }
                        }
                    }
                }
                Spacer(Modifier.height(24.dp))
            }

            if (calificarEntrega) DialogoCalificarEntrega(vm, onCerrar = { calificarEntrega = false }) { punt, trato, emp, com ->
                vm.calificarEntrega(p.id, CalificarEntregaRequest(p.fecha_entrega ?: "", punt, trato, emp, com.ifBlank { null }))
            }
            calificarPlatillo?.let { (fecha, pl) ->
                DialogoCalificarPlatillo(vm, pl.platillo_nombre ?: "Platillo", onCerrar = { calificarPlatillo = null }) { estrellas, com ->
                    vm.calificarPlatillo(p.id, CalificarPlatilloClienteRequest(fecha, pl.platillo_id!!, estrellas, com.ifBlank { null }))
                }
            }
        }
    }
}

/** Calificar puntualidad, trato y empaque (lo usan cliente y empleado). */
@Composable
fun DialogoCalificarEntrega(
    calificando: Boolean, resultado: Pair<Boolean, String>?,
    onCerrar: () -> Unit, onEnviar: (Int, Int, Int, String) -> Unit
) {
    var punt by remember { mutableIntStateOf(0) }
    var trato by remember { mutableIntStateOf(0) }
    var emp by remember { mutableIntStateOf(0) }
    var com by remember { mutableStateOf("") }
    val exito = resultado?.first == true
    AlertDialog(
        onDismissRequest = onCerrar,
        title = { Text("Calificar la entrega") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                if (!exito) {
                    Estrellas(punt, { punt = it }, "Puntualidad")
                    Estrellas(trato, { trato = it }, "Trato del repartidor")
                    Estrellas(emp, { emp = it }, "Empaque")
                    CampoTexto(com, { if (it.length <= 500) com = it }, "Comentario (opcional)", lineas = 2)
                }
                resultado?.let { (ok, msg) -> if (ok) AvisoOk(msg) else AvisoError(msg) }
            }
        },
        confirmButton = {
            if (exito) TextButton(onClick = onCerrar) { Text("Listo") }
            else TextButton(onClick = { onEnviar(punt, trato, emp, com) }, enabled = punt > 0 && trato > 0 && emp > 0 && !calificando) {
                Text(if (calificando) "Enviando…" else "Enviar")
            }
        },
        dismissButton = { if (!exito) TextButton(onClick = onCerrar) { Text("Cancelar") } }
    )
}

@Composable
fun DialogoCalificarPlatillo(
    nombre: String, calificando: Boolean, resultado: Pair<Boolean, String>?,
    onCerrar: () -> Unit, onEnviar: (Int, String) -> Unit
) {
    var estrellas by remember { mutableIntStateOf(0) }
    var com by remember { mutableStateOf("") }
    val exito = resultado?.first == true
    AlertDialog(
        onDismissRequest = onCerrar,
        title = { Text("Calificar: $nombre") },
        text = {
            Column {
                if (!exito) {
                    Estrellas(estrellas, { estrellas = it }, "Sabor y calidad")
                    CampoTexto(com, { if (it.length <= 500) com = it }, "Comentario (opcional)", lineas = 2)
                }
                resultado?.let { (ok, msg) -> if (ok) AvisoOk(msg) else AvisoError(msg) }
            }
        },
        confirmButton = {
            if (exito) TextButton(onClick = onCerrar) { Text("Listo") }
            else TextButton(onClick = { onEnviar(estrellas, com) }, enabled = estrellas > 0 && !calificando) {
                Text(if (calificando) "Enviando…" else "Enviar")
            }
        },
        dismissButton = { if (!exito) TextButton(onClick = onCerrar) { Text("Cancelar") } }
    )
}

@Composable
private fun DialogoCalificarEntrega(vm: ClienteViewModel, onCerrar: () -> Unit, onEnviar: (Int, Int, Int, String) -> Unit) =
    DialogoCalificarEntrega(vm.calificando, vm.resultadoCalificacion, onCerrar, onEnviar)

@Composable
private fun DialogoCalificarPlatillo(vm: ClienteViewModel, nombre: String, onCerrar: () -> Unit, onEnviar: (Int, String) -> Unit) =
    DialogoCalificarPlatillo(nombre, vm.calificando, vm.resultadoCalificacion, onCerrar, onEnviar)
