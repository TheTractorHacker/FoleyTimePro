package com.foleyit.foleytimepro.ui.screen

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.ArrowForward
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.foleyit.foleytimepro.network.ApiClient
import com.foleyit.foleytimepro.network.PunchEntry
import com.foleyit.foleytimepro.ui.theme.*
import com.foleyit.foleytimepro.utils.PrefsManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.text.SimpleDateFormat
import java.util.*

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun TimesheetScreen(prefs: PrefsManager) {
    val scope  = rememberCoroutineScope()
    val api    = remember { ApiClient(prefs.getInstanceUrl()) }
    var punches by remember { mutableStateOf<List<PunchEntry>>(emptyList()) }
    var totalHours by remember { mutableStateOf("0.00") }
    var weekOffset by remember { mutableIntStateOf(0) }
    var loading by remember { mutableStateOf(true) }

    val weekLabel = remember(weekOffset) { weekLabel(weekOffset) }

    fun load() = scope.launch {
        loading = true
        val (start, end) = weekDates(weekOffset)
        val h = withContext(Dispatchers.IO) { api.getPunchHistory(prefs.getAccessToken(), start, end) }
        if (h.success) { punches = h.punches; totalHours = h.totalHours }
        loading = false
    }

    LaunchedEffect(weekOffset) { load() }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Timesheet", fontWeight = FontWeight.Bold, color = Color.White) },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Navy, titleContentColor = Color.White)
            )
        }
    ) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding).verticalScroll(rememberScrollState())) {

            // Week navigation card
            Card(
                modifier = Modifier.fillMaxWidth().padding(16.dp),
                shape = RoundedCornerShape(16.dp),
                colors = CardDefaults.cardColors(containerColor = Color.White),
                elevation = CardDefaults.cardElevation(2.dp),
            ) {
                Row(
                    modifier = Modifier.fillMaxWidth().padding(12.dp),
                    horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    IconButton(onClick = { weekOffset-- }) {
                        Icon(Icons.AutoMirrored.Filled.ArrowBack, "Previous", tint = Navy)
                    }
                    Column(horizontalAlignment = Alignment.CenterHorizontally) {
                        Text(weekLabel, fontWeight = FontWeight.Bold, fontSize = 15.sp)
                        Text("$totalHours hrs total", color = Navy, fontWeight = FontWeight.Bold, fontSize = 13.sp)
                    }
                    IconButton(onClick = { if (weekOffset < 0) weekOffset++ }, enabled = weekOffset < 0) {
                        Icon(Icons.AutoMirrored.Filled.ArrowForward, "Next", tint = if (weekOffset < 0) Navy else TextHint)
                    }
                }
            }

            // Punch list card
            Card(
                modifier = Modifier.fillMaxWidth().padding(start = 16.dp, end = 16.dp, bottom = 16.dp),
                shape = RoundedCornerShape(16.dp),
                colors = CardDefaults.cardColors(containerColor = Color.White),
                elevation = CardDefaults.cardElevation(2.dp),
            ) {
                Column(Modifier.padding(16.dp)) {
                    Text("Punch Details", fontWeight = FontWeight.Bold, fontSize = 15.sp, modifier = Modifier.padding(bottom = 12.dp))

                    if (loading) {
                        CircularProgressIndicator(modifier = Modifier.align(Alignment.CenterHorizontally).padding(24.dp))
                    } else if (punches.isEmpty()) {
                        val emptyMsg = if (weekOffset == 0)
                            "No punches this week yet.\nClock in to get started."
                        else
                            "No punches recorded for this week."
                        Text(emptyMsg, color = TextHint, modifier = Modifier.fillMaxWidth().padding(24.dp), textAlign = androidx.compose.ui.text.style.TextAlign.Center)
                    } else {
                        punches.forEachIndexed { i, entry ->
                            TimesheetRow(entry)
                            if (i < punches.size - 1) HorizontalDivider(color = DividerColor)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun TimesheetRow(entry: PunchEntry) {
    Row(
        modifier = Modifier.fillMaxWidth().padding(vertical = 12.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text(entry.date, fontWeight = FontWeight.Bold, fontSize = 14.sp)
            Text("In: ${entry.inTime ?: "—"}   Out: ${entry.outTime ?: "—"}", fontSize = 12.sp, color = TextSecondary, modifier = Modifier.padding(top = 3.dp))
            entry.lunchStart?.let {
                Text("Lunch: $it – ${entry.lunchEnd ?: "?"}", fontSize = 11.sp, color = TextHint, modifier = Modifier.padding(top = 2.dp))
            }
        }
        Surface(shape = RoundedCornerShape(8.dp), color = Navy.copy(alpha = 0.1f)) {
            Text(
                text = if (entry.outTime != null) "${entry.hours ?: "?"} hrs" else "open",
                fontWeight = FontWeight.Bold, fontSize = 13.sp,
                color = if (entry.outTime != null) Navy else GreenIn,
                modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp)
            )
        }
    }
}

private fun weekDates(offset: Int): Pair<String, String> {
    val iso = SimpleDateFormat("yyyy-MM-dd", Locale.US)
    val cal = Calendar.getInstance()
    cal.add(Calendar.WEEK_OF_YEAR, offset)
    cal.set(Calendar.DAY_OF_WEEK, Calendar.MONDAY)
    val start = iso.format(cal.time)
    cal.add(Calendar.DAY_OF_MONTH, 6)
    val end = iso.format(cal.time)
    return Pair(start, end)
}

private fun weekLabel(offset: Int): String {
    val cal = Calendar.getInstance()
    cal.add(Calendar.WEEK_OF_YEAR, offset)
    cal.set(Calendar.DAY_OF_WEEK, Calendar.MONDAY)
    val fmt = SimpleDateFormat("MMM d", Locale.US)
    val end = cal.clone() as Calendar; end.add(Calendar.DAY_OF_MONTH, 6)
    return if (offset == 0) "This Week  •  ${fmt.format(cal.time)} – ${fmt.format(end.time)}"
    else "${fmt.format(cal.time)} – ${SimpleDateFormat("MMM d, yyyy", Locale.US).format(end.time)}"
}
