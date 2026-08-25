package com.foleyit.foleytimepro.ui.common

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.expandHorizontally
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkHorizontally
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CloudOff
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.unit.dp
import com.foleyit.foleytimepro.ui.theme.AppShapeExtras
import com.foleyit.foleytimepro.ui.theme.Spacing
import com.foleyit.foleytimepro.ui.theme.statusColors

/**
 * The one pill in the app.
 *
 * Everything that used to be a hand-rolled `Surface(RoundedCornerShape(50))` with
 * its own `.copy(alpha = 0.12f)` container now routes through here, so a status
 * chip on the Clock screen and an hours badge on the Timesheet are the same
 * object at the same size with the same contrast guarantees.
 */
@Composable
fun Pill(
    text: String,
    modifier: Modifier = Modifier,
    icon: ImageVector? = null,
    container: Color = MaterialTheme.colorScheme.secondaryContainer,
    content: Color = MaterialTheme.colorScheme.onSecondaryContainer,
    emphasized: Boolean = false,
) {
    Surface(
        modifier = modifier,
        shape    = AppShapeExtras.pill,
        color    = container,
        contentColor = content,
    ) {
        Row(
            modifier = Modifier.padding(
                PaddingValues(
                    start  = if (icon != null) Spacing.sm else Spacing.md,
                    end    = Spacing.md,
                    top    = 6.dp,
                    bottom = 6.dp,
                )
            ),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
        ) {
            if (icon != null) {
                Icon(icon, contentDescription = null, modifier = Modifier.size(16.dp))
            }
            Text(
                text  = text,
                style = if (emphasized) MaterialTheme.typography.labelLarge
                        else MaterialTheme.typography.labelMedium,
            )
        }
    }
}

/**
 * "N pending" for punches sitting in the offline queue.
 *
 * Animates in and out rather than popping, because it appears at the moment the
 * user taps a punch button and a silent layout jump there reads as a glitch.
 */
@Composable
fun QueuedPill(count: Int, modifier: Modifier = Modifier) {
    AnimatedVisibility(
        visible = count > 0,
        enter   = fadeIn() + expandHorizontally(),
        exit    = fadeOut() + shrinkHorizontally(),
        modifier = modifier,
    ) {
        Pill(
            text      = "$count pending",
            icon      = Icons.Filled.CloudOff,
            container = MaterialTheme.statusColors.warningContainer,
            content   = MaterialTheme.statusColors.onWarningContainer,
        )
    }
}
