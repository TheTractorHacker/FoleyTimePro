package com.foleyit.foleytimepro.ui.theme

import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.sp

private val ColorScheme = lightColorScheme(
    primary          = Navy,
    onPrimary        = Color.White,
    primaryContainer = NavyLight,
    secondary        = Cyan,
    onSecondary      = Color.White,
    background       = Background,
    onBackground     = TextPrimary,
    surface          = Surface,
    onSurface        = TextPrimary,
    surfaceVariant   = Background,
    onSurfaceVariant = TextSecondary,
    outline          = DividerColor,
    error            = ClockOutRed,
)

val AppTypography = Typography(
    headlineLarge  = TextStyle(fontWeight = FontWeight.Bold,   fontSize = 26.sp, color = TextPrimary),
    headlineMedium = TextStyle(fontWeight = FontWeight.Bold,   fontSize = 22.sp, color = TextPrimary),
    titleLarge     = TextStyle(fontWeight = FontWeight.Bold,   fontSize = 18.sp, color = TextPrimary),
    titleMedium    = TextStyle(fontWeight = FontWeight.SemiBold, fontSize = 15.sp, color = TextPrimary),
    bodyLarge      = TextStyle(fontWeight = FontWeight.Normal, fontSize = 14.sp, color = TextPrimary),
    bodyMedium     = TextStyle(fontWeight = FontWeight.Normal, fontSize = 13.sp, color = TextSecondary),
    bodySmall      = TextStyle(fontWeight = FontWeight.Normal, fontSize = 11.sp, color = TextHint),
    labelLarge     = TextStyle(fontWeight = FontWeight.Bold,   fontSize = 14.sp),
    labelMedium    = TextStyle(fontWeight = FontWeight.SemiBold, fontSize = 12.sp),
)

@Composable
fun FoleyTimeProTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = ColorScheme,
        typography  = AppTypography,
        content     = content
    )
}
