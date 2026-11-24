package com.example.easylunch.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color

private val EasyLunchColors = lightColorScheme(
    primary = Color(0xFFFFB300),
    onPrimary = Color.Black,
    primaryContainer = Color(0xFFFFF4D6),
    onPrimaryContainer = Color(0xFF2B2B2B),
    secondary = Color(0xFF8A6100),
    background = Color(0xFFFAFAFA),
    surface = Color.White,
    onBackground = Color(0xFF2B2B2B),
    onSurface = Color(0xFF2B2B2B),
    error = Color(0xFFC62828)
)

@Composable
fun EasyLunchTheme(content: @Composable () -> Unit) {
    MaterialTheme(colorScheme = EasyLunchColors, typography = Typography, content = content)
}
