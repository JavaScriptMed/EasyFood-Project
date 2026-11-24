package com.example.easylunch.ui.screens

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.filled.LocationOn
import androidx.compose.material.icons.filled.Payment
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.easylunch.data.model.AddressDto
import com.example.easylunch.data.model.PedidoDto

@Composable
fun OrdersScreen(
    pedidos: List<PedidoDto>,
    addresses: List<AddressDto>,
    onNewOrderClick: () -> Unit,
    onPayClick: (PedidoDto) -> Unit,
    onOrderClick: (PedidoDto) -> Unit,
    modifier: Modifier = Modifier
) {
    val mustardYellow = Color(0xFFFFB300)
    val darkTitle = Color(0xFF1A1A1A)
    val darkSub = Color(0xFF4A4A4A)

    var selectedTabIndex by remember { mutableIntStateOf(0) }
    val tabTitles = listOf("Mis Pedidos y Paquetes", "Historial")

    val pedidosActivos = remember(pedidos) {
        pedidos.filter { it.estado != "recibido" }
    }
    val pedidosHistorial = remember(pedidos) {
        pedidos.filter { it.estado == "recibido" }
    }

    val listaVisible = if (selectedTabIndex == 0) pedidosActivos else pedidosHistorial

    Box(
        modifier = modifier
            .fillMaxSize()
    ) {
        Surface(
            modifier = Modifier.fillMaxSize(),
            color = Color(0xFFFAFAFA)
        ) {
            Column(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(horizontal = 16.dp, vertical = 14.dp)
            ) {
                Text(
                    text = if (selectedTabIndex == 0) "Mis Pedidos y Paquetes" else "Historial de Pedidos",
                    fontSize = 21.sp,
                    fontWeight = FontWeight.Bold,
                    color = darkTitle
                )
                Text(
                    text = "${listaVisible.size} ${if (listaVisible.size == 1) "pedido" else "pedidos"} en esta sección",
                    fontSize = 12.sp,
                    color = darkSub
                )

                Spacer(modifier = Modifier.height(12.dp))

                // Pestañas superiores
                Surface(
                    shape = RoundedCornerShape(14.dp),
                    color = Color(0xFFECECEC),
                    modifier = Modifier.fillMaxWidth()
                ) {
                    TabRow(
                        selectedTabIndex = selectedTabIndex,
                        containerColor = Color.Transparent,
                        contentColor = darkTitle,
                        indicator = {},
                        divider = {}
                    ) {
                        tabTitles.forEachIndexed { index, title ->
                            val isSelected = selectedTabIndex == index
                            Surface(
                                onClick = { selectedTabIndex = index },
                                shape = RoundedCornerShape(12.dp),
                                color = if (isSelected) Color.White else Color.Transparent,
                                shadowElevation = if (isSelected) 2.dp else 0.dp,
                                modifier = Modifier
                                    .padding(4.dp)
                                    .height(38.dp)
                            ) {
                                Box(
                                    contentAlignment = Alignment.Center,
                                    modifier = Modifier.padding(horizontal = 10.dp)
                                ) {
                                    Text(
                                        text = title,
                                        fontSize = 12.sp,
                                        fontWeight = if (isSelected) FontWeight.Bold else FontWeight.Medium,
                                        color = if (isSelected) darkTitle else darkSub,
                                        maxLines = 1,
                                        overflow = TextOverflow.Ellipsis
                                    )
                                }
                            }
                        }
                    }
                }

                Spacer(modifier = Modifier.height(14.dp))

                if (listaVisible.isEmpty()) {
                    Box(
                        modifier = Modifier.fillMaxSize(),
                        contentAlignment = Alignment.Center
                    ) {
                        Text(
                            text = if (selectedTabIndex == 0) {
                                "No tienes pedidos activos por el momento."
                            } else {
                                "Aún no tienes pedidos recibidos en tu historial."
                            },
                            fontSize = 14.sp,
                            color = darkSub
                        )
                    }
                } else {
                    LazyColumn(
                        contentPadding = PaddingValues(bottom = 96.dp),
                        verticalArrangement = Arrangement.spacedBy(12.dp)
                    ) {
                        items(listaVisible, key = { it.id }) { pedido ->
                            val matchedAddress = pedido.direccion
                                ?: addresses.find { it.id == pedido.direccion_id }
                                ?: pedido.direccion_entrega?.takeIf { it.isNotBlank() }?.let { calle ->
                                    addresses.find { it.street_address.equals(calle, ignoreCase = true) }
                                }

                            val addressText = matchedAddress?.let { dir ->
                                val tag = dir.label?.takeIf { it.isNotBlank() }
                                if (tag != null) "$tag • ${dir.street_address}" else dir.street_address
                            } ?: pedido.direccion_entrega?.takeIf { it.isNotBlank() }
                            ?: "Sin dirección"

                            val nombrePaquete = pedido.paquete?.nombre?.takeIf { it.isNotBlank() }
                                ?: pedido.plan_nombre?.takeIf { it.isNotBlank() }
                                ?: "Estándar"
                            val diasPaquete = pedido.paquete?.dias ?: 1

                            val isPending = pedido.estado == "pendiente_pago"
                            val isCancelled = pedido.estado == "cancelado"

                            Card(
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .clickable { onOrderClick(pedido) },
                                shape = RoundedCornerShape(16.dp),
                                colors = CardDefaults.cardColors(containerColor = Color.White),
                                elevation = CardDefaults.cardElevation(defaultElevation = 2.dp)
                            ) {
                                Column(
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .padding(16.dp)
                                ) {
                                    Row(
                                        modifier = Modifier.fillMaxWidth(),
                                        horizontalArrangement = Arrangement.SpaceBetween,
                                        verticalAlignment = Alignment.CenterVertically
                                    ) {
                                        Text(
                                            text = pedido.folio ?: "Pedido #${pedido.id}",
                                            fontWeight = FontWeight.Bold,
                                            fontSize = 16.sp,
                                            color = darkTitle
                                        )
                                        Row(verticalAlignment = Alignment.CenterVertically) {
                                            Surface(
                                                shape = RoundedCornerShape(8.dp),
                                                color = when {
                                                    isPending -> Color(0xFFFFEBEE)
                                                    isCancelled -> Color(0xFFEEEEEE)
                                                    else -> mustardYellow.copy(alpha = 0.22f)
                                                }
                                            ) {
                                                Text(
                                                    text = pedido.estado.replace("_", " ").uppercase(),
                                                    modifier = Modifier.padding(horizontal = 8.dp, vertical = 4.dp),
                                                    fontSize = 11.sp,
                                                    fontWeight = FontWeight.Bold,
                                                    color = when {
                                                        isPending -> Color(0xFFC62828)
                                                        isCancelled -> Color(0xFF616161)
                                                        else -> Color(0xFF8F6B00)
                                                    }
                                                )
                                            }
                                            Spacer(modifier = Modifier.width(4.dp))
                                            Icon(
                                                imageVector = Icons.Default.ChevronRight,
                                                contentDescription = "Ver detalle",
                                                tint = darkSub,
                                                modifier = Modifier.size(20.dp)
                                            )
                                        }
                                    }

                                    Spacer(modifier = Modifier.height(8.dp))
                                    Text(
                                        text = "Paquete: $nombrePaquete ($diasPaquete ${if (diasPaquete == 1) "día" else "días"})",
                                        fontSize = 13.sp,
                                        fontWeight = FontWeight.SemiBold,
                                        color = darkTitle
                                    )
                                    Text(
                                        text = "Entrega: Del ${pedido.fecha_inicio ?: ""} al ${pedido.fecha_fin ?: ""}",
                                        fontSize = 13.sp,
                                        color = darkSub
                                    )

                                    Spacer(modifier = Modifier.height(4.dp))
                                    Row(verticalAlignment = Alignment.CenterVertically) {
                                        Icon(
                                            imageVector = Icons.Default.LocationOn,
                                            contentDescription = null,
                                            tint = Color(0xFF8F6B00),
                                            modifier = Modifier.size(15.dp)
                                        )
                                        Spacer(modifier = Modifier.width(4.dp))
                                        Text(
                                            text = addressText,
                                            fontSize = 13.sp,
                                            color = darkSub,
                                            maxLines = 1,
                                            overflow = TextOverflow.Ellipsis
                                        )
                                    }

                                    if (isPending && pedido.segundos_para_pagar != null) {
                                        Spacer(modifier = Modifier.height(6.dp))
                                        Text(
                                            text = "Expira en: ${(pedido.segundos_para_pagar / 60).coerceAtLeast(0)} mins",
                                            fontSize = 12.sp,
                                            color = Color(0xFFC62828),
                                            fontWeight = FontWeight.Bold
                                        )
                                    }

                                    Spacer(modifier = Modifier.height(12.dp))

                                    Row(
                                        modifier = Modifier.fillMaxWidth(),
                                        horizontalArrangement = Arrangement.SpaceBetween,
                                        verticalAlignment = Alignment.CenterVertically
                                    ) {
                                        Text(
                                            text = "Total: $${pedido.total}",
                                            fontWeight = FontWeight.Bold,
                                            fontSize = 16.sp,
                                            color = darkTitle
                                        )

                                        if (isPending) {
                                            Button(
                                                onClick = { onPayClick(pedido) },
                                                shape = RoundedCornerShape(10.dp),
                                                colors = ButtonDefaults.buttonColors(containerColor = Color(0xFF2E7D32)),
                                                contentPadding = PaddingValues(horizontal = 12.dp, vertical = 6.dp)
                                            ) {
                                                Icon(
                                                    imageVector = Icons.Default.Payment,
                                                    contentDescription = null,
                                                    modifier = Modifier.size(16.dp),
                                                    tint = Color.White
                                                )
                                                Spacer(modifier = Modifier.width(4.dp))
                                                Text("Pagar Ahora", fontSize = 13.sp, fontWeight = FontWeight.Bold, color = Color.White)
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        ExtendedFloatingActionButton(
            onClick = onNewOrderClick,
            containerColor = mustardYellow,
            contentColor = Color.Black,
            shape = RoundedCornerShape(28.dp),
            icon = {
                Icon(imageVector = Icons.Default.Add, contentDescription = null)
            },
            text = {
                Text(
                    text = "Nuevo Pedido",
                    fontWeight = FontWeight.Bold,
                    fontSize = 14.sp
                )
            },
            modifier = Modifier
                .align(Alignment.BottomEnd)
                .padding(end = 20.dp, bottom = 88.dp)
        )
    }
}