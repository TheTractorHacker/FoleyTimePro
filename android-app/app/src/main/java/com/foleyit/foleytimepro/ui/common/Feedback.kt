package com.foleyit.foleytimepro.ui.common

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.expandVertically
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CloudOff
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material.icons.outlined.ErrorOutline
import androidx.compose.material.icons.outlined.Inbox
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import com.foleyit.foleytimepro.ui.theme.Spacing
import com.foleyit.foleytimepro.ui.theme.statusColors

/**
 * Spinner with an optional caption.
 *
 * Sizes to its content by default so it can sit inside a card in a scrolling
 * column; pass `Modifier.fillMaxSize()` to centre it on an otherwise empty screen.
 */
@Composable
fun LoadingState(
    modifier: Modifier = Modifier,
    caption: String? = null,
) {
    Column(
        modifier = modifier.fillMaxWidth().padding(Spacing.xl),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        CircularProgressIndicator()
        if (!caption.isNullOrBlank()) {
            Spacer(Modifier.height(Spacing.md))
            Text(
                text  = caption,
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
        }
    }
}

/**
 * Shared shape for the "there is nothing here" and "that went wrong" states, so
 * an empty timesheet and a failed load are recognisably the same kind of screen.
 */
@Composable
private fun Placeholder(
    icon: ImageVector,
    iconTint: Color,
    title: String,
    body: String?,
    modifier: Modifier = Modifier,
    onRetry: (() -> Unit)? = null,
) {
    Column(
        modifier = modifier.fillMaxWidth().padding(horizontal = Spacing.xl, vertical = Spacing.xxl),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Icon(icon, contentDescription = null, tint = iconTint, modifier = Modifier.size(40.dp))
        Spacer(Modifier.height(Spacing.lg))
        Text(
            text      = title,
            style     = MaterialTheme.typography.titleMedium,
            color     = MaterialTheme.colorScheme.onSurface,
            textAlign = TextAlign.Center,
        )
        if (!body.isNullOrBlank()) {
            Spacer(Modifier.height(Spacing.xs))
            Text(
                text      = body,
                style     = MaterialTheme.typography.bodyMedium,
                color     = MaterialTheme.colorScheme.onSurfaceVariant,
                textAlign = TextAlign.Center,
            )
        }
        if (onRetry != null) {
            Spacer(Modifier.height(Spacing.lg))
            Button(onClick = onRetry) { Text("Try again") }
        }
    }
}

/** Empty placeholder for lists with no data. */
@Composable
fun EmptyState(
    title: String,
    modifier: Modifier = Modifier,
    subtitle: String? = null,
) = Placeholder(
    icon     = Icons.Outlined.Inbox,
    iconTint = MaterialTheme.colorScheme.onSurfaceVariant,
    title    = title,
    body     = subtitle,
    modifier = modifier,
)

/** Error placeholder with an optional retry. */
@Composable
fun ErrorState(
    message: String,
    modifier: Modifier = Modifier,
    onRetry: (() -> Unit)? = null,
) = Placeholder(
    icon     = Icons.Outlined.ErrorOutline,
    iconTint = MaterialTheme.colorScheme.error,
    title    = "Something went wrong",
    body     = message,
    modifier = modifier,
    onRetry  = onRetry,
)

/**
 * The banner strip used for offline and for device-integrity warnings.
 *
 * Both are "keep working, but know this" messages, so they share one shape and
 * differ only in role colors — which is also why neither is a Snackbar: a
 * Snackbar is transient and these conditions persist.
 */
@Composable
private fun Banner(
    icon: ImageVector,
    text: String,
    container: Color,
    content: Color,
    modifier: Modifier = Modifier,
) {
    Surface(
        modifier = modifier.fillMaxWidth(),
        color    = container,
        contentColor = content,
    ) {
        Row(
            modifier = Modifier.padding(horizontal = Spacing.lg, vertical = Spacing.md),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(Spacing.md),
        ) {
            Icon(icon, contentDescription = null, modifier = Modifier.size(20.dp))
            Text(text, style = MaterialTheme.typography.bodyMedium)
        }
    }
}

/** Slim strip shown at the top of a screen while the device has no connection. */
@Composable
fun OfflineBanner(visible: Boolean, modifier: Modifier = Modifier) {
    AnimatedVisibility(
        visible = visible,
        enter   = expandVertically() + fadeIn(),
        exit    = shrinkVertically() + fadeOut(),
        modifier = modifier,
    ) {
        Banner(
            icon      = Icons.Filled.CloudOff,
            text      = "Offline — actions will sync when you reconnect",
            container = MaterialTheme.statusColors.warningContainer,
            content   = MaterialTheme.statusColors.onWarningContainer,
        )
    }
}

/** Root / emulator / insecure-device warning. */
@Composable
fun WarningStrip(text: String, modifier: Modifier = Modifier) = Banner(
    icon      = Icons.Filled.Warning,
    text      = text,
    container = MaterialTheme.colorScheme.errorContainer,
    content   = MaterialTheme.colorScheme.onErrorContainer,
    modifier  = modifier,
)
