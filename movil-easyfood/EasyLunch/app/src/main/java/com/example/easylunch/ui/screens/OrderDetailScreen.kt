package com.example.easylunch.ui.screens

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.ReceiptLong
import androidx.compose.material.icons.filled.CalendarToday
import androidx.compose.material.icons.filled.Cancel
import androidx.compose.material.icons.filled.Inventory2
import androidx.compose.material.icons.filled.LocationOn
import androidx.compose.material.icons.filled.Payment
import androidx.compose.material.icons.filled.RestaurantMenu
import androidx.compose.material.icons.filled.Star
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.easylunch.data.model.AddressDto
import com.example.easylunch.data.model.MenuDto
import com.example.easylunch.data.model.PedidoDto

private data class PlatilloCalificableItem(
    val fecha: String,
    val platilloId: Int,
    val nombre: String,
    val etiquetaTiempo: String?
)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun OrderDetailScreen(
    pedido: PedidoDto,
    addresses: List<AddressDto>,
    onBack: () -> Unit,
    onPayClick: (PedidoDto) -> Unit,
    onCancelClick: (PedidoDto) -> Unit,
    modifier: Modifier = Modifier,
    menus: List<MenuDto> = emptyList(),
    ratingStatusMessage: String = "",
    onClearRatingStatus: () -> Unit = {},
    onRateDelivery: (fecha: String, puntualidad: Int, tratoChofer: Int, empaque: Int, comentario: String?) -> Unit = { _, _, _, _, _ -> },
    onRateDish: (fecha: String, platilloId: Int, estrellas: Int, comentario: String?) -> Unit = { _, _, _, _ -> }
) {
    val mustardYellow = Color(0xFFFFB300)
    val darkTitle = Color(0xFF1A1A1A)
    val darkSub = Color(0xFF4A4A4A)
    var showCancelDialog by remember { mutableStateOf(false) }
    var showRatingDialog by remember { mutableStateOf(false) }

    val matchedAddress = pedido.direccion
        ?: addresses.find { it.id == pedido.direccion_id }
        ?: pedido.direccion_entrega?.takeIf { it.isNotBlank() }?.let { calle ->
            addresses.find { it.street_address.equals(calle, ignoreCase = true) }
        }

    val nombrePaquete = pedido.paquete?.nombre?.takeIf { it.isNotBlank() }
        ?: pedido.plan_nombre?.takeIf { it.isNotBlank() }
        ?: "Paquete Estándar"

    val diasPaquete = (pedido.paquete?.dias ?: 1).coerceAtLeast(1)
    val comidasPorDiaEsperadas = pedido.comidas_por_dia ?: 3
    val estadoPedido = pedido.estado

    val diasEntregados = remember(pedido) {
        pedido.dias.orEmpty().filter { it.estado.equals("entregada", ignoreCase = true) }
    }
    val puedeCalificar = diasEntregados.isNotEmpty() || estadoPedido.equals("recibido", ignoreCase = true)

    val fechasEntregadas = remember(pedido, diasEntregados) {
        val desdeDias = diasEntregados.mapNotNull { it.fecha?.takeIf(String::isNotBlank) }.distinct()
        if (desdeDias.isNotEmpty()) {
            desdeDias
        } else {
            listOfNotNull(pedido.fecha_entrega?.takeIf(String::isNotBlank), pedido.fecha_inicio?.takeIf(String::isNotBlank))
                .distinct()
        }
    }

    val platillosCalificables = remember(pedido, menus) {
        buildList {
            pedido.dias.orEmpty().forEach { dia ->
                val fechaDia = dia.fecha?.takeIf(String::isNotBlank) ?: pedido.fecha_entrega.orEmpty()
                if (fechaDia.isNotBlank()) {
                    dia.platillos.orEmpty().forEach { item ->
                        val id = item.platillo_id
                        if (id != null) {
                            val nombre = item.platillo_nombre?.takeIf { it.isNotBlank() }
                                ?: menus.find { it.id == id }?.name
                                ?: "Platillo #$id"
                            add(PlatilloCalificableItem(fechaDia, id, nombre, item.tiempo_comida))
                        }
                    }
                    if (dia.platillos.isNullOrEmpty()) {
                        val idPlano = dia.resolvedPlatilloId
                        if (idPlano != null) {
                            val nombrePlano = dia.resolvedPlatilloNombre?.takeIf { it.isNotBlank() }
                                ?: menus.find { it.id == idPlano }?.name
                                ?: "Platillo #$idPlano"
                            add(PlatilloCalificableItem(fechaDia, idPlano, nombrePlano, null))
                        }
                    }
                }
            }
        }.distinctBy { "${it.fecha}_${it.platilloId}" }
    }

    if (showCancelDialog) {
        AlertDialog(
            onDismissRequest = { showCancelDialog = false },
            containerColor = Color.White,
            title = {
                Text("Cancelar pedido", fontWeight = FontWeight.Bold, color = darkTitle)
            },
            text = {
                Text(
                    "¿Seguro que deseas cancelar el pedido ${pedido.folio ?: "#${pedido.id}"}? Esta acción liberará las porciones asignadas.",
                    color = darkSub,
                    fontSize = 14.sp
                )
            },
            confirmButton = {
                Button(
                    onClick = {
                        showCancelDialog = false
                        onCancelClick(pedido)
                    },
                    colors = ButtonDefaults.buttonColors(containerColor = Color(0xFFC62828))
                ) {
                    Text("Sí, cancelar", color = Color.White, fontWeight = FontWeight.Bold)
                }
            },
            dismissButton = {
                TextButton(onClick = { showCancelDialog = false }) {
                    Text("Mantener", color = darkSub)
                }
            }
        )
    }

    if (showRatingDialog) {
        var modoTab by remember { mutableIntStateOf(0) } // 0 = Entrega, 1 = Platillo
        var fechaSeleccionada by remember { mutableStateOf(fechasEntregadas.firstOrNull() ?: "") }
        var puntualidad by remember { mutableIntStateOf(5) }
        var tratoChofer by remember { mutableIntStateOf(5) }
        var empaque by remember { mutableIntStateOf(5) }
        var platilloSeleccionado by remember { mutableStateOf(platillosCalificables.firstOrNull()) }
        var estrellasPlatillo by remember { mutableIntStateOf(5) }
        var comentario by remember { mutableStateOf("") }

        AlertDialog(
            onDismissRequest = {
                showRatingDialog = false
                onClearRatingStatus()
            },
            containerColor = Color.White,
            title = {
                Text("Calificar pedido", fontWeight = FontWeight.Bold, color = darkTitle)
            },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        FilterChip(
                            selected = modoTab == 0,
                            onClick = { modoTab = 0 },
                            label = { Text("Entrega") }
                        )
                        if (platillosCalificables.isNotEmpty()) {
                            FilterChip(
                                selected = modoTab == 1,
                                onClick = { modoTab = 1 },
                                label = { Text("Platillo") }
                            )
                        }
                    }

                    if (fechasEntregadas.isNotEmpty()) {
                        Text("Fecha:", fontSize = 12.sp, fontWeight = FontWeight.Bold, color = darkTitle)
                        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                            fechasEntregadas.forEach { fecha ->
                                FilterChip(
                                    selected = fechaSeleccionada == fecha,
                                    onClick = { fechaSeleccionada = fecha },
                                    label = { Text(fecha, fontSize = 11.sp) }
                                )
                            }
                        }
                    } else {
                        OutlinedTextField(
                            value = fechaSeleccionada,
                            onValueChange = { fechaSeleccionada = it },
                            label = { Text("Fecha (AAAA-MM-DD)") },
                            singleLine = true,
                            modifier = Modifier.fillMaxWidth()
                        )
                    }

                    if (modoTab == 0) {
                        EstrellasSelector("Puntualidad", puntualidad) { puntualidad = it }
                        EstrellasSelector("Trato del chofer", tratoChofer) { tratoChofer = it }
                        EstrellasSelector("Empaque", empaque) { empaque = it }
                    } else {
                        platillosCalificables.forEach { item ->
                            val isSelected = platilloSeleccionado == item
                            FilterChip(
                                selected = isSelected,
                                onClick = {
                                    platilloSeleccionado = item
                                    fechaSeleccionada = item.fecha
                                },
                                label = { Text(item.nombre, fontSize = 12.sp) }
                            )
                        }
                        EstrellasSelector("Calificación del platillo", estrellasPlatillo) { estrellasPlatillo = it }
                    }

                    OutlinedTextField(
                        value = comentario,
                        onValueChange = { comentario = it },
                        label = { Text("Comentario (opcional)") },
                        modifier = Modifier.fillMaxWidth()
                    )

                    if (ratingStatusMessage.isNotBlank()) {
                        Text(
                            text = ratingStatusMessage,
                            fontSize = 12.sp,
                            fontWeight = FontWeight.SemiBold,
                            color = darkTitle
                        )
                    }
                }
            },
            confirmButton = {
                Button(
                    onClick = {
                        if (modoTab == 0) {
                            if (fechaSeleccionada.isNotBlank()) {
                                onRateDelivery(fechaSeleccionada, puntualidad, tratoChofer, empaque, comentario)
                            }
                        } else {
                            val platillo = platilloSeleccionado
                            if (platillo != null) {
                                val fechaEnvio = platillo.fecha.ifBlank { fechaSeleccionada }
                                onRateDish(fechaEnvio, platillo.platilloId, estrellasPlatillo, comentario)
                            }
                        }
                    },
                    colors = ButtonDefaults.buttonColors(containerColor = mustardYellow, contentColor = Color.Black)
                ) {
                    Text("Enviar", fontWeight = FontWeight.Bold)
                }
            },
            dismissButton = {
                TextButton(onClick = {
                    showRatingDialog = false
                    onClearRatingStatus()
                }) {
                    Text("Cerrar", color = darkSub)
                }
            }
        )
    }

    Scaffold(
        modifier = modifier.fillMaxSize(),
        containerColor = Color(0xFFFAFAFA),
        topBar = {
            TopAppBar(
                title = {
                    Text(
                        text = pedido.folio ?: "Detalle del Pedido #${pedido.id}",
                        fontSize = 19.sp,
                        fontWeight = FontWeight.Bold,
                        color = darkTitle
                    )
                },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(
                            imageVector = Icons.AutoMirrored.Filled.ArrowBack,
                            contentDescription = "Regresar",
                            tint = darkTitle
                        )
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Color.White)
            )
        }
    ) { innerPadding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding)
                .padding(16.dp)
        ) {
            LazyColumn(
                modifier = Modifier
                    .weight(1f)
                    .fillMaxWidth(),
                verticalArrangement = Arrangement.spacedBy(12.dp)
            ) {
                // Estado y vigencia
                item {
                    Card(
                        shape = RoundedCornerShape(16.dp),
                        colors = CardDefaults.cardColors(containerColor = Color.White),
                        elevation = CardDefaults.cardElevation(defaultElevation = 2.dp),
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Column(modifier = Modifier.padding(16.dp)) {
                            Row(
                                modifier = Modifier.fillMaxWidth(),
                                horizontalArrangement = Arrangement.SpaceBetween,
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                DetailHeader(
                                    icon = Icons.AutoMirrored.Filled.ReceiptLong,
                                    title = "Estado del Pedido",
                                    color = darkTitle
                                )
                                Surface(
                                    shape = RoundedCornerShape(8.dp),
                                    color = when (estadoPedido) {
                                        "pendiente_pago" -> Color(0xFFFFEBEE)
                                        "cancelado" -> Color(0xFFEEEEEE)
                                        else -> mustardYellow.copy(alpha = 0.22f)
                                    }
                                ) {
                                    Text(
                                        text = estadoPedido.replace("_", " ").uppercase(),
                                        modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp),
                                        fontSize = 11.sp,
                                        fontWeight = FontWeight.Bold,
                                        color = when (estadoPedido) {
                                            "pendiente_pago" -> Color(0xFFC62828)
                                            "cancelado" -> Color(0xFF616161)
                                            else -> Color(0xFF8F6B00)
                                        }
                                    )
                                }
                            }
                            Spacer(modifier = Modifier.height(10.dp))
                            pedido.fecha_entrega?.takeIf { it.isNotBlank() }?.let { entrega ->
                                Row(verticalAlignment = Alignment.CenterVertically) {
                                    Icon(Icons.Default.CalendarToday, contentDescription = null, tint = darkSub, modifier = Modifier.size(16.dp))
                                    Spacer(modifier = Modifier.width(6.dp))
                                    Text(
                                        text = "Entrega programada: $entrega",
                                        fontSize = 13.sp,
                                        fontWeight = FontWeight.SemiBold,
                                        color = darkTitle
                                    )
                                }
                                Spacer(modifier = Modifier.height(4.dp))
                            }
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Icon(Icons.Default.CalendarToday, contentDescription = null, tint = darkSub, modifier = Modifier.size(16.dp))
                                Spacer(modifier = Modifier.width(6.dp))
                                Text(
                                    text = "Comidas del: ${pedido.fecha_inicio ?: "-"} al ${pedido.fecha_fin ?: "-"}",
                                    fontSize = 13.sp,
                                    color = darkSub
                                )
                            }
                            if (estadoPedido == "pendiente_pago" && pedido.segundos_para_pagar != null) {
                                Spacer(modifier = Modifier.height(6.dp))
                                Text(
                                    text = "Tiempo restante para pagar: ${(pedido.segundos_para_pagar / 60).coerceAtLeast(0)} minutos",
                                    fontSize = 12.sp,
                                    fontWeight = FontWeight.Bold,
                                    color = Color(0xFFC62828)
                                )
                            }
                            if (!pedido.metodo_pago.isNullOrBlank()) {
                                Spacer(modifier = Modifier.height(6.dp))
                                val digits = pedido.ultimos_digitos?.let { " •••• $it" } ?: ""
                                Text(
                                    text = "Pago: ${pedido.metodo_pago.uppercase()}$digits",
                                    fontSize = 12.sp,
                                    fontWeight = FontWeight.SemiBold,
                                    color = Color(0xFF2E7D32)
                                )
                            }
                        }
                    }
                }

                // Paquete contratado
                item {
                    Card(
                        shape = RoundedCornerShape(16.dp),
                        colors = CardDefaults.cardColors(containerColor = Color.White),
                        elevation = CardDefaults.cardElevation(defaultElevation = 2.dp),
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Column(modifier = Modifier.padding(16.dp)) {
                            DetailHeader(Icons.Default.Inventory2, "Paquete Contratado", darkTitle)
                            Spacer(modifier = Modifier.height(8.dp))
                            Text(
                                text = nombrePaquete,
                                fontWeight = FontWeight.Bold,
                                fontSize = 15.sp,
                                color = darkTitle
                            )
                            Text(
                                text = "$diasPaquete ${if (diasPaquete == 1) "día" else "días"} • ${pedido.paquete?.descuento_porcentaje ?: 0.0}% de descuento • $comidasPorDiaEsperadas comida(s)/día",
                                fontSize = 13.sp,
                                color = darkSub
                            )
                            pedido.paquete?.detalle?.takeIf { it.isNotBlank() }?.let { detalle ->
                                Spacer(modifier = Modifier.height(4.dp))
                                Text(text = detalle, fontSize = 12.sp, color = darkSub)
                            }
                        }
                    }
                }

                // Dirección de entrega
                item {
                    Card(
                        shape = RoundedCornerShape(16.dp),
                        colors = CardDefaults.cardColors(containerColor = Color.White),
                        elevation = CardDefaults.cardElevation(defaultElevation = 2.dp),
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Column(modifier = Modifier.padding(16.dp)) {
                            DetailHeader(Icons.Default.LocationOn, "Dirección de Entrega", darkTitle)
                            Spacer(modifier = Modifier.height(8.dp))

                            val callePrincipal = matchedAddress?.let { dir ->
                                val interior = dir.internal_number?.takeIf { it.isNotBlank() }?.let { " Int. $it" } ?: ""
                                val cp = dir.postal_code?.takeIf { it.isNotBlank() }?.let { ", CP $it" } ?: ""
                                "${dir.street_address}$interior$cp"
                            } ?: pedido.direccion_entrega?.takeIf { it.isNotBlank() }

                            val referenciasTexto = matchedAddress?.delivery_instructions?.takeIf { it.isNotBlank() }
                                ?: pedido.referencias?.takeIf { it.isNotBlank() }

                            if (callePrincipal != null) {
                                val etiqueta = matchedAddress?.label?.takeIf { it.isNotBlank() }
                                if (etiqueta != null) {
                                    Text(
                                        text = etiqueta,
                                        fontWeight = FontWeight.Bold,
                                        fontSize = 14.sp,
                                        color = darkTitle
                                    )
                                }
                                Text(
                                    text = callePrincipal,
                                    fontSize = 13.sp,
                                    color = darkSub
                                )
                                referenciasTexto?.let { refs ->
                                    Spacer(modifier = Modifier.height(4.dp))
                                    Text(
                                        text = "Referencias: $refs",
                                        fontSize = 12.sp,
                                        color = darkSub
                                    )
                                }
                            } else {
                                Text(
                                    text = "Dirección no registrada en el pedido",
                                    fontSize = 13.sp,
                                    color = darkSub
                                )
                            }
                        }
                    }
                }

                // Platillos elegidos por día
                item {
                    Card(
                        shape = RoundedCornerShape(16.dp),
                        colors = CardDefaults.cardColors(containerColor = Color.White),
                        elevation = CardDefaults.cardElevation(defaultElevation = 2.dp),
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Column(modifier = Modifier.padding(16.dp)) {
                            DetailHeader(Icons.Default.RestaurantMenu, "Platillos Elegidos", darkTitle)
                            Spacer(modifier = Modifier.height(8.dp))

                            val diasDetalle = pedido.dias.orEmpty()
                            if (diasDetalle.isNotEmpty()) {
                                diasDetalle.forEach { diaItem ->
                                    val fechaLabel = diaItem.fecha ?: "Día programado"
                                    Surface(
                                        shape = RoundedCornerShape(10.dp),
                                        color = mustardYellow.copy(alpha = 0.14f),
                                        modifier = Modifier
                                            .fillMaxWidth()
                                            .padding(vertical = 4.dp)
                                    ) {
                                        Column(modifier = Modifier.padding(10.dp)) {
                                            Text(
                                                text = fechaLabel,
                                                fontWeight = FontWeight.Bold,
                                                fontSize = 13.sp,
                                                color = darkTitle
                                            )
                                            Spacer(modifier = Modifier.height(4.dp))

                                            val subPlatillos = diaItem.platillos.orEmpty()
                                            if (subPlatillos.isNotEmpty()) {
                                                subPlatillos.forEach { itemPlatillo ->
                                                    val platilloId = itemPlatillo.platillo_id
                                                    val menuCatalogo = menus.find { it.id == platilloId }
                                                    val nombreMenu = itemPlatillo.platillo_nombre?.takeIf { it.isNotBlank() }
                                                        ?: menuCatalogo?.name?.takeIf { it.isNotBlank() }
                                                        ?: platilloId?.let { "Platillo #$it" }
                                                        ?: "Platillo"
                                                    val tiempoLabel = itemPlatillo.tiempo_comida?.takeIf { it.isNotBlank() }
                                                        ?.replaceFirstChar { it.uppercase() }
                                                    val extraTag = if (itemPlatillo.es_extra) " [Extra]" else ""
                                                    val tiempoPrefix = tiempoLabel?.let { "$it$extraTag: " } ?: ""
                                                    val precioValor = itemPlatillo.precio_congelado ?: menuCatalogo?.price
                                                    val precio = precioValor?.let { " • $$it MXN" } ?: ""
                                                    Text(
                                                        text = "• $tiempoPrefix$nombreMenu$precio",
                                                        fontSize = 13.sp,
                                                        color = darkSub,
                                                        modifier = Modifier.padding(vertical = 1.dp)
                                                    )
                                                }
                                            } else {
                                                val platilloId = diaItem.resolvedPlatilloId
                                                val menuCatalogo = menus.find { it.id == platilloId }
                                                val nombreMenu = diaItem.resolvedPlatilloNombre?.takeIf { it.isNotBlank() }
                                                    ?: menuCatalogo?.name?.takeIf { it.isNotBlank() }
                                                    ?: platilloId?.let { "Platillo #$it" }
                                                    ?: "Platillo"
                                                val precioValor = diaItem.precio_congelado ?: menuCatalogo?.price
                                                val precio = precioValor?.let { " • $$it MXN" } ?: ""
                                                Text(
                                                    text = "• $nombreMenu$precio",
                                                    fontSize = 13.sp,
                                                    color = darkSub,
                                                    modifier = Modifier.padding(vertical = 1.dp)
                                                )
                                            }
                                        }
                                    }
                                }
                            } else {
                                Text(
                                    text = "Paquete de $diasPaquete ${if (diasPaquete == 1) "día" else "días"} (${pedido.fecha_inicio ?: ""} – ${pedido.fecha_fin ?: ""}).",
                                    fontSize = 13.sp,
                                    color = darkSub
                                )
                            }
                        }
                    }
                }
            }

            Spacer(modifier = Modifier.height(12.dp))

            // Resumen y acciones
            Card(
                shape = RoundedCornerShape(16.dp),
                colors = CardDefaults.cardColors(containerColor = Color.White),
                elevation = CardDefaults.cardElevation(defaultElevation = 3.dp),
                modifier = Modifier.fillMaxWidth()
            ) {
                Column(modifier = Modifier.padding(14.dp)) {
                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.SpaceBetween,
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        Text("Total del pedido", fontSize = 14.sp, color = darkSub, fontWeight = FontWeight.SemiBold)
                        Text("$${pedido.total} MXN", fontSize = 18.sp, color = darkTitle, fontWeight = FontWeight.Bold)
                    }

                    val puedeCancelar = estadoPedido == "pendiente_pago" || estadoPedido == "recibido"
                    val puedePagar = estadoPedido == "pendiente_pago"

                    if (puedeCancelar || puedePagar || puedeCalificar) {
                        Spacer(modifier = Modifier.height(10.dp))
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.spacedBy(10.dp)
                        ) {
                            if (puedeCancelar) {
                                OutlinedButton(
                                    onClick = { showCancelDialog = true },
                                    shape = RoundedCornerShape(12.dp),
                                    modifier = Modifier.weight(1f).height(46.dp)
                                ) {
                                    Icon(
                                        imageVector = Icons.Default.Cancel,
                                        contentDescription = null,
                                        tint = Color(0xFFC62828),
                                        modifier = Modifier.size(18.dp)
                                    )
                                    Spacer(modifier = Modifier.width(6.dp))
                                    Text("Cancelar", color = Color(0xFFC62828), fontWeight = FontWeight.Bold)
                                }
                            }
                            if (puedePagar) {
                                Button(
                                    onClick = { onPayClick(pedido) },
                                    shape = RoundedCornerShape(12.dp),
                                    colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF2E7D32)),
                                    modifier = Modifier.weight(1f).height(46.dp)
                                ) {
                                    Icon(
                                        imageVector = Icons.Default.Payment,
                                        contentDescription = null,
                                        tint = Color.White,
                                        modifier = Modifier.size(18.dp)
                                    )
                                    Spacer(modifier = Modifier.width(6.dp))
                                    Text("Pagar Ahora", color = Color.White, fontWeight = FontWeight.Bold)
                                }
                            }
                            if (!puedePagar && puedeCalificar) {
                                Button(
                                    onClick = {
                                        onClearRatingStatus()
                                        showRatingDialog = true
                                    },
                                    shape = RoundedCornerShape(12.dp),
                                    colors = ButtonDefaults.buttonColors(containerColor = mustardYellow, contentColor = Color.Black),
                                    modifier = Modifier.weight(1f).height(46.dp)
                                ) {
                                    Icon(
                                        imageVector = Icons.Default.Star,
                                        contentDescription = null,
                                        modifier = Modifier.size(18.dp)
                                    )
                                    Spacer(modifier = Modifier.width(6.dp))
                                    Text("Calificar", fontWeight = FontWeight.Bold)
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun EstrellasSelector(
    etiqueta: String,
    valor: Int,
    onSelect: (Int) -> Unit
) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically
    ) {
        Text(text = "$etiqueta:", fontSize = 12.sp, fontWeight = FontWeight.SemiBold, color = Color(0xFF1A1A1A))
        Row(horizontalArrangement = Arrangement.spacedBy(2.dp)) {
            for (i in 1..5) {
                IconButton(
                    onClick = { onSelect(i) },
                    modifier = Modifier.size(30.dp)
                ) {
                    Icon(
                        imageVector = Icons.Default.Star,
                        contentDescription = "$i estrellas",
                        tint = if (i <= valor) Color(0xFFFFB300) else Color(0xFFD0D0D0),
                        modifier = Modifier.size(20.dp)
                    )
                }
            }
        }
    }
}

@Composable
private fun DetailHeader(icon: ImageVector, title: String, color: Color) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(
            imageVector = icon,
            contentDescription = null,
            tint = Color(0xFF8F6B00),
            modifier = Modifier.size(19.dp)
        )
        Spacer(modifier = Modifier.width(8.dp))
        Text(
            text = title,
            fontWeight = FontWeight.Bold,
            fontSize = 15.sp,
            color = color
        )
    }
}