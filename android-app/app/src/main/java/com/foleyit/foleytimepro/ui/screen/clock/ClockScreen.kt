package com.foleyit.foleytimepro.ui.screen.clock

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarDuration
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.material3.rememberTopAppBarState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.foleyit.foleytimepro.domain.model.PunchEntry
import com.foleyit.foleytimepro.ui.common.DividedColumn
import com.foleyit.foleytimepro.ui.common.FoleyTopAppBar
import com.foleyit.foleytimepro.ui.common.OfflineBanner
import com.foleyit.foleytimepro.ui.common.Pill
import com.foleyit.foleytimepro.ui.common.QueuedPill
import com.foleyit.foleytimepro.ui.common.SectionCard
import com.foleyit.foleytimepro.ui.theme.AppShapeExtras
import com.foleyit.foleytimepro.ui.theme.ClockReadoutStyle
import com.foleyit.foleytimepro.ui.theme.Spacing
import com.foleyit.foleytimepro.ui.theme.statusColors
import kotlinx.coroutines.delay
import java.util.Calendar

@Composable
fun ClockScreen(viewModel: ClockViewModel = hiltViewModel()) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val snackbarHostState = remember { SnackbarHostState() }
    val haptics = LocalHapticFeedback.current
    val scrollBehavior = TopAppBarDefaults.enterAlwaysScrollBehavior(rememberTopAppBarState())

    // Live elapsed timer, driven off the status + baseline elapsed.
    var elapsed by remember { mutableLongStateOf(0L) }
    LaunchedEffect(state.status?.status, state.status?.elapsedSeconds) {
        elapsed = state.status?.elapsedSeconds ?: 0L
        if (state.status?.status == "In") {
            while (true) { delay(1000); elapsed++ }
        }
    }

    // Punch results are one-shot, so they are consumed here and cleared, rather
    // than living on screen until the next punch overwrites them.
    LaunchedEffect(state.actionMessage) {
        val message = state.actionMessage ?: return@LaunchedEffect
        snackbarHostState.showSnackbar(
            message  = message,
            duration = if (state.actionIsError) SnackbarDuration.Long else SnackbarDuration.Short,
        )
        viewModel.onActionMessageShown()
    }

    Scaffold(
        modifier = Modifier.nestedScroll(scrollBehavior.nestedScrollConnection),
        topBar = {
            FoleyTopAppBar(
                title = state.companyName,
                scrollBehavior = scrollBehavior,
                actions = {
                    QueuedPill(state.queuedCount)
                    Spacer(Modifier.size(Spacing.sm))
                    IconButton(onClick = viewModel::refresh) {
                        Icon(Icons.Filled.Refresh, contentDescription = "Refresh")
                    }
                },
            )
        },
        snackbarHost = { SnackbarHost(snackbarHostState) },
        contentWindowInsets = WindowInsets(0, 0, 0, 0),
    ) { padding ->
        PullToRefreshBox(
            isRefreshing = state.loading,
            onRefresh    = viewModel::refresh,
            modifier     = Modifier.fillMaxSize().padding(padding),
        ) {
            Column(
                modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()),
            ) {
                OfflineBanner(visible = state.isOffline)

                StatusHero(state = state, elapsed = elapsed)

                PunchActions(
                    state = state,
                    onNoteChanged = viewModel::onNoteChanged,
                    onPunch = { action ->
                        haptics.performHapticFeedback(HapticFeedbackType.LongPress)
                        viewModel.punch(action)
                    },
                )

                if (state.recent.isNotEmpty()) {
                    SectionCard(
                        modifier = Modifier.padding(
                            start  = Spacing.screenGutter,
                            end    = Spacing.screenGutter,
                            bottom = Spacing.screenGutter,
                        ),
                        title = "This Week",
                        trailing = { Pill(text = "${state.totalHours} hrs", emphasized = true) },
                    ) {
                        DividedColumn(state.recent) { PunchRowItem(it) }
                    }
                }
            }
        }
    }
}

/**
 * The status card.
 *
 * This used to be a full-bleed linear gradient with white text hardcoded on top.
 * It is now a rounded surface on the punch-state container role, so the same
 * component reads correctly in dark mode, at high contrast, and under a
 * font-scale bump — none of which the fixed white-on-gradient version survived.
 */
@Composable
private fun StatusHero(state: ClockUiState, elapsed: Long) {
    val container by animateColorAsState(
        targetValue = when {
            state.isIn    -> MaterialTheme.statusColors.clockedInContainer
            state.isLunch -> MaterialTheme.statusColors.lunchContainer
            else          -> MaterialTheme.colorScheme.surfaceContainerHigh
        },
        label = "hero-container",
    )
    val onContainer = when {
        state.isIn    -> MaterialTheme.statusColors.onClockedInContainer
        state.isLunch -> MaterialTheme.statusColors.onLunchContainer
        else          -> MaterialTheme.colorScheme.onSurface
    }

    Surface(
        modifier = Modifier
            .fillMaxWidth()
            .padding(Spacing.screenGutter),
        shape = AppShapeExtras.extraLargeIncreased,
        color = container,
        contentColor = onContainer,
    ) {
        Column(
            modifier = Modifier.fillMaxWidth().padding(Spacing.xl),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Text(
                text  = greeting(),
                style = MaterialTheme.typography.labelLarge,
                color = onContainer.copy(alpha = 0.75f),
            )
            Text(
                text     = state.status?.name ?: "Loading…",
                style    = MaterialTheme.typography.headlineSmall,
                modifier = Modifier.padding(top = Spacing.xs, bottom = Spacing.md),
            )
            Pill(
                text = when {
                    state.isIn    -> "Clocked in"
                    state.isLunch -> "On lunch"
                    else          -> "Clocked out"
                },
                container = onContainer.copy(alpha = 0.12f),
                content   = onContainer,
                emphasized = true,
            )

            AnimatedVisibility(
                visible = !state.isOut,
                enter   = fadeIn() + expandVertically(),
                exit    = fadeOut() + shrinkVertically(),
            ) {
                Column(
                    horizontalAlignment = Alignment.CenterHorizontally,
                    modifier = Modifier.padding(top = Spacing.lg),
                ) {
                    Text(
                        text  = formatElapsed(elapsed),
                        style = ClockReadoutStyle,
                        // Screen readers should hear "3 hours 12 minutes", not
                        // the digits of a clock face read one at a time.
                        modifier = Modifier.semantics {
                            contentDescription = spokenElapsed(elapsed)
                        },
                    )
                    state.status?.clockInTime?.let {
                        Text(
                            text  = "Since $it",
                            style = MaterialTheme.typography.bodyMedium,
                            color = onContainer.copy(alpha = 0.75f),
                        )
                    }
                }
            }
        }
    }
}

/**
 * The four punch actions, in fixed positions.
 *
 * Invalid actions are disabled rather than hidden: this app is used several
 * times a day on a shared kiosk, and a button that moves depending on state
 * defeats the muscle memory that makes it fast.
 */
@Composable
private fun PunchActions(
    state: ClockUiState,
    onNoteChanged: (String) -> Unit,
    onPunch: (String) -> Unit,
) {
    Column(
        modifier = Modifier.padding(horizontal = Spacing.screenGutter),
        verticalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        OutlinedTextField(
            value = state.note,
            onValueChange = onNoteChanged,
            label = { Text("Add a note (optional)") },
            modifier = Modifier.fillMaxWidth(),
            shape = MaterialTheme.shapes.medium,
            maxLines = 2,
        )

        PunchButton(
            label     = "Clock In",
            onClick   = { onPunch("clockin") },
            enabled   = state.isOut && !state.punching,
            container = MaterialTheme.statusColors.clockedIn,
            content   = MaterialTheme.statusColors.onClockedIn,
            height    = 60.dp,
        )

        Row(horizontalArrangement = Arrangement.spacedBy(Spacing.sm)) {
            PunchButton(
                label     = "Start Lunch",
                onClick   = { onPunch("lunchstart") },
                enabled   = state.isIn && !state.punching,
                container = MaterialTheme.statusColors.lunchContainer,
                content   = MaterialTheme.statusColors.onLunchContainer,
                modifier  = Modifier.weight(1f),
            )
            PunchButton(
                label     = "End Lunch",
                onClick   = { onPunch("lunchend") },
                enabled   = state.isLunch && !state.punching,
                container = MaterialTheme.colorScheme.secondaryContainer,
                content   = MaterialTheme.colorScheme.onSecondaryContainer,
                modifier  = Modifier.weight(1f),
            )
        }

        PunchButton(
            label     = "Clock Out",
            onClick   = { onPunch("clockout") },
            enabled   = (state.isIn || state.isLunch) && !state.punching,
            container = MaterialTheme.colorScheme.error,
            content   = MaterialTheme.colorScheme.onError,
            height    = 60.dp,
        )

        Spacer(Modifier.height(Spacing.sm))
    }
}

@Composable
private fun PunchButton(
    label: String,
    onClick: () -> Unit,
    enabled: Boolean,
    container: Color,
    content: Color,
    modifier: Modifier = Modifier,
    height: androidx.compose.ui.unit.Dp = 56.dp,
) {
    Button(
        onClick  = onClick,
        enabled  = enabled,
        modifier = modifier.fillMaxWidth().height(height),
        shape    = AppShapeExtras.pill,
        colors   = ButtonDefaults.buttonColors(
            containerColor = container,
            contentColor   = content,
        ),
    ) {
        Text(label, style = MaterialTheme.typography.titleMedium)
    }
}

@Composable
private fun PunchRowItem(entry: PunchEntry) {
    Row(
        modifier = Modifier.fillMaxWidth().padding(vertical = Spacing.md),
        horizontalArrangement = Arrangement.spacedBy(Spacing.md),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(modifier = Modifier.weight(1f)) {
            Text(entry.date, style = MaterialTheme.typography.titleSmall)
            Text(
                text  = "${entry.inTime ?: "—"}  →  ${entry.outTime ?: "open"}",
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            entry.lunchStart?.let {
                Text(
                    text  = "Lunch: $it – ${entry.lunchEnd ?: "?"}",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
            if (entry.source == "offline_sync") {
                Text(
                    text  = "Synced from offline",
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.statusColors.warning,
                )
            }
        }
        entry.hours?.let {
            Pill(
                text      = "${it}h",
                container = MaterialTheme.colorScheme.primaryContainer,
                content   = MaterialTheme.colorScheme.onPrimaryContainer,
            )
        }
    }
}

private fun greeting(): String = when (Calendar.getInstance().get(Calendar.HOUR_OF_DAY)) {
    in 0..11  -> "Good morning"
    in 12..16 -> "Good afternoon"
    else      -> "Good evening"
}

private fun formatElapsed(s: Long) =
    "%02d:%02d:%02d".format(s / 3600, (s % 3600) / 60, s % 60)

private fun spokenElapsed(s: Long): String {
    val hours = s / 3600
    val minutes = (s % 3600) / 60
    return buildString {
        if (hours > 0) append("$hours hour${if (hours == 1L) "" else "s"} ")
        append("$minutes minute${if (minutes == 1L) "" else "s"} elapsed")
    }
}
