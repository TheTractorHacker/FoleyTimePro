package com.foleyit.foleytimepro.ui.theme

import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Shapes
import androidx.compose.ui.unit.dp

/**
 * The Material 3 shape scale. Components read these through `MaterialTheme.shapes`
 * instead of each call site inventing its own `RoundedCornerShape(14.dp)`.
 */
val AppShapes = Shapes(
    extraSmall = RoundedCornerShape(4.dp),
    small      = RoundedCornerShape(8.dp),
    medium     = RoundedCornerShape(12.dp),
    large      = RoundedCornerShape(16.dp),
    extraLarge = RoundedCornerShape(28.dp),
)

/**
 * Expressive corner sizes that material3 1.4.0 defines internally but does not
 * expose on [Shapes]. Kept here so the hero surfaces can use the larger, softer
 * expressive radii without hand-typing dp at the call site.
 */
object AppShapeExtras {
    val largeIncreased      = RoundedCornerShape(20.dp)
    val extraLargeIncreased = RoundedCornerShape(32.dp)
    val extraExtraLarge     = RoundedCornerShape(48.dp)

    /** A fully-rounded pill, for chips, badges and the punch buttons. */
    val pill = RoundedCornerShape(percent = 50)
}

/** Vertical rhythm. One place to change the app's density. */
object Spacing {
    val xs = 4.dp
    val sm = 8.dp
    val md = 12.dp
    val lg = 16.dp
    val xl = 24.dp
    val xxl = 32.dp

    /** Gutter between a screen's content and its edges. */
    val screenGutter = 16.dp
}
