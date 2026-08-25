package com.foleyit.foleytimepro.ui.common

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.RowScope
import androidx.compose.material3.LargeTopAppBar
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.TopAppBarScrollBehavior
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.style.TextOverflow

/**
 * The app bars.
 *
 * Both bars sit on `surface` and shift to `surfaceContainer` once content
 * scrolls under them — the Material 3 way of showing that something is behind
 * the bar. The previous bars were a flat, hardcoded navy block that gave no such
 * signal and forced white-on-navy text regardless of the active theme.
 *
 * [FoleyLargeTopAppBar] renders its subtitle as a second `Text` inside the
 * `title` slot rather than using `LargeFlexibleTopAppBar`'s dedicated
 * `subtitle` parameter: that parameter, and the whole Flexible app bar family,
 * is still `internal` in material3 1.4.0 (confirmed against the resolved
 * dependency with a standalone compile probe) and only becomes public API in
 * the still-alpha 1.5.x line. The stable [LargeTopAppBar] achieves the same
 * two-line look through its plain `title` slot.
 */

/** Compact, always-visible bar. For screens whose own content is the headline. */
@Composable
fun FoleyTopAppBar(
    title: String,
    modifier: Modifier = Modifier,
    scrollBehavior: TopAppBarScrollBehavior? = null,
    actions: @Composable RowScope.() -> Unit = {},
) {
    TopAppBar(
        title = {
            Text(title, maxLines = 1, overflow = TextOverflow.Ellipsis)
        },
        modifier = modifier,
        actions  = actions,
        colors   = TopAppBarDefaults.topAppBarColors(
            containerColor         = MaterialTheme.colorScheme.surface,
            scrolledContainerColor = MaterialTheme.colorScheme.surfaceContainer,
            titleContentColor      = MaterialTheme.colorScheme.onSurface,
            actionIconContentColor = MaterialTheme.colorScheme.onSurfaceVariant,
        ),
        scrollBehavior = scrollBehavior,
    )
}

/**
 * Tall bar that collapses as the page scrolls, with an optional second line
 * under the title. Used on the list screens, where the extra height at rest
 * gives the section a name without spending a card on it.
 */
@Composable
fun FoleyLargeTopAppBar(
    title: String,
    modifier: Modifier = Modifier,
    subtitle: String? = null,
    scrollBehavior: TopAppBarScrollBehavior? = null,
    actions: @Composable RowScope.() -> Unit = {},
) {
    LargeTopAppBar(
        title = {
            Column {
                Text(title, maxLines = 1, overflow = TextOverflow.Ellipsis)
                if (subtitle != null) {
                    Text(
                        text     = subtitle,
                        style    = MaterialTheme.typography.bodyMedium,
                        color    = MaterialTheme.colorScheme.onSurfaceVariant,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
            }
        },
        modifier = modifier,
        actions  = actions,
        colors   = TopAppBarDefaults.topAppBarColors(
            containerColor         = MaterialTheme.colorScheme.surface,
            scrolledContainerColor = MaterialTheme.colorScheme.surfaceContainer,
            titleContentColor      = MaterialTheme.colorScheme.onSurface,
            actionIconContentColor = MaterialTheme.colorScheme.onSurfaceVariant,
        ),
        scrollBehavior = scrollBehavior,
    )
}
