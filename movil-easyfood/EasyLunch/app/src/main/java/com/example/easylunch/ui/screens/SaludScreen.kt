package com.example.easylunch.ui.screens

import androidx.compose.foundation.layout.*
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import com.example.easylunch.ui.viewmodel.SaludViewModel

@Composable
fun SaludScreen(
    modifier: Modifier = Modifier, // <-- Agregamos esto aquí
    viewModel: SaludViewModel = viewModel()
) {
    Surface(
        modifier = modifier.fillMaxSize(), // <-- Lo aplicamos al Surface principal
        color = MaterialTheme.colorScheme.background
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(24.dp),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Text(
                text = "Prueba de Conexión EasyLunch",
                fontSize = 20.sp,
                textAlign = TextAlign.Center,
                style = MaterialTheme.typography.titleLarge
            )

            Spacer(modifier = Modifier.height(32.dp))

            Text(
                text = viewModel.estadoRespuesta,
                fontSize = 16.sp,
                textAlign = TextAlign.Center,
                modifier = Modifier.padding(horizontal = 16.dp)
            )

            Spacer(modifier = Modifier.height(32.dp))

            Button(
                onClick = { viewModel.verificarSalud() }
            ) {
                Text(text = "Probar Conexión con Backend")
            }
        }
    }
}