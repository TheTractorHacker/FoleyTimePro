package com.foleyit.foleytimepro.ui.common

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.foleyit.foleytimepro.ui.theme.Spacing

/**
 * A titled card.
 *
 * Material 3 separates surfaces by tone, not by shadow, so this sits on
 * `surfaceContainerLow` at zero elevation instead of the 2dp drop-shadow the
 * previous cards used. On a wall-mounted kiosk at a shallow viewing angle the
 * tonal step stays visible where a shadow washes out.
 */
@Composable
fun SectionCard(
    modifier: Modifier = Modifier,
    title: String? = null,
    trailing: @Composable (() -> Unit)? = null,
    contentPadding: Boolean = true,
    content: @Composable ColumnScope.() -> Unit,
) {
    Card(
        modifier = modifier.fillMaxWidth(),
        shape    = MaterialTheme.shapes.large,
        colors   = CardDefaults.cardColors(
            containerColor = MaterialTheme.colorScheme.surfaceContainerLow,
        ),
        elevation = CardDefaults.cardElevation(defaultElevation = 0.dp),
    ) {
        Column(Modifier.padding(if (contentPadding) Spacing.lg else 0.dp)) {
            if (title != null) {
                SectionHeader(
                    title = title,
                    trailing = trailing,
                    modifier = Modifier.padding(
                        start  = if (contentPadding) 0.dp else Spacing.lg,
                        end    = if (contentPadding) 0.dp else Spacing.lg,
                        top    = if (contentPadding) 0.dp else Spacing.lg,
                        bottom = Spacing.sm,
                    ),
                )
            }
            content()
        }
    }
}

/** Card title row, with room for a value or action on the right. */
@Composable
fun SectionHeader(
    title: String,
    modifier: Modifier = Modifier,
    trailing: @Composable (() -> Unit)? = null,
) {
    Row(
        modifier = modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment     = Alignment.CenterVertically,
    ) {
        Text(
            text  = title,
            style = MaterialTheme.typography.titleMedium,
            color = MaterialTheme.colorScheme.onSurface,
        )
        trailing?.invoke()
    }
}

/** Label on the left, value on the right. Used for read-only facts. */
@Composable
fun KeyValueRow(
    label: String,
    value: String,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier.fillMaxWidth().padding(vertical = Spacing.md),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.lg),
    ) {
        Text(
            text  = label,
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
        Text(
            text     = value,
            style    = MaterialTheme.typography.bodyMedium,
            color    = MaterialTheme.colorScheme.onSurface,
            modifier = Modifier.weight(1f),
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            textAlign = androidx.compose.ui.text.style.TextAlign.End,
        )
    }
}

/**
 * The hairline between rows inside a card. `outlineVariant` is the Material role
 * for exactly this and it tracks light/dark on its own, which the old fixed
 * `DividerColor` did not.
 */
@Composable
fun RowDivider(modifier: Modifier = Modifier) {
    HorizontalDivider(
        modifier = modifier,
        color    = MaterialTheme.colorScheme.outlineVariant,
    )
}

/** Lays out `items` with a [RowDivider] between each pair, but not at the ends. */
@Composable
fun <T> DividedColumn(
    items: List<T>,
    modifier: Modifier = Modifier,
    row: @Composable (T) -> Unit,
) {
    Column(modifier) {
        items.forEachIndexed { index, item ->
            row(item)
            if (index < items.lastIndex) RowDivider()
        }
    }
}
