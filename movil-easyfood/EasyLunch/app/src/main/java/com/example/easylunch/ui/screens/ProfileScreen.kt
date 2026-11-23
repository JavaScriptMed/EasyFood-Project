package com.example.easylunch.ui.screens

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ExitToApp
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.CameraAlt
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.LocationOn
import androidx.compose.material.icons.filled.Person
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil.compose.AsyncImage
import com.example.easylunch.data.model.AddressDto
import com.example.easylunch.data.model.UserDto

@Composable
fun ProfileScreen(
    user: UserDto?,
    addresses: List<AddressDto>,
    onUpdateProfile: (android.content.Context, String, String, String, String, Uri?) -> Unit,
    onNavigateToAddAddress: () -> Unit,
    onNavigateToEditAddress: (AddressDto) -> Unit,
    onDeleteAddressClick: (Int) -> Unit,
    onLogout: () -> Unit,
    modifier: Modifier = Modifier
) {
    val context = LocalContext.current
    var isEditing by remember { mutableStateOf(false) }
    var name by remember { mutableStateOf(user?.name ?: "") }
    var firstName by remember { mutableStateOf(user?.profile?.first_name ?: "") }
    var lastName by remember { mutableStateOf(user?.profile?.last_name ?: "") }
    var phone by remember { mutableStateOf(user?.profile?.phone ?: "") }

    // Estado para guardar la Uri de la imagen seleccionada localmente del dispositivo
    var selectedImageUri by remember { mutableStateOf<Uri?>(null) }

    // Lanzador para abrir la galería del dispositivo
    val imagePickerLauncher = rememberLauncherForActivityResult(
        contract = ActivityResultContracts.GetContent()
    ) { uri: Uri? ->
        if (uri != null) {
            selectedImageUri = uri
        }
    }

    val mustardYellow = Color(0xFFFFB300)

    Surface(
        modifier = modifier.fillMaxSize(),
        color = Color(0xFFFAFAFA)
    ) {
        LazyColumn(
            modifier = Modifier
                .fillMaxSize()
                .padding(16.dp)
        ) {
            item {
                Card(
                    modifier = Modifier.fillMaxWidth(),
                    shape = RoundedCornerShape(20.dp),
                    elevation = CardDefaults.cardElevation(defaultElevation = 2.dp),
                    colors = CardDefaults.cardColors(containerColor = Color.White)
                ) {
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(20.dp),
                        verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.SpaceBetween
                    ) {
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            // Avatar con soporte para imagen seleccionada o del servidor
                            Box(
                                modifier = Modifier
                                    .size(60.dp)
                                    .clip(CircleShape)
                                    .background(mustardYellow.copy(alpha = 0.2f))
                                    .clickable { if (isEditing) imagePickerLauncher.launch("image/*") },
                                contentAlignment = Alignment.Center
                            ) {
                                val avatarToShow = selectedImageUri ?: user?.profile?.avatar_url
                                if (avatarToShow != null) {
                                    AsyncImage(
                                        model = avatarToShow,
                                        contentDescription = "Avatar",
                                        modifier = Modifier.fillMaxSize(),
                                        contentScale = ContentScale.Crop
                                    )
                                } else {
                                    Icon(
                                        imageVector = Icons.Default.Person,
                                        contentDescription = null,
                                        modifier = Modifier.size(32.dp),
                                        tint = Color(0xFF8F6B00)
                                    )
                                }
                            }

                            Spacer(modifier = Modifier.width(16.dp))

                            Column {
                                Text(
                                    text = user?.name ?: "Usuario",
                                    fontSize = 18.sp,
                                    fontWeight = FontWeight.Bold,
                                    color = Color(0xFF2C2C2C)
                                )
                                Text(
                                    text = user?.email ?: "",
                                    fontSize = 13.sp,
                                    color = Color.Gray
                                )
                            }
                        }

                        IconButton(onClick = { isEditing = !isEditing }) {
                            Icon(
                                imageVector = if (isEditing) Icons.Default.Add else Icons.Default.Edit,
                                contentDescription = "Editar perfil",
                                tint = mustardYellow
                            )
                        }
                    }
                }

                Spacer(modifier = Modifier.height(16.dp))

                if (isEditing) {
                    Card(
                        modifier = Modifier.fillMaxWidth(),
                        shape = RoundedCornerShape(20.dp),
                        colors = CardDefaults.cardColors(containerColor = Color.White),
                        elevation = CardDefaults.cardElevation(defaultElevation = 4.dp)
                    ) {
                        Column(modifier = Modifier.padding(20.dp)) {
                            Text(text = "Editar Información Personal", fontWeight = FontWeight.Bold, fontSize = 15.sp, color = Color(0xFF2C2C2C))
                            Spacer(modifier = Modifier.height(12.dp))

                            // Botón para cambiar foto de perfil desde el modo edición
                            OutlinedButton(
                                onClick = { imagePickerLauncher.launch("image/*") },
                                modifier = Modifier.fillMaxWidth(),
                                shape = RoundedCornerShape(12.dp)
                            ) {
                                Icon(imageVector = Icons.Default.CameraAlt, contentDescription = null, tint = mustardYellow)
                                Spacer(modifier = Modifier.width(8.dp))
                                Text(text = if (selectedImageUri == null) "Seleccionar Foto de Perfil" else "Foto seleccionada con éxito", color = Color.DarkGray)
                            }

                            Spacer(modifier = Modifier.height(10.dp))

                            OutlinedTextField(
                                value = name,
                                onValueChange = { name = it },
                                label = { Text("Nombre de usuario / Alias") },
                                shape = RoundedCornerShape(12.dp),
                                modifier = Modifier.fillMaxWidth(),
                                colors = OutlinedTextFieldDefaults.colors(focusedBorderColor = mustardYellow)
                            )
                            Spacer(modifier = Modifier.height(10.dp))
                            OutlinedTextField(
                                value = firstName,
                                onValueChange = { firstName = it },
                                label = { Text("Nombre(s)") },
                                shape = RoundedCornerShape(12.dp),
                                modifier = Modifier.fillMaxWidth(),
                                colors = OutlinedTextFieldDefaults.colors(focusedBorderColor = mustardYellow)
                            )
                            Spacer(modifier = Modifier.height(10.dp))
                            OutlinedTextField(
                                value = lastName,
                                onValueChange = { lastName = it },
                                label = { Text("Apellidos") },
                                shape = RoundedCornerShape(12.dp),
                                modifier = Modifier.fillMaxWidth(),
                                colors = OutlinedTextFieldDefaults.colors(focusedBorderColor = mustardYellow)
                            )
                            Spacer(modifier = Modifier.height(10.dp))
                            OutlinedTextField(
                                value = phone,
                                onValueChange = { phone = it },
                                label = { Text("Teléfono de contacto") },
                                shape = RoundedCornerShape(12.dp),
                                modifier = Modifier.fillMaxWidth(),
                                colors = OutlinedTextFieldDefaults.colors(focusedBorderColor = mustardYellow)
                            )
                            Spacer(modifier = Modifier.height(20.dp))

                            Button(
                                onClick = {
                                    onUpdateProfile(context, name, firstName, lastName, phone, selectedImageUri)
                                    isEditing = false
                                },
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .height(48.dp),
                                shape = RoundedCornerShape(12.dp),
                                colors = ButtonDefaults.buttonColors(containerColor = mustardYellow)
                            ) {
                                Text("Guardar Cambios", fontWeight = FontWeight.Bold, color = Color.Black)
                            }
                        }
                    }
                    Spacer(modifier = Modifier.height(16.dp))
                }

                Spacer(modifier = Modifier.height(8.dp))

                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Text(
                        text = "Mis Direcciones de Entrega",
                        fontSize = 16.sp,
                        fontWeight = FontWeight.Bold,
                        color = Color(0xFF2C2C2C)
                    )
                    IconButton(onClick = onNavigateToAddAddress) {
                        Icon(
                            imageVector = Icons.Default.Add,
                            contentDescription = "Agregar dirección",
                            tint = mustardYellow
                        )
                    }
                }

                Spacer(modifier = Modifier.height(8.dp))
            }

            if (addresses.isEmpty()) {
                item {
                    Text(
                        text = "No tienes direcciones registradas para recibir tus paquetes.",
                        fontSize = 13.sp,
                        color = Color.Gray,
                        modifier = Modifier.padding(vertical = 12.dp)
                    )
                }
            } else {
                items(addresses) { address ->
                    Card(
                        modifier = Modifier
                            .fillMaxWidth()
                            .padding(vertical = 6.dp),
                        shape = RoundedCornerShape(16.dp),
                        colors = CardDefaults.cardColors(containerColor = Color.White),
                        elevation = CardDefaults.cardElevation(defaultElevation = 2.dp)
                    ) {
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(16.dp),
                            verticalAlignment = Alignment.CenterVertically
                        ) {
                            Box(
                                modifier = Modifier
                                    .size(40.dp)
                                    .clip(CircleShape)
                                    .background(mustardYellow.copy(alpha = 0.15f)),
                                contentAlignment = Alignment.Center
                            ) {
                                Icon(
                                    imageVector = Icons.Default.LocationOn,
                                    contentDescription = null,
                                    tint = Color(0xFF8F6B00),
                                    modifier = Modifier.size(20.dp)
                                )
                            }
                            Spacer(modifier = Modifier.width(12.dp))
                            Column(
                                modifier = Modifier
                                    .weight(1f)
                                    .clickable { onNavigateToEditAddress(address) }
                            ) {
                                Text(
                                    text = address.label ?: "Casa",
                                    fontWeight = FontWeight.Bold,
                                    fontSize = 15.sp,
                                    color = Color(0xFF2C2C2C)
                                )
                                Text(
                                    text = "${address.street_address}, Int: ${address.internal_number ?: "S/N"}, C.P. ${address.postal_code ?: ""}",
                                    fontSize = 13.sp,
                                    color = Color.Gray
                                )
                                if (!address.delivery_instructions.isNullOrEmpty()) {
                                    Text(
                                        text = "Ref: ${address.delivery_instructions}",
                                        fontSize = 12.sp,
                                        color = mustardYellow
                                    )
                                }

                                // Validación de cobertura calculada por PostGIS en el backend
                                if (address.sin_cobertura == true) {
                                    Spacer(modifier = Modifier.height(2.dp))
                                    Text(
                                        text = "⚠️ Fuera de zona de cobertura",
                                        fontSize = 12.sp,
                                        fontWeight = FontWeight.Bold,
                                        color = Color.Red
                                    )
                                }
                            }
                            IconButton(onClick = { onDeleteAddressClick(address.id) }) {
                                Icon(
                                    imageVector = Icons.Default.Delete,
                                    contentDescription = "Eliminar dirección",
                                    tint = MaterialTheme.colorScheme.error
                                )
                            }
                        }
                    }
                }
            }

            item {
                Spacer(modifier = Modifier.height(24.dp))
                OutlinedButton(
                    onClick = onLogout,
                    modifier = Modifier
                        .fillMaxWidth()
                        .height(50.dp),
                    shape = RoundedCornerShape(14.dp),
                    colors = ButtonDefaults.outlinedButtonColors(contentColor = MaterialTheme.colorScheme.error)
                ) {
                    Icon(imageVector = Icons.AutoMirrored.Filled.ExitToApp, contentDescription = null)
                    Spacer(modifier = Modifier.width(8.dp))
                    Text("Cerrar Sesión", fontWeight = FontWeight.Bold)
                }
                Spacer(modifier = Modifier.height(24.dp))
            }
        }
    }
}
