package com.foleyit.foleytimepro.ui.theme

import androidx.compose.material3.Typography
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.LineHeightStyle
import androidx.compose.ui.unit.sp

/**
 * The full 15-role Material 3 type scale.
 *
 * The scale it replaces defined 9 roles with a bare `fontSize`, no `lineHeight`
 * and no `letterSpacing` -- which meant Compose fell back to font-metric leading
 * and every list row breathed differently depending on whether its text wrapped.
 * Sizes here are the Material 3 reference values; do not spot-adjust them at the
 * call site, pick a different role.
 */

private val Leading = LineHeightStyle(
    alignment = LineHeightStyle.Alignment.Center,
    trim      = LineHeightStyle.Trim.None,
)

private fun scale(
    size: Int,
    lineHeight: Int,
    weight: FontWeight = FontWeight.Normal,
    letterSpacing: Double = 0.0,
) = TextStyle(
    fontFamily      = FontFamily.Default,
    fontWeight      = weight,
    fontSize        = size.sp,
    lineHeight      = lineHeight.sp,
    letterSpacing   = letterSpacing.sp,
    lineHeightStyle = Leading,
)

val AppTypography = Typography(
    displayLarge   = scale(57, 64, letterSpacing = -0.25),
    displayMedium  = scale(45, 52),
    displaySmall   = scale(36, 44),

    headlineLarge  = scale(32, 40),
    headlineMedium = scale(28, 36),
    headlineSmall  = scale(24, 32),

    titleLarge     = scale(22, 28),
    titleMedium    = scale(16, 24, FontWeight.Medium, 0.15),
    titleSmall     = scale(14, 20, FontWeight.Medium, 0.1),

    bodyLarge      = scale(16, 24, letterSpacing = 0.5),
    bodyMedium     = scale(14, 20, letterSpacing = 0.25),
    bodySmall      = scale(12, 16, letterSpacing = 0.4),

    labelLarge     = scale(14, 20, FontWeight.Medium, 0.1),
    labelMedium    = scale(12, 16, FontWeight.Medium, 0.5),
    labelSmall     = scale(11, 16, FontWeight.Medium, 0.5),
)

/**
 * The running-clock readout. Tabular-width digits matter more than the type
 * scale here: proportional digits make the seconds column jitter every tick.
 */
val ClockReadoutStyle = TextStyle(
    fontFamily      = FontFamily.Monospace,
    fontWeight      = FontWeight.Medium,
    fontSize        = 56.sp,
    lineHeight      = 64.sp,
    letterSpacing   = (-1).sp,
    lineHeightStyle = Leading,
)

/** Same readout, sized for the compact top-of-list summary. */
val ClockReadoutStyleSmall = ClockReadoutStyle.copy(
    fontSize   = 28.sp,
    lineHeight = 36.sp,
    letterSpacing = 0.sp,
)
