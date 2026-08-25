package com.foleyit.foleytimepro.ui.screen.schedule

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.material3.rememberTopAppBarState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.foleyit.foleytimepro.domain.model.Shift
import com.foleyit.foleytimepro.ui.common.DividedColumn
import com.foleyit.foleytimepro.ui.common.FoleyLargeTopAppBar
import com.foleyit.foleytimepro.ui.common.KeyValueRow
import com.foleyit.foleytimepro.ui.common.OfflineBanner
import com.foleyit.foleytimepro.ui.common.Pill
import com.foleyit.foleytimepro.ui.common.SectionCard
import com.foleyit.foleytimepro.ui.theme.AppShapeExtras
import com.foleyit.foleytimepro.ui.theme.Spacing
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

private val WeekDays = listOf("Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun")

@Composable
fun ScheduleScreen(viewModel: ScheduleViewModel = hiltViewModel()) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val cachedShift by viewModel.cachedShift.collectAsStateWithLifecycle()
    val scrollBehavior = TopAppBarDefaults.enterAlwaysScrollBehavior(rememberTopAppBarState())

    // Prefer the freshly-fetched shift, fall back to the cached one for instant
    // render. A shift with a blank name is the server's way of saying "none
    // assigned", so it is folded to null here — that keeps every downstream check
    // a plain null check instead of a two-part condition the compiler cannot
    // smart-cast through.
    val shift = (state.shift ?: cachedShift)?.takeIf { it.name.isNotEmpty() }

    Scaffold(
        modifier = Modifier.nestedScroll(scrollBehavior.nestedScrollConnection),
        topBar = {
            FoleyLargeTopAppBar(
                title    = "Schedule",
                subtitle = shift?.name ?: "No shift assigned",
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

                ShiftHero(
                    shift    = shift,
                    loading  = state.loading && shift == null,
                    modifier = Modifier.padding(horizontal = Spacing.screenGutter),
                )

                SectionCard(
                    modifier = Modifier.padding(horizontal = Spacing.screenGutter),
                    title = "This Week",
                ) {
                    val today = SimpleDateFormat("EEE", Locale.US).format(Date())
                    DividedColumn(WeekDays) { day ->
                        DayRow(
                            day     = day,
                            shift   = shift,
                            isToday = day.equals(today, ignoreCase = true),
                        )
                    }
                }

                if (shift != null) {
                    SectionCard(
                        modifier = Modifier.padding(
                            start  = Spacing.screenGutter,
                            end    = Spacing.screenGutter,
                            bottom = Spacing.screenGutter,
                        ),
                        title = "Lunch Break",
                    ) {
                        KeyValueRow(
                            label = "Recommended duration",
                            value = if (shift.lunchDurationMinutes > 0)
                                        formatLunch(shift.lunchDurationMinutes) else "Not set",
                        )
                        KeyValueRow(
                            label = "Time to take lunch",
                            value = if (shift.lunchStartTime.isNotEmpty())
                                        fmt12(shift.lunchStartTime) else "Not set",
                        )
                    }
                } else {
                    Spacer(Modifier.height(Spacing.screenGutter))
                }
            }
        }
    }
}

/** The assigned shift, on the primary container so it reads as the page's anchor. */
@Composable
private fun ShiftHero(shift: Shift?, loading: Boolean, modifier: Modifier = Modifier) {
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = AppShapeExtras.extraLargeIncreased,
        color = MaterialTheme.colorScheme.primaryContainer,
        contentColor = MaterialTheme.colorScheme.onPrimaryContainer,
    ) {
        Column(Modifier.padding(Spacing.xl)) {
            Text("YOUR SHIFT", style = MaterialTheme.typography.labelMedium)
            Spacer(Modifier.height(Spacing.sm))
            when {
                loading -> CircularProgressIndicator(
                    modifier = Modifier.size(28.dp),
                    color    = MaterialTheme.colorScheme.onPrimaryContainer,
                )
                shift != null -> {
                    Text(shift.name, style = MaterialTheme.typography.headlineSmall)
                    Spacer(Modifier.height(Spacing.md))
                    Pill(
                        text = "${fmt12(shift.startTime)} – ${fmt12(shift.endTime)}",
                        container = MaterialTheme.colorScheme.onPrimaryContainer.copy(alpha = 0.12f),
                        content   = MaterialTheme.colorScheme.onPrimaryContainer,
                        emphasized = true,
                    )
                }
                else -> Text(
                    text  = "No shift assigned",
                    style = MaterialTheme.typography.titleLarge,
                )
            }
        }
    }
}

/**
 * One day of the week.
 *
 * The bar in the middle is the only thing that distinguishes a working day from
 * an off day at a glance, so it is a filled track rather than the near-invisible
 * 4dp hairline it replaces, and today's row is additionally tinted.
 */
@Composable
private fun DayRow(day: String, shift: Shift?, isToday: Boolean) {
    val isWork = shift?.daysOfWeek?.any { it.equals(day, ignoreCase = true) } == true

    Row(
        modifier = Modifier.fillMaxWidth().padding(vertical = Spacing.md),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.md),
    ) {
        Text(
            text  = day,
            style = if (isToday) MaterialTheme.typography.titleSmall
                    else MaterialTheme.typography.bodyLarge,
            color = if (isToday) MaterialTheme.colorScheme.primary
                    else MaterialTheme.colorScheme.onSurface,
            modifier = Modifier.width(44.dp),
        )
        Box(
            modifier = Modifier
                .weight(1f)
                .height(6.dp)
                .background(
                    color = when {
                        isWork && isToday -> MaterialTheme.colorScheme.primary
                        isWork            -> MaterialTheme.colorScheme.primaryContainer
                        else              -> Color.Transparent
                    },
                    shape = AppShapeExtras.pill,
                )
        )
        Text(
            text  = if (isWork && shift != null)
                        "${fmt12(shift.startTime)}–${fmt12(shift.endTime)}" else "Off",
            style = MaterialTheme.typography.bodyMedium,
            color = MaterialTheme.colorScheme.onSurfaceVariant,
        )
    }
}

private fun fmt12(t: String): String {
    if (t.isEmpty()) return ""
    return try {
        SimpleDateFormat("h:mm a", Locale.US)
            .format(SimpleDateFormat("HH:mm:ss", Locale.US).parse(t)!!)
    } catch (_: Exception) { t }
}

private fun formatLunch(minutes: Int): String = when {
    minutes <= 0      -> "None"
    minutes % 60 == 0 -> "${minutes / 60} hr${if (minutes / 60 > 1) "s" else ""}"
    minutes >= 60     -> "${minutes / 60} hr ${minutes % 60} min"
    else              -> "$minutes min"
}
