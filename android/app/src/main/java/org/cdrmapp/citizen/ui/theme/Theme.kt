package org.cdrmapp.citizen.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp

val BrandGreen = Color(0xFF005F35)
val BrandOrange = Color(0xFFFA9423)
val BrandBurgundy = Color(0xFF9C0026)
val BrandGold = Color(0xFFF4B000)

private val LightColors = lightColorScheme(
    primary = BrandGreen, onPrimary = Color.White,
    primaryContainer = Color(0xFFDCEFE2), onPrimaryContainer = Color(0xFF004A29),
    secondary = BrandOrange, onSecondary = Color(0xFF18352A),
    secondaryContainer = Color(0xFFFFF0DA), onSecondaryContainer = Color(0xFF703900),
    tertiary = BrandBurgundy, onTertiary = Color.White,
    tertiaryContainer = Color(0xFFFFE0E7), onTertiaryContainer = Color(0xFF65001A),
    background = Color(0xFFEEF1F5), onBackground = Color(0xFF18352A),
    surface = Color.White, onSurface = Color(0xFF18352A),
    surfaceVariant = Color(0xFFE2EAE4), onSurfaceVariant = Color(0xFF4E6257),
    outline = Color(0xFF748C7D), outlineVariant = Color(0xFFC4D2C8),
    surfaceContainerLowest = Color.White, surfaceContainerLow = Color(0xFFF7FAF8),
    surfaceContainer = Color(0xFFF0F5F1), surfaceContainerHigh = Color(0xFFE8EFE9),
    surfaceContainerHighest = Color(0xFFE1EAE3), surfaceTint = BrandGreen,
    error = BrandBurgundy, onError = Color.White,
    errorContainer = Color(0xFFFFE0E7), onErrorContainer = Color(0xFF65001A),
)

private val DarkColors = darkColorScheme(
    primary = Color(0xFF8CDFA6), onPrimary = Color(0xFF00391C),
    primaryContainer = Color(0xFF004A29), onPrimaryContainer = Color(0xFFC9F2D5),
    secondary = Color(0xFFFFB563), onSecondary = Color(0xFF472600),
    secondaryContainer = Color(0xFF613A08), onSecondaryContainer = Color(0xFFFFDDB6),
    tertiary = Color(0xFFFFB0C3), onTertiary = Color(0xFF65001A),
    tertiaryContainer = Color(0xFF810B2E), onTertiaryContainer = Color(0xFFFFD9E2),
    background = Color(0xFF101A14), onBackground = Color(0xFFE0ECE3),
    surface = Color(0xFF142019), onSurface = Color(0xFFE0ECE3),
    surfaceVariant = Color(0xFF33483A), onSurfaceVariant = Color(0xFFBDCEC1),
    outline = Color(0xFF8DA193), outlineVariant = Color(0xFF435B4B),
    surfaceContainerLowest = Color(0xFF0B140E), surfaceContainerLow = Color(0xFF17251C),
    surfaceContainer = Color(0xFF1D2C22), surfaceContainerHigh = Color(0xFF26372C),
    surfaceContainerHighest = Color(0xFF304336), surfaceTint = Color(0xFF8CDFA6),
    error = Color(0xFFFFB0C3), onError = Color(0xFF65001A),
    errorContainer = Color(0xFF810B2E), onErrorContainer = Color(0xFFFFD9E2),
)

@Composable
fun CdrmTheme(darkTheme: Boolean = isSystemInDarkTheme(), content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = if (darkTheme) DarkColors else LightColors,
        shapes = Shapes(
            extraSmall = RoundedCornerShape(4.dp), small = RoundedCornerShape(6.dp),
            medium = RoundedCornerShape(10.dp), large = RoundedCornerShape(14.dp),
            extraLarge = RoundedCornerShape(20.dp),
        ),
        content = content,
    )
}
