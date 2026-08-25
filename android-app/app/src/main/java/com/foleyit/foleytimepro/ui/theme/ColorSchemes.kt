package com.foleyit.foleytimepro.ui.theme

import androidx.compose.material3.ColorScheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Immutable
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color

/**
 * The brand color schemes, mapped tone-by-tone off the palettes in [Color.kt].
 *
 * Every role is filled in explicitly. The alternative — letting `lightColorScheme()`
 * default the roles we don't name — silently mixes Material's stock purple into
 * anything we forget, which is exactly the drift this file exists to prevent.
 */

internal val BrandLightScheme: ColorScheme = lightColorScheme(
    primary                 = Primary40,
    onPrimary               = Primary100,
    primaryContainer        = Primary90,
    onPrimaryContainer      = Primary10,
    inversePrimary          = Primary80,

    secondary               = Secondary40,
    onSecondary             = Secondary100,
    secondaryContainer      = Secondary90,
    onSecondaryContainer    = Secondary10,

    tertiary                = Tertiary40,
    onTertiary              = Tertiary100,
    tertiaryContainer       = Tertiary90,
    onTertiaryContainer     = Tertiary10,

    error                   = Error40,
    onError                 = Error100,
    errorContainer          = Error90,
    onErrorContainer        = Error10,

    background              = Neutral98,
    onBackground            = Neutral10,
    surface                 = Neutral98,
    onSurface               = Neutral10,
    surfaceVariant          = NeutralVariant90,
    onSurfaceVariant        = NeutralVariant30,
    surfaceTint             = Primary40,
    inverseSurface          = Neutral20,
    inverseOnSurface        = Neutral95,

    surfaceBright           = Neutral98,
    surfaceDim              = Neutral87,
    surfaceContainerLowest  = Neutral100,
    surfaceContainerLow     = Neutral96,
    surfaceContainer        = Neutral94,
    surfaceContainerHigh    = Neutral92,
    surfaceContainerHighest = Neutral90,

    outline                 = NeutralVariant50,
    outlineVariant          = NeutralVariant80,
    scrim                   = Neutral0,

    primaryFixed            = Primary90,
    primaryFixedDim         = Primary80,
    onPrimaryFixed          = Primary10,
    onPrimaryFixedVariant   = Primary30,
    secondaryFixed          = Secondary90,
    secondaryFixedDim       = Secondary80,
    onSecondaryFixed        = Secondary10,
    onSecondaryFixedVariant = Secondary30,
    tertiaryFixed           = Tertiary90,
    tertiaryFixedDim        = Tertiary80,
    onTertiaryFixed         = Tertiary10,
    onTertiaryFixedVariant  = Tertiary30,
)

internal val BrandDarkScheme: ColorScheme = darkColorScheme(
    primary                 = Primary80,
    onPrimary               = Primary20,
    primaryContainer        = Primary30,
    onPrimaryContainer      = Primary90,
    inversePrimary          = Primary40,

    secondary               = Secondary80,
    onSecondary             = Secondary20,
    secondaryContainer      = Secondary30,
    onSecondaryContainer    = Secondary90,

    tertiary                = Tertiary80,
    onTertiary              = Tertiary20,
    tertiaryContainer       = Tertiary30,
    onTertiaryContainer     = Tertiary90,

    error                   = Error80,
    onError                 = Error20,
    errorContainer          = Error30,
    onErrorContainer        = Error90,

    background              = Neutral6,
    onBackground            = Neutral90,
    surface                 = Neutral6,
    onSurface               = Neutral90,
    surfaceVariant          = NeutralVariant30,
    onSurfaceVariant        = NeutralVariant80,
    surfaceTint             = Primary80,
    inverseSurface          = Neutral90,
    inverseOnSurface        = Neutral20,

    surfaceBright           = Neutral24,
    surfaceDim              = Neutral6,
    surfaceContainerLowest  = Neutral4,
    surfaceContainerLow     = Neutral10,
    surfaceContainer        = Neutral12,
    surfaceContainerHigh    = Neutral17,
    surfaceContainerHighest = Neutral22,

    outline                 = NeutralVariant60,
    outlineVariant          = NeutralVariant30,
    scrim                   = Neutral0,

    // Fixed roles are deliberately identical across light and dark -- that is the
    // point of them: a chip that must read the same on a phone and on a wall-mounted
    // kiosk in a dark warehouse.
    primaryFixed            = Primary90,
    primaryFixedDim         = Primary80,
    onPrimaryFixed          = Primary10,
    onPrimaryFixedVariant   = Primary30,
    secondaryFixed          = Secondary90,
    secondaryFixedDim       = Secondary80,
    onSecondaryFixed        = Secondary10,
    onSecondaryFixedVariant = Secondary30,
    tertiaryFixed           = Tertiary90,
    tertiaryFixedDim        = Tertiary80,
    onTertiaryFixed         = Tertiary10,
    onTertiaryFixedVariant  = Tertiary30,
)

/**
 * Punch-state colors, carried as a proper role set rather than loose `Color`
 * constants so screens never have to ask "is this the light or dark value".
 *
 * Only three ramps live here. Clocking out is the destructive action and uses
 * [ColorScheme.error]; ending lunch resumes work and uses [ColorScheme.secondary].
 */
@Immutable
data class StatusColors(
    val clockedIn: Color,
    val onClockedIn: Color,
    val clockedInContainer: Color,
    val onClockedInContainer: Color,

    val lunch: Color,
    val onLunch: Color,
    val lunchContainer: Color,
    val onLunchContainer: Color,

    val warning: Color,
    val onWarning: Color,
    val warningContainer: Color,
    val onWarningContainer: Color,
)

internal val LightStatusColors = StatusColors(
    clockedIn            = ClockedIn40,
    onClockedIn          = ClockedIn100,
    clockedInContainer   = ClockedIn90,
    onClockedInContainer = ClockedIn10,

    lunch                = Lunch40,
    onLunch              = Lunch100,
    lunchContainer       = Lunch90,
    onLunchContainer     = Lunch10,

    warning              = Warning40,
    onWarning            = Warning100,
    warningContainer     = Warning90,
    onWarningContainer   = Warning10,
)

internal val DarkStatusColors = StatusColors(
    clockedIn            = ClockedIn80,
    onClockedIn          = ClockedIn20,
    clockedInContainer   = ClockedIn30,
    onClockedInContainer = ClockedIn90,

    lunch                = Lunch80,
    onLunch              = Lunch20,
    lunchContainer       = Lunch30,
    onLunchContainer     = Lunch90,

    warning              = Warning80,
    onWarning            = Warning20,
    warningContainer     = Warning30,
    onWarningContainer   = Warning90,
)

/** Reachable as `MaterialTheme.statusColors` -- see `FoleyTimeProTheme`. */
internal val LocalStatusColors = staticCompositionLocalOf { LightStatusColors }
