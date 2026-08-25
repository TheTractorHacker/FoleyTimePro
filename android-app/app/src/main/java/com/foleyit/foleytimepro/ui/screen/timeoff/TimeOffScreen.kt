package com.foleyit.foleytimepro.ui.screen.timeoff

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.CalendarToday
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.DatePicker
import androidx.compose.material3.DatePickerDialog
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.material3.rememberDatePickerState
import androidx.compose.material3.rememberTopAppBarState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.foleyit.foleytimepro.domain.model.TimeOffItem
import com.foleyit.foleytimepro.ui.common.DividedColumn
import com.foleyit.foleytimepro.ui.common.EmptyState
import com.foleyit.foleytimepro.ui.common.FoleyLargeTopAppBar
import com.foleyit.foleytimepro.ui.common.LoadingState
import com.foleyit.foleytimepro.ui.common.Pill
import com.foleyit.foleytimepro.ui.common.SectionCard
import com.foleyit.foleytimepro.ui.theme.Spacing
import com.foleyit.foleytimepro.ui.theme.statusColors
import java.util.Calendar
import java.util.TimeZone

@Composable
fun TimeOffScreen(viewModel: TimeOffViewModel = hiltViewModel()) {
    val state by viewModel.state.collectAsStateWithLifecycle()
    val snackbarHostState = remember { SnackbarHostState() }
    val scrollBehavior = TopAppBarDefaults.enterAlwaysScrollBehavior(rememberTopAppBarState())
    var showDialog by remember { mutableStateOf(false) }

    // Submission results were previously a coloured card wedged above the list,
    // shifting every row down. A Snackbar says the same thing without moving the
    // content the user is reading.
    LaunchedEffect(state.statusMessage) {
        val message = state.statusMessage ?: return@LaunchedEffect
        snackbarHostState.showSnackbar(message)
        viewModel.dismissStatus()
    }

    if (showDialog) {
        TimeOffRequestDialog(
            onDismiss = { showDialog = false },
            onSubmit  = { start, end, reason ->
                showDialog = false
                viewModel.submit(start, end, reason)
            },
        )
    }

    Scaffold(
        modifier = Modifier.nestedScroll(scrollBehavior.nestedScrollConnection),
        topBar = {
            FoleyLargeTopAppBar(
                title    = "Time Off",
                subtitle = pendingSummary(state.requests),
                scrollBehavior = scrollBehavior,
            )
        },
        floatingActionButton = {
            ExtendedFloatingActionButton(
                onClick = { showDialog = true },
                icon    = { Icon(Icons.Filled.Add, contentDescription = null) },
                text    = { Text("Request") },
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
                if (state.submitting) {
                    LinearProgressIndicator(Modifier.fillMaxWidth())
                }

                SectionCard(
                    modifier = Modifier.padding(Spacing.screenGutter),
                    title = "Your Requests",
                ) {
                    when {
                        state.loading && state.requests.isEmpty() ->
                            LoadingState(caption = "Loading requests…")

                        state.requests.isEmpty() -> EmptyState(
                            title    = "No requests yet",
                            subtitle = "Tap Request to submit time off.",
                        )

                        else -> DividedColumn(state.requests) { TimeOffRow(it) }
                    }
                }

                // Clearance for the extended FAB, which floats over the content
                // rather than displacing it.
                Spacer(Modifier.height(88.dp))
            }
        }
    }
}

private fun pendingSummary(requests: List<TimeOffItem>): String {
    val pending = requests.count { it.status !in setOf("Approved", "Rejected", "Denied") }
    return when (pending) {
        0    -> "All requests reviewed"
        1    -> "1 awaiting approval"
        else -> "$pending awaiting approval"
    }
}

@Composable
private fun TimeOffRow(req: TimeOffItem) {
    Row(
        modifier = Modifier.fillMaxWidth().padding(vertical = Spacing.md),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.md),
    ) {
        Column(Modifier.weight(1f)) {
            Text(
                text  = "${isoToMDY(req.startDate)}  –  ${isoToMDY(req.endDate)}",
                style = MaterialTheme.typography.titleSmall,
            )
            Text(
                text  = req.type,
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            if (req.reason.isNotEmpty()) {
                Text(
                    text  = req.reason,
                    style = MaterialTheme.typography.bodySmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
        StatusPill(req.status)
    }
}

/**
 * Approval state.
 *
 * The old version painted saturated brand-agnostic hexes (#16A34A / #DC2626 /
 * #F97316) straight onto the row with white text. These are container roles
 * instead, so the three states stay distinguishable in dark mode and against the
 * card behind them.
 */
@Composable
private fun StatusPill(status: String) = when (status) {
    "Approved" -> Pill(
        text      = "Approved",
        container = MaterialTheme.statusColors.clockedInContainer,
        content   = MaterialTheme.statusColors.onClockedInContainer,
    )
    "Rejected", "Denied" -> Pill(
        text      = "Rejected",
        container = MaterialTheme.colorScheme.errorContainer,
        content   = MaterialTheme.colorScheme.onErrorContainer,
    )
    else -> Pill(
        text      = "Pending",
        container = MaterialTheme.statusColors.warningContainer,
        content   = MaterialTheme.statusColors.onWarningContainer,
    )
}

@Composable
private fun TimeOffRequestDialog(
    onDismiss: () -> Unit,
    onSubmit: (String, String, String) -> Unit,
) {
    var reason by remember { mutableStateOf("") }
    var showStartPicker by remember { mutableStateOf(false) }
    var showEndPicker   by remember { mutableStateOf(false) }
    val startState = rememberDatePickerState()
    val endState   = rememberDatePickerState()

    val startMillis = startState.selectedDateMillis
    val endMillis   = endState.selectedDateMillis

    val startDisplay = startMillis?.let { millisToMDY(it) } ?: ""
    val endDisplay   = endMillis?.let   { millisToMDY(it) } ?: ""
    val startApi     = startMillis?.let { millisToYMD(it) } ?: ""
    val endApi       = endMillis?.let   { millisToYMD(it) } ?: ""

    // An end date before the start date is the one input error the form can catch
    // on its own, so it does rather than waiting for the server to reject it.
    val rangeInverted = startMillis != null && endMillis != null && endMillis < startMillis

    if (showStartPicker) {
        DatePickerDialog(
            onDismissRequest = { showStartPicker = false },
            confirmButton = { TextButton(onClick = { showStartPicker = false }) { Text("OK") } },
            dismissButton = { TextButton(onClick = { showStartPicker = false }) { Text("Cancel") } },
        ) { DatePicker(state = startState) }
    }
    if (showEndPicker) {
        DatePickerDialog(
            onDismissRequest = { showEndPicker = false },
            confirmButton = { TextButton(onClick = { showEndPicker = false }) { Text("OK") } },
            dismissButton = { TextButton(onClick = { showEndPicker = false }) { Text("Cancel") } },
        ) { DatePicker(state = endState) }
    }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Request Time Off") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(Spacing.md)) {
                DateField(
                    label = "Start Date",
                    value = startDisplay,
                    onClick = { showStartPicker = true },
                )
                DateField(
                    label = "End Date",
                    value = endDisplay,
                    onClick = { showEndPicker = true },
                    isError = rangeInverted,
                    supporting = if (rangeInverted) "End date is before the start date" else null,
                )
                OutlinedTextField(
                    value = reason,
                    onValueChange = { reason = it },
                    label = { Text("Reason") },
                    modifier = Modifier.fillMaxWidth(),
                    shape = MaterialTheme.shapes.medium,
                    minLines = 2,
                )
            }
        },
        confirmButton = {
            Button(
                onClick = { onSubmit(startApi, endApi, reason) },
                enabled = startApi.isNotBlank() && endApi.isNotBlank() && !rangeInverted,
            ) { Text("Submit") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } },
    )
}

/**
 * A read-only field that opens a date picker.
 *
 * The transparent Box on top is deliberate: `OutlinedTextField` swallows clicks
 * even when `readOnly`, so an overlay is the only way to make the whole field
 * — label, value and trailing icon alike — a single tap target.
 */
@Composable
private fun DateField(
    label: String,
    value: String,
    onClick: () -> Unit,
    isError: Boolean = false,
    supporting: String? = null,
) {
    Box(Modifier.fillMaxWidth()) {
        OutlinedTextField(
            value = value,
            onValueChange = {},
            label = { Text(label) },
            placeholder = { Text("MM-DD-YYYY") },
            readOnly = true,
            isError = isError,
            supportingText = supporting?.let { { Text(it) } },
            modifier = Modifier.fillMaxWidth(),
            shape = MaterialTheme.shapes.medium,
            trailingIcon = { Icon(Icons.Filled.CalendarToday, contentDescription = null) },
        )
        Box(
            Modifier
                .matchParentSize()
                .clickable(onClick = onClick)
        )
    }
}

private fun millisToMDY(millis: Long): String {
    val cal = Calendar.getInstance(TimeZone.getTimeZone("UTC"))
    cal.timeInMillis = millis
    return "%02d-%02d-%04d".format(
        cal.get(Calendar.MONTH) + 1, cal.get(Calendar.DAY_OF_MONTH), cal.get(Calendar.YEAR)
    )
}

private fun millisToYMD(millis: Long): String {
    val cal = Calendar.getInstance(TimeZone.getTimeZone("UTC"))
    cal.timeInMillis = millis
    return "%04d-%02d-%02d".format(
        cal.get(Calendar.YEAR), cal.get(Calendar.MONTH) + 1, cal.get(Calendar.DAY_OF_MONTH)
    )
}

private fun isoToMDY(date: String): String = try {
    val p = date.split("-")
    if (p.size == 3) "${p[1]}-${p[2]}-${p[0]}" else date
} catch (_: Exception) { date }
