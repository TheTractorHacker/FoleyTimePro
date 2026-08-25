package com.foleyit.foleytimepro.ui.screen.timesheet

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.ArrowForward
import androidx.compose.material3.FilledTonalIconButton
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.material3.rememberTopAppBarState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.foleyit.foleytimepro.domain.model.PunchEntry
import com.foleyit.foleytimepro.ui.common.DividedColumn
import com.foleyit.foleytimepro.ui.common.EmptyState
import com.foleyit.foleytimepro.ui.common.FoleyLargeTopAppBar
import com.foleyit.foleytimepro.ui.common.LoadingState
import com.foleyit.foleytimepro.ui.common.OfflineBanner
import com.foleyit.foleytimepro.ui.common.Pill
import com.foleyit.foleytimepro.ui.common.SectionCard
import com.foleyit.foleytimepro.ui.theme.Spacing
import com.foleyit.foleytimepro.ui.theme.statusColors

@Composable
fun TimesheetScreen(viewModel: TimesheetViewModel = hiltViewModel()) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val scrollBehavior = TopAppBarDefaults.enterAlwaysScrollBehavior(rememberTopAppBarState())

    Scaffold(
        modifier = Modifier.nestedScroll(scrollBehavior.nestedScrollConnection),
        topBar = {
            FoleyLargeTopAppBar(
                title    = "Timesheet",
                subtitle = "${state.totalHours} hrs this week",
                scrollBehavior = scrollBehavior,
            )
        },
        contentWindowInsets = WindowInsets(0, 0, 0, 0),
    ) { padding ->
        PullToRefreshBox(
            isRefreshing = state.loading,
            onRefresh    = viewModel::refresh,
            modifier     = Modifier.fillMaxSize().padding(padding),
        ) {
            Column(
                modifier = Modifier.fillMaxSize().verticalScroll(rememberScrollState()),
                verticalArrangement = Arrangement.spacedBy(Spacing.md),
            ) {
                OfflineBanner(visible = state.isOffline)

                WeekSelector(
                    label       = state.weekLabel,
                    totalHours  = state.totalHours,
                    canGoForward = state.canGoForward,
                    onPrevious  = viewModel::previousWeek,
                    onNext      = viewModel::nextWeek,
                    modifier    = Modifier.padding(horizontal = Spacing.screenGutter),
                )

                SectionCard(
                    modifier = Modifier.padding(
                        start  = Spacing.screenGutter,
                        end    = Spacing.screenGutter,
                        bottom = Spacing.screenGutter,
                    ),
                    title = "Punch Details",
                ) {
                    when {
                        state.loading && state.entries.isEmpty() ->
                            LoadingState(caption = "Loading punches…")

                        state.entries.isEmpty() -> EmptyState(
                            title = if (state.weekOffset == 0) "No punches this week yet"
                                    else "Nothing recorded this week",
                            subtitle = if (state.weekOffset == 0) "Clock in to get started."
                                       else null,
                        )

                        else -> DividedColumn(state.entries) { TimesheetRow(it) }
                    }
                }
            }
        }
    }
}

/**
 * Week paging.
 *
 * The forward control is disabled rather than hidden at the current week, so the
 * two arrows keep their positions and the row does not reflow every time you
 * page back to today.
 */
@Composable
private fun WeekSelector(
    label: String,
    totalHours: String,
    canGoForward: Boolean,
    onPrevious: () -> Unit,
    onNext: () -> Unit,
    modifier: Modifier = Modifier,
) {
    SectionCard(modifier = modifier, contentPadding = false) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(Spacing.sm),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment     = Alignment.CenterVertically,
        ) {
            FilledTonalIconButton(onClick = onPrevious) {
                Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "Previous week")
            }
            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                Text(label, style = MaterialTheme.typography.titleMedium)
                Text(
                    text  = "$totalHours hrs total",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.primary,
                )
            }
            FilledTonalIconButton(onClick = onNext, enabled = canGoForward) {
                Icon(Icons.AutoMirrored.Filled.ArrowForward, contentDescription = "Next week")
            }
        }
    }
}

@Composable
private fun TimesheetRow(entry: PunchEntry) {
    val open = entry.outTime == null
    Row(
        modifier = Modifier.fillMaxWidth().padding(vertical = Spacing.md),
        horizontalArrangement = Arrangement.spacedBy(Spacing.md),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text(entry.date, style = MaterialTheme.typography.titleSmall)
            Text(
                text  = "In: ${entry.inTime ?: "—"}   Out: ${entry.outTime ?: "—"}",
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
        }
        Pill(
            text      = if (open) "Open" else "${entry.hours ?: "?"} hrs",
            container = if (open) MaterialTheme.statusColors.clockedInContainer
                        else MaterialTheme.colorScheme.primaryContainer,
            content   = if (open) MaterialTheme.statusColors.onClockedInContainer
                        else MaterialTheme.colorScheme.onPrimaryContainer,
        )
    }
}
