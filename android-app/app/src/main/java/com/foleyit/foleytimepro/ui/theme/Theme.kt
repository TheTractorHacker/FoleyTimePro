package com.foleyit.foleytimepro.ui.theme

import android.app.Activity
import android.os.Build
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.dynamicDarkColorScheme
import androidx.compose.material3.dynamicLightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.ReadOnlyComposable
import androidx.compose.runtime.SideEffect
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalView
import androidx.core.view.WindowCompat

/**
 * The app theme.
 *
 * `MaterialExpressiveTheme` and the `MotionScheme` API are compiled into
 * material3 1.4.0 but still `internal` there — Google shipped the bytecode
 * ahead of making it public API, and it only becomes callable in the still-alpha
 * 1.5.x line. Confirmed directly against the resolved dependency: probing it
 * with a standalone `kotlinc` against the real `material3-android-1.4.0.aar`
 * reports "internal in file" for `MaterialExpressiveTheme`, `MotionScheme`, and
 * the `motionScheme`-accepting `MaterialTheme` overload alike. This theme uses
 * the stable 3-arg `MaterialTheme(colorScheme, shapes, typography, content)`
 * instead. Revisit once the project moves to a material3 release where that
 * surface has stabilized.
 *
 * ## Why dynamic color is off by default
 *
 * FoleyTimePro runs on two very different devices: an employee's personal phone
 * and a shared, wall-mounted kiosk. Material You would repaint the kiosk to
 * whatever wallpaper happens to be set on it, and the punch-state colors — the
 * one thing on screen that has to be unambiguous at arm's length — would shift
 * with it. The brand palette is the same everywhere instead. Callers can still
 * pass `dynamicColor = true` for a personal-device build.
 */
@Composable
fun FoleyTimeProTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    dynamicColor: Boolean = false,
    content: @Composable () -> Unit,
) {
    val colorScheme = when {
        dynamicColor && Build.VERSION.SDK_INT >= Build.VERSION_CODES.S -> {
            val ctx = LocalContext.current
            if (darkTheme) dynamicDarkColorScheme(ctx) else dynamicLightColorScheme(ctx)
        }
        darkTheme -> BrandDarkScheme
        else      -> BrandLightScheme
    }
    val statusColors = if (darkTheme) DarkStatusColors else LightStatusColors

    // The window is drawn edge-to-edge (see MainActivity), so the only thing left
    // to sync is which way the system-bar icons are tinted.
    val view = LocalView.current
    if (!view.isInEditMode) {
        SideEffect {
            val window = (view.context as? Activity)?.window ?: return@SideEffect
            WindowCompat.getInsetsController(window, view).apply {
                isAppearanceLightStatusBars     = !darkTheme
                isAppearanceLightNavigationBars = !darkTheme
            }
        }
    }

    CompositionLocalProvider(LocalStatusColors provides statusColors) {
        MaterialTheme(
            colorScheme = colorScheme,
            shapes      = AppShapes,
            typography  = AppTypography,
            content     = content,
        )
    }
}

/**
 * Punch-state colors, reached the same way as the stock roles:
 * `MaterialTheme.statusColors.clockedInContainer`.
 */
val MaterialTheme.statusColors: StatusColors
    @Composable @ReadOnlyComposable
    get() = LocalStatusColors.current
