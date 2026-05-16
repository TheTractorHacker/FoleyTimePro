package com.foleyit.foleytimepro.ui.screen

import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.CalendarToday
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.sp
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.foleyit.foleytimepro.network.ApiClient
import com.foleyit.foleytimepro.network.TimeOffRequest
import com.foleyit.foleytimepro.ui.theme.*
import com.foleyit.foleytimepro.utils.PrefsManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.util.Calendar
import java.util.TimeZone

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TimeOffScreen(prefs: PrefsManager) {
    val scope  = rememberCoroutineScope()
    val api    = remember { ApiClient(prefs.getInstanceUrl()) }
    var requests by remember { mutableStateOf<List<TimeOffRequest>>(emptyList()) }
    var loading  by remember { mutableStateOf(true) }
    var showDialog by remember { mutableStateOf(false) }

    fun load() = scope.launch {
        loading = true
        val r = withContext(Dispatchers.IO) { api.getTimeOffRequests(prefs.getAccessToken()) }
        if (r.success) requests = r.requests
        loading = false
    }

    LaunchedEffect(Unit) { load() }

    if (showDialog) {
        TimeOffRequestDialog(
            onDismiss = { showDialog = false },
            onSubmit  = { start, end, reason ->
                scope.launch {
                    withContext(Dispatchers.IO) { api.submitTimeOffRequest(prefs.getAccessToken(), start, end, reason) }
                    load()
                }
                showDialog = false
            }
        )
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Time Off", fontWeight = FontWeight.Bold, color = Color.White) },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Navy, titleContentColor = Color.White)
            )
        },
        floatingActionButton = {
            FloatingActionButton(onClick = { showDialog = true }, containerColor = Navy) {
                Icon(Icons.Filled.Add, "Request time off", tint = Color.White)
            }
        }
    ) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding).verticalScroll(rememberScrollState()).padding(16.dp)) {
            Text("Your Requests", fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium, modifier = Modifier.padding(bottom = 4.dp))
            Text("Submitted time off requests", color = TextSecondary, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(bottom = 16.dp))

            if (loading) {
                CircularProgressIndicator(modifier = Modifier.align(Alignment.CenterHorizontally).padding(32.dp))
            } else if (requests.isEmpty()) {
                Card(modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(16.dp), colors = CardDefaults.cardColors(containerColor = Color.White), elevation = CardDefaults.cardElevation(2.dp)) {
                    Text("No time off requests yet.\nTap + to submit one.", color = TextHint, modifier = Modifier.padding(40.dp).fillMaxWidth(), textAlign = androidx.compose.ui.text.style.TextAlign.Center)
                }
            } else {
                Card(modifier = Modifier.fillMaxWidth(), shape = RoundedCornerShape(16.dp), colors = CardDefaults.cardColors(containerColor = Color.White), elevation = CardDefaults.cardElevation(2.dp)) {
                    Column(Modifier.padding(16.dp)) {
                        requests.forEachIndexed { i, req ->
                            TimeOffRow(req)
                            if (i < requests.size - 1) HorizontalDivider(color = DividerColor)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun TimeOffRow(req: TimeOffRequest) {
    Row(modifier = Modifier.fillMaxWidth().padding(vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text("${isoToMDY(req.startDate)}  –  ${isoToMDY(req.endDate)}", fontWeight = FontWeight.Bold, fontSize = 14.sp)
            Text(req.type, fontSize = 12.sp, color = TextSecondary, modifier = Modifier.padding(top = 2.dp))
            if (req.reason.isNotEmpty()) Text(req.reason, fontSize = 11.sp, color = TextHint, modifier = Modifier.padding(top = 2.dp))
        }
        val (bgColor, label) = when (req.status) {
            "Approved" -> Pair(Color(0xFF16A34A), "Approved")
            "Denied"   -> Pair(Color(0xFFDC2626), "Denied")
            else       -> Pair(Color(0xFFF97316), "Pending")
        }
        Surface(shape = RoundedCornerShape(12.dp), color = bgColor) {
            Text(label, fontSize = 11.sp, fontWeight = FontWeight.Bold, color = Color.White, modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp))
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun TimeOffRequestDialog(onDismiss: () -> Unit, onSubmit: (String, String, String) -> Unit) {
    var reason          by remember { mutableStateOf("") }
    var showStartPicker by remember { mutableStateOf(false) }
    var showEndPicker   by remember { mutableStateOf(false) }
    val startState = rememberDatePickerState()
    val endState   = rememberDatePickerState()

    val startDisplay = startState.selectedDateMillis?.let { millisToMDY(it) } ?: ""
    val endDisplay   = endState.selectedDateMillis?.let   { millisToMDY(it) } ?: ""
    val startApi     = startState.selectedDateMillis?.let { millisToYMD(it) } ?: ""
    val endApi       = endState.selectedDateMillis?.let   { millisToYMD(it) } ?: ""

    if (showStartPicker) {
        DatePickerDialog(
            onDismissRequest = { showStartPicker = false },
            confirmButton    = { TextButton(onClick = { showStartPicker = false }) { Text("OK") } },
            dismissButton    = { TextButton(onClick = { showStartPicker = false }) { Text("Cancel") } },
        ) { DatePicker(state = startState) }
    }
    if (showEndPicker) {
        DatePickerDialog(
            onDismissRequest = { showEndPicker = false },
            confirmButton    = { TextButton(onClick = { showEndPicker = false }) { Text("OK") } },
            dismissButton    = { TextButton(onClick = { showEndPicker = false }) { Text("Cancel") } },
        ) { DatePicker(state = endState) }
    }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Request Time Off", fontWeight = FontWeight.Bold) },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                Box(Modifier.fillMaxWidth()) {
                    OutlinedTextField(
                        value = startDisplay,
                        onValueChange = {},
                        label = { Text("Start Date") },
                        placeholder = { Text("MM-DD-YYYY") },
                        readOnly = true,
                        modifier = Modifier.fillMaxWidth(),
                        trailingIcon = { Icon(Icons.Filled.CalendarToday, null) },
                    )
                    Box(Modifier.matchParentSize().clickable { showStartPicker = true })
                }
                Box(Modifier.fillMaxWidth()) {
                    OutlinedTextField(
                        value = endDisplay,
                        onValueChange = {},
                        label = { Text("End Date") },
                        placeholder = { Text("MM-DD-YYYY") },
                        readOnly = true,
                        modifier = Modifier.fillMaxWidth(),
                        trailingIcon = { Icon(Icons.Filled.CalendarToday, null) },
                    )
                    Box(Modifier.matchParentSize().clickable { showEndPicker = true })
                }
                OutlinedTextField(
                    value = reason,
                    onValueChange = { reason = it },
                    label = { Text("Reason") },
                    modifier = Modifier.fillMaxWidth(),
                    minLines = 2,
                )
            }
        },
        confirmButton = {
            Button(
                onClick  = { onSubmit(startApi, endApi, reason) },
                enabled  = startApi.isNotBlank() && endApi.isNotBlank(),
            ) { Text("Submit", color = Color.White) }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } }
    )
}

private fun millisToMDY(millis: Long): String {
    val cal = Calendar.getInstance(TimeZone.getTimeZone("UTC"))
    cal.timeInMillis = millis
    return "%02d-%02d-%04d".format(cal.get(Calendar.MONTH) + 1, cal.get(Calendar.DAY_OF_MONTH), cal.get(Calendar.YEAR))
}

private fun millisToYMD(millis: Long): String {
    val cal = Calendar.getInstance(TimeZone.getTimeZone("UTC"))
    cal.timeInMillis = millis
    return "%04d-%02d-%02d".format(cal.get(Calendar.YEAR), cal.get(Calendar.MONTH) + 1, cal.get(Calendar.DAY_OF_MONTH))
}

private fun isoToMDY(date: String): String = try {
    val p = date.split("-")
    if (p.size == 3) "${p[1]}-${p[2]}-${p[0]}" else date
} catch (_: Exception) { date }
