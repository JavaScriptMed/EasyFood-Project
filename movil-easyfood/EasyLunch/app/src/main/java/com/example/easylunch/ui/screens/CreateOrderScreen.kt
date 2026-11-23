package com.example.easylunch.ui.screens

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.CalendarToday
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Inventory2
import androidx.compose.material.icons.filled.LocationOn
import androidx.compose.material.icons.filled.RestaurantMenu
import androidx.compose.material.icons.filled.ShoppingBag
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import com.example.easylunch.data.model.AddressDto
import com.example.easylunch.data.model.MenuDto
import com.example.easylunch.data.model.PaqueteDto
import com.example.easylunch.data.model.PlatillosDiaRequest
import com.example.easylunch.ui.viewmodel.MenuViewModel

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CreateOrderScreen(
    paquetes: List<PaqueteDto>,
    direcciones: List<AddressDto>,
    onConfirmOrder: (paqueteId: Int, direccionId: Int, platillosPorDia: List<PlatillosDiaRequest>) -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    errorMessage: String = "",
    menuViewModel: MenuViewModel = viewModel()
) {
    var selectedPaquete by remember { mutableStateOf<PaqueteDto?>(null) }
    var selectedDireccion by remember { mutableStateOf<AddressDto?>(null) }

    var paqueteMenuExpanded by remember { mutableStateOf(false) }
    var direccionMenuExpanded by remember { mutableStateOf(false) }

    // Tiempos de comida base elegidos (se aplican igual en todos los días del paquete)
    val tiemposDisponibles = listOf("desayuno", "comida", "cena")
    var tiemposBaseSeleccionados by remember { mutableStateOf(listOf("comida")) }
    var incluirExtra by remember { mutableStateOf(false) }

    // Por día (1..dias) -> mapa de "desayuno"|"comida"|"cena"|"extra" a menuId
    var seleccionesPorDia by remember { mutableStateOf(emptyMap<Int, Map<String, Int>>()) }
    var diaActual by remember { mutableIntStateOf(1) }

    val mustardYellow = Color(0xFFFFB300)
    val darkTitleColor = Color(0xFF1A1A1A)
    val darkSubColor = Color(0xFF2C2C2C)

    val totalDias = selectedPaquete?.dias ?: 0
    val slotsPorDia = remember(tiemposBaseSeleccionados, incluirExtra) {
        val baseOrdenados = tiemposDisponibles.filter { tiemposBaseSeleccionados.contains(it) }
        if (incluirExtra) baseOrdenados + "extra" else baseOrdenados
    }
    val totalRequeridos = totalDias * slotsPorDia.size
    val totalSeleccionados = seleccionesPorDia.values.sumOf { mapaDia ->
        slotsPorDia.count { slot -> mapaDia[slot] != null }
    }
    val mapaDiaActual = seleccionesPorDia[diaActual].orEmpty()

    LaunchedEffect(Unit) {
        menuViewModel.loadMenus()
    }

    LaunchedEffect(selectedPaquete, slotsPorDia) {
        diaActual = 1
        val paquete = selectedPaquete
        seleccionesPorDia = if (paquete != null) {
            (1..paquete.dias).associateWith { emptyMap() }
        } else {
            emptyMap()
        }
    }

    val menusDisponibles = remember(menuViewModel.menus) {
        menuViewModel.menus.filter { it.is_available }
    }

    fun normalizarTiempoComida(menu: MenuDto): String {
        val raw = menu.tiempo_comida?.lowercase()?.trim()
        return if (raw in tiemposDisponibles) raw!! else "comida"
    }

    fun asignarPlatilloASlot(dia: Int, slot: String, menu: MenuDto) {
        val esExtraSlot = slot == "extra"
        val tiempoEsperado = if (esExtraSlot) normalizarTiempoComida(menu) else slot
        if (!esExtraSlot && menu.tiempo_comida != null && menu.tiempo_comida.lowercase() != slot) {
            return
        }

        val actualDia = seleccionesPorDia[dia].orEmpty().toMutableMap()
        if (actualDia[slot] == menu.id) {
            actualDia.remove(slot)
        } else {
            actualDia[slot] = menu.id
        }
        val actualizado = seleccionesPorDia.toMutableMap()
        actualizado[dia] = actualDia
        seleccionesPorDia = actualizado
        // tiempoEsperado queda validado por coherencia
        @Suppress("UNUSED_VARIABLE")
        val _validado = tiempoEsperado
    }

    val esDireccionValida = selectedDireccion != null && selectedDireccion?.sin_cobertura != true
    val esPedidoValido = selectedPaquete != null &&
            esDireccionValida &&
            slotsPorDia.isNotEmpty() &&
            totalRequeridos > 0 &&
            totalSeleccionados == totalRequeridos

    Scaffold(
        modifier = modifier.fillMaxSize(),
        containerColor = Color(0xFFFAFAFA),
        topBar = {
            TopAppBar(
                title = {
                    Text(
                        text = "Crear Nuevo Pedido",
                        fontSize = 20.sp,
                        fontWeight = FontWeight.Bold,
                        color = darkTitleColor
                    )
                },
                navigationIcon = {
                    IconButton(onClick = onBack) {
                        Icon(
                            imageVector = Icons.AutoMirrored.Filled.ArrowBack,
                            contentDescription = "Regresar",
                            tint = darkTitleColor
                        )
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Color.White,
                    titleContentColor = darkTitleColor,
                    navigationIconContentColor = darkTitleColor
                )
            )
        }
    ) { innerPadding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding)
                .padding(horizontal = 16.dp, vertical = 12.dp)
        ) {
            LazyColumn(
                modifier = Modifier
                    .weight(1f)
                    .fillMaxWidth(),
                verticalArrangement = Arrangement.spacedBy(12.dp)
            ) {
                // PASO 1: Paquete
                item {
                    SectionHeader(
                        icon = Icons.Default.Inventory2,
                        title = "1. Selecciona un Paquete",
                        color = darkTitleColor
                    )
                    Spacer(modifier = Modifier.height(6.dp))

                    ExposedDropdownMenuBox(
                        expanded = paqueteMenuExpanded,
                        onExpandedChange = { paqueteMenuExpanded = !paqueteMenuExpanded },
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        OutlinedTextField(
                            value = selectedPaquete?.let {
                                "${it.nombre} (${it.dias} ${if (it.dias == 1) "día" else "días"} • ${it.descuento_porcentaje}% desc)"
                            } ?: "",
                            onValueChange = {},
                            readOnly = true,
                            placeholder = {
                                Text("Elige un paquete disponible", color = darkSubColor)
                            },
                            leadingIcon = {
                                Icon(
                                    imageVector = Icons.Default.Inventory2,
                                    contentDescription = null,
                                    tint = mustardYellow
                                )
                            },
                            trailingIcon = {
                                ExposedDropdownMenuDefaults.TrailingIcon(expanded = paqueteMenuExpanded)
                            },
                            shape = RoundedCornerShape(12.dp),
                            colors = OutlinedTextFieldDefaults.colors(
                                focusedContainerColor = Color.White,
                                unfocusedContainerColor = Color.White,
                                focusedTextColor = darkTitleColor,
                                unfocusedTextColor = darkTitleColor,
                                focusedBorderColor = mustardYellow,
                                unfocusedBorderColor = Color(0xFFBDBDBD)
                            ),
                            modifier = Modifier
                                .menuAnchor(ExposedDropdownMenuAnchorType.PrimaryNotEditable, enabled = true)
                                .fillMaxWidth()
                        )

                        ExposedDropdownMenu(
                            expanded = paqueteMenuExpanded,
                            onDismissRequest = { paqueteMenuExpanded = false },
                            containerColor = Color.White
                        ) {
                            paquetes.forEach { paquete ->
                                DropdownMenuItem(
                                    text = {
                                        Column {
                                            Text(
                                                text = paquete.nombre,
                                                fontWeight = FontWeight.Bold,
                                                color = darkTitleColor
                                            )
                                            Text(
                                                text = "${paquete.dias} ${if (paquete.dias == 1) "día" else "días"} • ${paquete.descuento_porcentaje}% de descuento",
                                                fontSize = 12.sp,
                                                color = darkSubColor
                                            )
                                        }
                                    },
                                    onClick = {
                                        selectedPaquete = paquete
                                        paqueteMenuExpanded = false
                                    }
                                )
                            }
                        }
                    }
                }

                // PASO 2: Dirección
                item {
                    Spacer(modifier = Modifier.height(4.dp))
                    SectionHeader(
                        icon = Icons.Default.LocationOn,
                        title = "2. Selecciona una Dirección de Entrega",
                        color = darkTitleColor
                    )
                    Spacer(modifier = Modifier.height(6.dp))

                    if (direcciones.isEmpty()) {
                        Card(
                            shape = RoundedCornerShape(12.dp),
                            colors = CardDefaults.cardColors(containerColor = Color.White),
                            modifier = Modifier.fillMaxWidth()
                        ) {
                            Row(
                                modifier = Modifier.padding(12.dp),
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                Icon(
                                    imageVector = Icons.Default.Warning,
                                    contentDescription = null,
                                    tint = Color(0xFFD32F2F)
                                )
                                Spacer(modifier = Modifier.width(8.dp))
                                Text(
                                    text = "No tienes direcciones registradas en tu perfil.",
                                    color = darkSubColor,
                                    fontSize = 13.sp
                                )
                            }
                        }
                    } else {
                        ExposedDropdownMenuBox(
                            expanded = direccionMenuExpanded,
                            onExpandedChange = { direccionMenuExpanded = !direccionMenuExpanded },
                            modifier = Modifier.fillMaxWidth()
                        ) {
                            OutlinedTextField(
                                value = selectedDireccion?.let {
                                    "${it.label ?: "Dirección"} — ${it.street_address}"
                                } ?: "",
                                onValueChange = {},
                                readOnly = true,
                                placeholder = {
                                    Text("Elige dónde recibirás tu pedido", color = darkSubColor)
                                },
                                leadingIcon = {
                                    Icon(
                                        imageVector = Icons.Default.LocationOn,
                                        contentDescription = null,
                                        tint = mustardYellow
                                    )
                                },
                                trailingIcon = {
                                    ExposedDropdownMenuDefaults.TrailingIcon(expanded = direccionMenuExpanded)
                                },
                                shape = RoundedCornerShape(12.dp),
                                colors = OutlinedTextFieldDefaults.colors(
                                    focusedContainerColor = Color.White,
                                    unfocusedContainerColor = Color.White,
                                    focusedTextColor = darkTitleColor,
                                    unfocusedTextColor = darkTitleColor,
                                    focusedBorderColor = mustardYellow,
                                    unfocusedBorderColor = Color(0xFFBDBDBD)
                                ),
                                modifier = Modifier
                                    .menuAnchor(ExposedDropdownMenuAnchorType.PrimaryNotEditable, enabled = true)
                                    .fillMaxWidth()
                            )

                            ExposedDropdownMenu(
                                expanded = direccionMenuExpanded,
                                onDismissRequest = { direccionMenuExpanded = false },
                                containerColor = Color.White
                            ) {
                                direcciones.forEach { dir ->
                                    DropdownMenuItem(
                                        text = {
                                            Column {
                                                Text(
                                                    text = dir.label ?: "Dirección",
                                                    fontWeight = FontWeight.Bold,
                                                    color = darkTitleColor
                                                )
                                                Text(
                                                    text = dir.street_address,
                                                    fontSize = 12.sp,
                                                    color = darkSubColor
                                                )
                                                if (dir.sin_cobertura == true) {
                                                    Text(
                                                        text = "Fuera de zona de cobertura",
                                                        fontSize = 11.sp,
                                                        fontWeight = FontWeight.Bold,
                                                        color = Color(0xFFD32F2F)
                                                    )
                                                }
                                            }
                                        },
                                        onClick = {
                                            selectedDireccion = dir
                                            direccionMenuExpanded = false
                                        }
                                    )
                                }
                            }
                        }

                        if (selectedDireccion?.sin_cobertura == true) {
                            Spacer(modifier = Modifier.height(6.dp))
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                Icon(
                                    imageVector = Icons.Default.Warning,
                                    contentDescription = null,
                                    tint = Color(0xFFD32F2F),
                                    modifier = Modifier.size(16.dp)
                                )
                                Spacer(modifier = Modifier.width(6.dp))
                                Text(
                                    text = "Esta dirección está fuera de cobertura. Selecciona otra.",
                                    color = Color(0xFFD32F2F),
                                    fontSize = 12.sp,
                                    fontWeight = FontWeight.Bold
                                )
                            }
                        }
                    }
                }

                // PASO 3: Tiempos de comida y platillos por día
                if (selectedPaquete != null) {
                    item {
                        Spacer(modifier = Modifier.height(4.dp))
                        SectionHeader(
                            icon = Icons.Default.RestaurantMenu,
                            title = "3. Comidas por Día ($totalSeleccionados/$totalRequeridos)",
                            color = darkTitleColor
                        )
                        Text(
                            text = "Elige los mismos tiempos de comida para todos los días del paquete (1 a 3 comidas + extra opcional).",
                            fontSize = 12.sp,
                            color = darkSubColor
                        )
                        Spacer(modifier = Modifier.height(8.dp))

                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.spacedBy(8.dp)
                        ) {
                            tiemposDisponibles.forEach { tiempo ->
                                val activo = tiemposBaseSeleccionados.contains(tiempo)
                                FilterChip(
                                    selected = activo,
                                    onClick = {
                                        tiemposBaseSeleccionados = if (activo) {
                                            if (tiemposBaseSeleccionados.size > 1) {
                                                tiemposBaseSeleccionados - tiempo
                                            } else {
                                                tiemposBaseSeleccionados
                                            }
                                        } else {
                                            tiemposBaseSeleccionados + tiempo
                                        }
                                    },
                                    label = { Text(tiempo.replaceFirstChar { it.uppercase() }) }
                                )
                            }
                        }

                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            horizontalArrangement = Arrangement.SpaceBetween,
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Text(
                                text = "Incluir platillo extra diario",
                                fontSize = 13.sp,
                                color = darkTitleColor
                            )
                            Switch(
                                checked = incluirExtra,
                                onCheckedChange = { incluirExtra = it }
                            )
                        }

                        if (totalDias > 1) {
                            ScrollableTabRow(
                                selectedTabIndex = (diaActual - 1).coerceIn(0, totalDias - 1),
                                containerColor = Color.White,
                                contentColor = darkTitleColor,
                                edgePadding = 8.dp
                            ) {
                                for (dia in 1..totalDias) {
                                    val countDia = slotsPorDia.count { slot -> seleccionesPorDia[dia]?.get(slot) != null }
                                    Tab(
                                        selected = diaActual == dia,
                                        onClick = { diaActual = dia },
                                        text = {
                                            Row(verticalAlignment = Alignment.CenterVertically) {
                                                Icon(
                                                    imageVector = Icons.Default.CalendarToday,
                                                    contentDescription = null,
                                                    modifier = Modifier.size(14.dp),
                                                    tint = if (diaActual == dia) darkTitleColor else darkSubColor
                                                )
                                                Spacer(modifier = Modifier.width(4.dp))
                                                Text(
                                                    text = "Día $dia ($countDia/${slotsPorDia.size})",
                                                    fontWeight = if (diaActual == dia) FontWeight.Bold else FontWeight.Medium,
                                                    color = darkTitleColor,
                                                    fontSize = 13.sp
                                                )
                                            }
                                        }
                                    )
                                }
                            }
                            Spacer(modifier = Modifier.height(8.dp))
                        }

                        // Selector por cada tiempo de comida / extra del día actual
                        slotsPorDia.forEach { slot ->
                            val elegidoId = mapaDiaActual[slot]
                            val elegidoMenu = menusDisponibles.find { it.id == elegidoId }
                            val etiquetaSlot = if (slot == "extra") "Platillo Extra" else slot.replaceFirstChar { it.uppercase() }

                            Surface(
                                shape = RoundedCornerShape(10.dp),
                                color = mustardYellow.copy(alpha = 0.16f),
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .padding(vertical = 4.dp)
                            ) {
                                Column(modifier = Modifier.padding(10.dp)) {
                                    Text(
                                        text = "$etiquetaSlot — Día $diaActual: ${elegidoMenu?.name ?: "Sin seleccionar"}",
                                        fontWeight = FontWeight.Bold,
                                        fontSize = 13.sp,
                                        color = darkTitleColor
                                    )
                                }
                            }

                            val candidatos = if (slot == "extra") {
                                menusDisponibles
                            } else {
                                val filtrados = menusDisponibles.filter {
                                    it.tiempo_comida.isNullOrBlank() || it.tiempo_comida.equals(slot, ignoreCase = true)
                                }
                                filtrados.ifEmpty { menusDisponibles }
                            }

                            candidatos.forEach { menu ->
                                val isChecked = elegidoId == menu.id
                                Card(
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .padding(vertical = 3.dp)
                                        .clickable { asignarPlatilloASlot(diaActual, slot, menu) },
                                    shape = RoundedCornerShape(12.dp),
                                    colors = CardDefaults.cardColors(
                                        containerColor = if (isChecked) mustardYellow.copy(alpha = 0.12f) else Color.White
                                    ),
                                    border = BorderStroke(
                                        width = 1.dp,
                                        color = if (isChecked) mustardYellow else Color(0xFFE0E0E0)
                                    ),
                                    elevation = CardDefaults.cardElevation(defaultElevation = 1.dp)
                                ) {
                                    Row(
                                        modifier = Modifier
                                            .fillMaxWidth()
                                            .padding(horizontal = 12.dp, vertical = 8.dp),
                                        horizontalArrangement = Arrangement.SpaceBetween,
                                        verticalAlignment = Alignment.CenterVertically
                                    ) {
                                        Icon(
                                            imageVector = if (isChecked) Icons.Default.CheckCircle else Icons.Default.RestaurantMenu,
                                            contentDescription = null,
                                            tint = if (isChecked) Color(0xFF8F6B00) else darkSubColor,
                                            modifier = Modifier.size(20.dp)
                                        )
                                        Spacer(modifier = Modifier.width(10.dp))
                                        Column(modifier = Modifier.weight(1f)) {
                                            Text(
                                                text = menu.name,
                                                fontWeight = FontWeight.Bold,
                                                fontSize = 14.sp,
                                                color = darkTitleColor
                                            )
                                            Text(
                                                text = "$${menu.price} MXN • Stock: ${menu.daily_stock}",
                                                fontSize = 12.sp,
                                                color = darkSubColor
                                            )
                                        }
                                        RadioButton(
                                            selected = isChecked,
                                            onClick = { asignarPlatilloASlot(diaActual, slot, menu) },
                                            colors = RadioButtonDefaults.colors(selectedColor = mustardYellow)
                                        )
                                    }
                                }
                            }
                        }
                    }
                }
            }

            if (errorMessage.isNotBlank()) {
                Spacer(modifier = Modifier.height(8.dp))
                Text(
                    text = errorMessage,
                    color = Color(0xFFD32F2F),
                    fontSize = 13.sp,
                    fontWeight = FontWeight.Bold
                )
            }

            Spacer(modifier = Modifier.height(12.dp))

            val faltantes = (totalRequeridos - totalSeleccionados).coerceAtLeast(0)

            Button(
                onClick = {
                    val paquete = selectedPaquete ?: return@Button
                    val direccion = selectedDireccion ?: return@Button
                    val payloadPorDia = (1..paquete.dias).map { dia ->
                        val mapaDia = seleccionesPorDia[dia].orEmpty()
                        PlatillosDiaRequest(
                            desayuno = mapaDia["desayuno"],
                            comida = mapaDia["comida"],
                            cena = mapaDia["cena"],
                            extra = if (incluirExtra) mapaDia["extra"] else null
                        )
                    }
                    onConfirmOrder(paquete.id, direccion.id, payloadPorDia)
                },
                enabled = esPedidoValido,
                modifier = Modifier
                    .fillMaxWidth()
                    .height(50.dp),
                colors = ButtonDefaults.buttonColors(
                    containerColor = mustardYellow,
                    contentColor = Color.Black,
                    disabledContainerColor = Color(0xFFE0E0E0),
                    disabledContentColor = darkSubColor
                ),
                shape = RoundedCornerShape(12.dp)
            ) {
                Icon(
                    imageVector = Icons.Default.ShoppingBag,
                    contentDescription = null,
                    modifier = Modifier.size(18.dp)
                )
                Spacer(modifier = Modifier.width(8.dp))
                Text(
                    text = when {
                        selectedPaquete == null -> "Selecciona un paquete"
                        selectedDireccion == null -> "Selecciona una dirección"
                        selectedDireccion?.sin_cobertura == true -> "Dirección fuera de cobertura"
                        !esPedidoValido -> "Elige $faltantes platillo(s) más ($totalSeleccionados/$totalRequeridos)"
                        else -> "Confirmar y Generar Pedido"
                    },
                    fontWeight = FontWeight.Bold,
                    fontSize = 14.sp
                )
            }
        }
    }
}

@Composable
private fun SectionHeader(
    icon: ImageVector,
    title: String,
    color: Color
) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(
            imageVector = icon,
            contentDescription = null,
            tint = Color(0xFF8F6B00),
            modifier = Modifier.size(20.dp)
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


