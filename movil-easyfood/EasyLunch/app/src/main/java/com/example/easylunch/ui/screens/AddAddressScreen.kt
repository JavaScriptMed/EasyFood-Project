package com.example.easylunch.ui.screens

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.AddLocationAlt
import androidx.compose.material.icons.filled.LocationOn
import androidx.compose.material.icons.filled.MyLocation
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.easylunch.data.model.AddressDto

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AddAddressScreen(
    addressToEdit: AddressDto? = null,
    errorMessage: String = "",
    onSaveAddress: (String, String, String, String, Double?, Double?, String, Boolean) -> Unit,
    onBackClick: () -> Unit,
    modifier: Modifier = Modifier
) {
    val mustardYellow = Color(0xFFFFB300)
    val darkTitle = Color(0xFF1A1A1A)
    val darkSub = Color(0xFF4A4A4A)
    val screenBg = Color(0xFFFAFAFA)

    val initialStreet = remember(addressToEdit) {
        addressToEdit?.street_address?.substringBefore(", Col.")?.trim() ?: ""
    }
    val initialColonia = remember(addressToEdit) {
        val raw = addressToEdit?.street_address.orEmpty()
        if (", Col." in raw) raw.substringAfter(", Col.").trim() else ""
    }

    var label by remember { mutableStateOf(addressToEdit?.label ?: "Casa") }
    var streetAddress by remember { mutableStateOf(initialStreet) }
    var colonia by remember { mutableStateOf(initialColonia) }
    var internalNumber by remember { mutableStateOf(addressToEdit?.internal_number ?: "") }
    var postalCode by remember { mutableStateOf(addressToEdit?.postal_code ?: "") }
    var latitude by remember { mutableStateOf(addressToEdit?.latitude?.toString() ?: "") }
    var longitude by remember { mutableStateOf(addressToEdit?.longitude?.toString() ?: "") }
    var deliveryInstructions by remember { mutableStateOf(addressToEdit?.delivery_instructions ?: "") }
    var isDefault by remember { mutableStateOf(addressToEdit?.is_default ?: false) }

    val direccionCompleta = remember(streetAddress, colonia) {
        buildString {
            append(streetAddress.trim())
            if (colonia.isNotBlank()) {
                append(", Col. ${colonia.trim()}")
            }
        }
    }

    val latParsed = remember(latitude) { latitude.trim().takeIf { it.isNotEmpty() }?.toDoubleOrNull() }
    val lngParsed = remember(longitude) { longitude.trim().takeIf { it.isNotEmpty() }?.toDoubleOrNull() }
    val coordenadasIncompletas = (latitude.isNotBlank() && latParsed == null) ||
            (longitude.isNotBlank() && lngParsed == null) ||
            ((latParsed == null) != (lngParsed == null))

    Scaffold(
        modifier = modifier.fillMaxSize(),
        containerColor = screenBg,
        topBar = {
            TopAppBar(
                title = {
                    Column {
                        Text(
                            text = if (addressToEdit == null) "Nueva Dirección" else "Editar Dirección",
                            fontSize = 19.sp,
                            fontWeight = FontWeight.Bold,
                            color = darkTitle
                        )
                        Text(
                            text = "Entrega en zonas activas",
                            fontSize = 12.sp,
                            color = darkSub
                        )
                    }
                },
                navigationIcon = {
                    IconButton(onClick = onBackClick) {
                        Icon(
                            imageVector = Icons.AutoMirrored.Filled.ArrowBack,
                            contentDescription = "Regresar",
                            tint = darkTitle
                        )
                    }
                },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Color.White,
                    titleContentColor = darkTitle,
                    navigationIconContentColor = darkTitle
                )
            )
        }
    ) { innerPadding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding)
                .verticalScroll(rememberScrollState())
                .padding(horizontal = 16.dp, vertical = 14.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            // Tarjeta 1: Datos de ubicación
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(16.dp),
                colors = CardDefaults.cardColors(containerColor = Color.White),
                elevation = CardDefaults.cardElevation(defaultElevation = 2.dp)
            ) {
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(16.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp)
                ) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(
                            modifier = Modifier
                                .size(34.dp)
                                .clip(CircleShape)
                                .background(mustardYellow.copy(alpha = 0.20f)),
                            contentAlignment = Alignment.Center
                        ) {
                            Icon(
                                imageVector = Icons.Default.LocationOn,
                                contentDescription = null,
                                tint = Color(0xFF8F6B00),
                                modifier = Modifier.size(18.dp)
                            )
                        }
                        Spacer(modifier = Modifier.width(10.dp))
                        Text(
                            text = "Datos de la Dirección",
                            fontSize = 15.sp,
                            fontWeight = FontWeight.Bold,
                            color = darkTitle
                        )
                    }

                    OutlinedTextField(
                        value = label,
                        onValueChange = { label = it },
                        label = { Text("Etiqueta (Ej. Casa, Trabajo)") },
                        modifier = Modifier.fillMaxWidth(),
                        singleLine = true,
                        shape = RoundedCornerShape(12.dp),
                        colors = OutlinedTextFieldDefaults.colors(
                            focusedBorderColor = mustardYellow,
                            unfocusedBorderColor = Color(0xFFE0E0E0),
                            cursorColor = mustardYellow
                        )
                    )

                    OutlinedTextField(
                        value = streetAddress,
                        onValueChange = { streetAddress = it },
                        label = { Text("Calle y Número Exterior *") },
                        modifier = Modifier.fillMaxWidth(),
                        singleLine = true,
                        shape = RoundedCornerShape(12.dp),
                        colors = OutlinedTextFieldDefaults.colors(
                            focusedBorderColor = mustardYellow,
                            unfocusedBorderColor = Color(0xFFE0E0E0),
                            cursorColor = mustardYellow
                        )
                    )

                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        OutlinedTextField(
                            value = colonia,
                            onValueChange = { colonia = it },
                            label = { Text("Colonia") },
                            modifier = Modifier.weight(1f),
                            singleLine = true,
                            shape = RoundedCornerShape(12.dp),
                            colors = OutlinedTextFieldDefaults.colors(
                                focusedBorderColor = mustardYellow,
                                unfocusedBorderColor = Color(0xFFE0E0E0),
                                cursorColor = mustardYellow
                            )
                        )
                        OutlinedTextField(
                            value = postalCode,
                            onValueChange = { postalCode = it },
                            label = { Text("C.P.") },
                            modifier = Modifier.weight(0.65f),
                            singleLine = true,
                            shape = RoundedCornerShape(12.dp),
                            colors = OutlinedTextFieldDefaults.colors(
                                focusedBorderColor = mustardYellow,
                                unfocusedBorderColor = Color(0xFFE0E0E0),
                                cursorColor = mustardYellow
                            )
                        )
                    }

                    OutlinedTextField(
                        value = internalNumber,
                        onValueChange = { internalNumber = it },
                        label = { Text("Número Interior / Depto (Opcional)") },
                        modifier = Modifier.fillMaxWidth(),
                        singleLine = true,
                        shape = RoundedCornerShape(12.dp),
                        colors = OutlinedTextFieldDefaults.colors(
                            focusedBorderColor = mustardYellow,
                            unfocusedBorderColor = Color(0xFFE0E0E0),
                            cursorColor = mustardYellow
                        )
                    )
                }
            }

            // Tarjeta 2: Coordenadas opcionales y referencias
            Card(
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(16.dp),
                colors = CardDefaults.cardColors(containerColor = Color.White),
                elevation = CardDefaults.cardElevation(defaultElevation = 2.dp)
            ) {
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(16.dp),
                    verticalArrangement = Arrangement.spacedBy(10.dp)
                ) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Box(
                            modifier = Modifier
                                .size(34.dp)
                                .clip(CircleShape)
                                .background(mustardYellow.copy(alpha = 0.20f)),
                            contentAlignment = Alignment.Center
                        ) {
                            Icon(
                                imageVector = Icons.Default.MyLocation,
                                contentDescription = null,
                                tint = Color(0xFF8F6B00),
                                modifier = Modifier.size(18.dp)
                            )
                        }
                        Spacer(modifier = Modifier.width(10.dp))
                        Text(
                            text = "Coordenadas y Referencias",
                            fontSize = 15.sp,
                            fontWeight = FontWeight.Bold,
                            color = darkTitle
                        )
                    }

                    Text(
                        text = "Si dejas latitud y longitud vacías, el servidor buscará la ubicación automáticamente por texto.",
                        fontSize = 12.sp,
                        color = darkSub
                    )

                    Row(
                        modifier = Modifier.fillMaxWidth(),
                        horizontalArrangement = Arrangement.spacedBy(8.dp)
                    ) {
                        OutlinedTextField(
                            value = latitude,
                            onValueChange = { latitude = it },
                            label = { Text("Latitud (Opc.)") },
                            modifier = Modifier.weight(1f),
                            singleLine = true,
                            shape = RoundedCornerShape(12.dp),
                            colors = OutlinedTextFieldDefaults.colors(
                                focusedBorderColor = mustardYellow,
                                unfocusedBorderColor = Color(0xFFE0E0E0),
                                cursorColor = mustardYellow
                            )
                        )
                        OutlinedTextField(
                            value = longitude,
                            onValueChange = { longitude = it },
                            label = { Text("Longitud (Opc.)") },
                            modifier = Modifier.weight(1f),
                            singleLine = true,
                            shape = RoundedCornerShape(12.dp),
                            colors = OutlinedTextFieldDefaults.colors(
                                focusedBorderColor = mustardYellow,
                                unfocusedBorderColor = Color(0xFFE0E0E0),
                                cursorColor = mustardYellow
                            )
                        )
                    }

                    if (coordenadasIncompletas) {
                        Text(
                            text = "Ingresa ambos valores numéricos (latitud y longitud) o deja ambos vacíos.",
                            fontSize = 12.sp,
                            fontWeight = FontWeight.SemiBold,
                            color = Color(0xFFC62828)
                        )
                    }

                    OutlinedTextField(
                        value = deliveryInstructions,
                        onValueChange = { deliveryInstructions = it },
                        label = { Text("Referencias / Instrucciones de entrega") },
                        modifier = Modifier.fillMaxWidth(),
                        shape = RoundedCornerShape(12.dp),
                        colors = OutlinedTextFieldDefaults.colors(
                            focusedBorderColor = mustardYellow,
                            unfocusedBorderColor = Color(0xFFE0E0E0),
                            cursorColor = mustardYellow
                        )
                    )

                    Surface(
                        shape = RoundedCornerShape(12.dp),
                        color = mustardYellow.copy(alpha = 0.14f),
                        border = BorderStroke(1.dp, mustardYellow.copy(alpha = 0.35f)),
                        modifier = Modifier.fillMaxWidth()
                    ) {
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(horizontal = 12.dp, vertical = 6.dp),
                            horizontalArrangement = Arrangement.SpaceBetween,
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Text(
                                text = "Dirección predeterminada",
                                fontSize = 13.sp,
                                fontWeight = FontWeight.SemiBold,
                                color = darkTitle
                            )
                            Switch(
                                checked = isDefault,
                                onCheckedChange = { isDefault = it },
                                colors = SwitchDefaults.colors(
                                    checkedThumbColor = Color.Black,
                                    checkedTrackColor = mustardYellow
                                )
                            )
                        }
                    }
                }
            }

            // Vista previa limpia de lo que se enviará
            if (direccionCompleta.isNotBlank()) {
                Surface(
                    shape = RoundedCornerShape(12.dp),
                    color = Color.White,
                    border = BorderStroke(1.dp, Color(0xFFE6E6E6)),
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Column(modifier = Modifier.padding(12.dp)) {
                        Text(
                            text = "RESUMEN DE DIRECCIÓN",
                            fontSize = 10.sp,
                            fontWeight = FontWeight.Bold,
                            color = Color(0xFF8F6B00)
                        )
                        Spacer(modifier = Modifier.height(2.dp))
                        val extraResumen = buildString {
                            if (internalNumber.isNotBlank()) append(" • Int. ${internalNumber.trim()}")
                            if (postalCode.isNotBlank()) append(" • C.P. ${postalCode.trim()}")
                        }
                        Text(
                            text = "$direccionCompleta$extraResumen",
                            fontSize = 13.sp,
                            color = darkTitle,
                            fontWeight = FontWeight.Medium
                        )
                    }
                }
            }

            if (errorMessage.isNotBlank()) {
                Surface(
                    shape = RoundedCornerShape(12.dp),
                    color = Color(0xFFFFEBEE),
                    modifier = Modifier.fillMaxWidth()
                ) {
                    Text(
                        text = errorMessage,
                        color = Color(0xFFC62828),
                        fontSize = 13.sp,
                        fontWeight = FontWeight.SemiBold,
                        modifier = Modifier.padding(12.dp)
                    )
                }
            }

            Spacer(modifier = Modifier.height(4.dp))

            Button(
                onClick = {
                    onSaveAddress(
                        label.trim().ifEmpty { "Casa" },
                        direccionCompleta,
                        internalNumber.trim(),
                        postalCode.trim(),
                        latParsed,
                        lngParsed,
                        deliveryInstructions.trim(),
                        isDefault
                    )
                },
                modifier = Modifier
                    .fillMaxWidth()
                    .height(50.dp),
                shape = RoundedCornerShape(14.dp),
                colors = ButtonDefaults.buttonColors(
                    containerColor = mustardYellow,
                    contentColor = Color.Black,
                    disabledContainerColor = Color(0xFFE0E0E0),
                    disabledContentColor = darkSub
                ),
                enabled = streetAddress.isNotBlank() && !coordenadasIncompletas
            ) {
                Icon(
                    imageVector = Icons.Default.AddLocationAlt,
                    contentDescription = null,
                    modifier = Modifier.size(18.dp)
                )
                Spacer(modifier = Modifier.width(8.dp))
                Text(
                    text = if (addressToEdit == null) "Guardar Dirección" else "Actualizar Dirección",
                    fontWeight = FontWeight.Bold,
                    fontSize = 14.sp
                )
            }

            Spacer(modifier = Modifier.height(16.dp))
        }
    }
}