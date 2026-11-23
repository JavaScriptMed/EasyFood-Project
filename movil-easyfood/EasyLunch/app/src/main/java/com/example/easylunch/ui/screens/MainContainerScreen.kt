package com.example.easylunch.ui.screens

import android.widget.Toast
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.DateRange
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Person
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import com.example.easylunch.BuildConfig
import com.example.easylunch.data.model.AddressDto
import com.example.easylunch.data.model.PedidoDto
import com.example.easylunch.ui.viewmodel.AuthViewModel
import com.example.easylunch.ui.viewmodel.MenuViewModel
import com.example.easylunch.ui.viewmodel.OrderViewModel
import com.stripe.android.PaymentConfiguration
import com.stripe.android.paymentsheet.PaymentSheet
import com.stripe.android.paymentsheet.PaymentSheetResult
import com.stripe.android.paymentsheet.rememberPaymentSheet

sealed class BottomNavTab(val title: String, val icon: ImageVector) {
    object Explorar : BottomNavTab("Explorar", Icons.Default.Home)
    object Actividad : BottomNavTab("Actividad", Icons.Default.DateRange)
    object Perfil : BottomNavTab("Mi perfil", Icons.Default.Person)
}

@Composable
fun MainContainerScreen(
    authViewModel: AuthViewModel,
    onLogout: () -> Unit
) {
    var selectedTab by remember { mutableStateOf<BottomNavTab>(BottomNavTab.Explorar) }
    var isAddingAddress by remember { mutableStateOf(false) }
    var addressEditing by remember { mutableStateOf<AddressDto?>(null) }
    var isCreatingOrder by remember { mutableStateOf(false) }
    var selectedPedidoDetail by remember { mutableStateOf<PedidoDto?>(null) }
    val orderViewModel: OrderViewModel = viewModel()
    val menuViewModel: MenuViewModel = viewModel()
    val context = LocalContext.current

    // 1. Inicializar Stripe con la clave pública de BuildConfig
    LaunchedEffect(Unit) {
        if (BuildConfig.STRIPE_PUBLISHABLE_KEY.isNotBlank()) {
            PaymentConfiguration.init(context, BuildConfig.STRIPE_PUBLISHABLE_KEY)
        }
        authViewModel.cargarDirecciones()
    }

    // 2. Registrar PaymentSheet y manejar el resultado del pago
    val paymentSheet = rememberPaymentSheet { result ->
        when (result) {
            is PaymentSheetResult.Completed -> {
                Toast.makeText(context, "¡Pago completado con éxito!", Toast.LENGTH_LONG).show()
                orderViewModel.refrescarTrasPago()
            }
            is PaymentSheetResult.Canceled -> {
                Toast.makeText(context, "Pago cancelado", Toast.LENGTH_SHORT).show()
            }
            is PaymentSheetResult.Failed -> {
                val msg = result.error.localizedMessage ?: "Error al procesar el pago"
                Toast.makeText(context, msg, Toast.LENGTH_LONG).show()
            }
        }
    }

    LaunchedEffect(selectedTab) {
        if (selectedTab != BottomNavTab.Actividad) {
            isCreatingOrder = false
            selectedPedidoDetail = null
        }
    }

    val tabs = listOf(
        BottomNavTab.Explorar,
        BottomNavTab.Actividad,
        BottomNavTab.Perfil
    )

    val mustardYellow = Color(0xFFFFB300)
    val floatingBarBg = Color(0xFF1E1E1E)

    // 3. Solicitar el client_secret al backend y abrir PaymentSheet
    val dispararPagoStripe: (PedidoDto) -> Unit = { pedido ->
        if (BuildConfig.STRIPE_PUBLISHABLE_KEY.isBlank()) {
            Toast.makeText(
                context,
                "Falta configurar STRIPE_PUBLISHABLE_KEY en gradle.properties",
                Toast.LENGTH_LONG
            ).show()
        } else {
            Toast.makeText(context, "Conectando con Stripe...", Toast.LENGTH_SHORT).show()
            orderViewModel.iniciarPagoStripe(
                pedidoId = pedido.id,
                onError = { mensajeError ->
                    Toast.makeText(context, mensajeError, Toast.LENGTH_LONG).show()
                },
                onReady = { intentData ->
                    val clientSecret = intentData.client_secret
                    if (clientSecret.isBlank()) {
                        Toast.makeText(
                            context,
                            "El servidor no devolvió client_secret",
                            Toast.LENGTH_LONG
                        ).show()
                    } else {
                        try {
                            PaymentConfiguration.init(context, BuildConfig.STRIPE_PUBLISHABLE_KEY)
                            paymentSheet.presentWithPaymentIntent(
                                paymentIntentClientSecret = clientSecret,
                                configuration = PaymentSheet.Configuration(
                                    merchantDisplayName = "EasyLunch"
                                )
                            )
                        } catch (e: Exception) {
                            Toast.makeText(
                                context,
                                "Error al abrir Stripe: ${e.localizedMessage}",
                                Toast.LENGTH_LONG
                            ).show()
                        }
                    }
                }
            )
        }
    }

    Scaffold(
        modifier = Modifier.fillMaxSize(),
        bottomBar = {
            AnimatedVisibility(
                visible = !isAddingAddress && !isCreatingOrder && selectedPedidoDetail == null,
                enter = slideInVertically(initialOffsetY = { it }),
                exit = slideOutVertically(targetOffsetY = { it })
            ) {
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 24.dp, vertical = 16.dp),
                    contentAlignment = Alignment.Center
                ) {
                    Row(
                        modifier = Modifier
                            .height(65.dp)
                            .clip(RoundedCornerShape(50.dp))
                            .background(floatingBarBg)
                            .padding(horizontal = 8.dp),
                        horizontalArrangement = Arrangement.SpaceEvenly,
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        tabs.forEach { tab ->
                            val selected = selectedTab == tab

                            Surface(
                                modifier = Modifier
                                    .height(48.dp)
                                    .weight(1f),
                                shape = RoundedCornerShape(30.dp),
                                color = if (selected) mustardYellow else Color.Transparent,
                                onClick = { selectedTab = tab }
                            ) {
                                Row(
                                    modifier = Modifier.fillMaxSize(),
                                    horizontalArrangement = Arrangement.Center,
                                    verticalAlignment = Alignment.CenterVertically
                                ) {
                                    Icon(
                                        imageVector = tab.icon,
                                        contentDescription = tab.title,
                                        tint = if (selected) Color.Black else Color.LightGray,
                                        modifier = Modifier.size(22.dp)
                                    )
                                    if (selected) {
                                        Spacer(modifier = Modifier.width(6.dp))
                                        Text(
                                            text = tab.title,
                                            color = Color.Black,
                                            fontSize = 12.sp,
                                            maxLines = 1,
                                            style = MaterialTheme.typography.labelMedium
                                        )
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    ) { innerPadding ->
        Box(modifier = Modifier.fillMaxSize()) {
            when (selectedTab) {
                BottomNavTab.Explorar -> {
                    MenuCatalogScreen(
                        modifier = Modifier.padding(innerPadding),
                        viewModel = menuViewModel
                    )
                }
                BottomNavTab.Actividad -> {
                    LaunchedEffect(Unit) {
                        orderViewModel.cargarPedidos()
                        orderViewModel.cargarPaquetes()
                        orderViewModel.cargarProximaEntrega()
                        authViewModel.cargarDirecciones()
                    }

                    val pedidoActivoDetalle = selectedPedidoDetail?.let { actual ->
                        orderViewModel.pedidos.find { it.id == actual.id } ?: actual
                    }

                    when {
                        isCreatingOrder -> {
                            CreateOrderScreen(
                                paquetes = orderViewModel.paquetes,
                                direcciones = authViewModel.addresses,
                                modifier = Modifier.padding(innerPadding),
                                errorMessage = orderViewModel.estadoOrden,
                                onConfirmOrder = { paqueteId, direccionId, platillosPorDia ->
                                    orderViewModel.crearPedido(paqueteId, direccionId, platillosPorDia) { _ ->
                                        isCreatingOrder = false
                                        orderViewModel.cargarPedidos()
                                    }
                                },
                                onBack = { isCreatingOrder = false }
                            )
                        }
                        pedidoActivoDetalle != null -> {
                            OrderDetailScreen(
                                pedido = pedidoActivoDetalle,
                                addresses = authViewModel.addresses,
                                onBack = { selectedPedidoDetail = null },
                                onPayClick = dispararPagoStripe,
                                onCancelClick = { pedido ->
                                    orderViewModel.cancelarPedido(pedido.id) {
                                        selectedPedidoDetail = null
                                    }
                                },
                                modifier = Modifier.padding(innerPadding),
                                menus = menuViewModel.menus
                            )
                        }
                        else -> {
                            OrdersScreen(
                                pedidos = orderViewModel.pedidos,
                                addresses = authViewModel.addresses,
                                onNewOrderClick = {
                                    isCreatingOrder = true
                                },
                                onPayClick = dispararPagoStripe,
                                onOrderClick = { pedido ->
                                    selectedPedidoDetail = pedido
                                },
                                modifier = Modifier.padding(innerPadding)
                            )
                        }
                    }
                }
                BottomNavTab.Perfil -> {
                    if (isAddingAddress) {
                        AddAddressScreen(
                            addressToEdit = addressEditing,
                            errorMessage = authViewModel.estadoDireccion,
                            onSaveAddress = { label, street, intNum, cp, lat, lng, instructions, isDefault ->
                                if (addressEditing == null) {
                                    authViewModel.crearDireccion(label, street, intNum, cp, lat, lng, instructions, isDefault) {
                                        isAddingAddress = false
                                    }
                                } else {
                                    authViewModel.actualizarDireccion(addressEditing!!.id, label, street, intNum, cp, lat, lng, instructions, isDefault) {
                                        isAddingAddress = false
                                        addressEditing = null
                                    }
                                }
                            },
                            onBackClick = {
                                isAddingAddress = false
                                addressEditing = null
                            },
                            modifier = Modifier.padding(innerPadding)
                        )
                    } else {
                        ProfileScreen(
                            user = authViewModel.currentUser,
                            addresses = authViewModel.addresses,
                            onUpdateProfile = { context, name, firstName, lastName, phone, imageUri ->
                                authViewModel.actualizarPerfil(context, name, firstName, lastName, phone, imageUri)
                            },
                            onNavigateToAddAddress = {
                                addressEditing = null
                                isAddingAddress = true
                            },
                            onNavigateToEditAddress = { address ->
                                addressEditing = address
                                isAddingAddress = true
                            },
                            onDeleteAddressClick = { addressId ->
                                authViewModel.eliminarDireccion(addressId)
                            },
                            onLogout = onLogout,
                            modifier = Modifier.padding(innerPadding)
                        )
                    }
                }
            }
        }
    }
}