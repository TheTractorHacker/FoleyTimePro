package com.foleyit.foleytimepro.ui.screen

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.foleyit.foleytimepro.network.ApiClient
import com.foleyit.foleytimepro.network.ShiftInfo
import com.foleyit.foleytimepro.ui.theme.*
import com.foleyit.foleytimepro.utils.PrefsManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.text.SimpleDateFormat
import java.util.*

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ScheduleScreen(prefs: PrefsManager) {
    val scope = rememberCoroutineScope()
    val api   = remember { ApiClient(prefs.getInstanceUrl()) }
    var shift by remember { mutableStateOf<ShiftInfo?>(null) }
    var loading by remember { mutableStateOf(true) }

    LaunchedEffect(Unit) {
        loading = true
        val s = withContext(Dispatchers.IO) { api.getMyShift(prefs.getAccessToken()) }
        shift = s
        loading = false
    }

    val todayAbbrev = SimpleDateFormat("EEE", Locale.US).format(Date())
    val allDays = listOf("Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun")

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Schedule", fontWeight = FontWeight.Bold) },
                colors = TopAppBarDefaults.topAppBarColors(containerColor = Navy, titleContentColor = Color.White)
            )
        }
    ) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding).verticalScroll(rememberScrollState())) {
            // Shift hero card
            Box(
                modifier = Modifier.fillMaxWidth().background(Brush.linearGradient(listOf(Navy, NavyLight))).padding(24.dp)
            ) {
                Column {
                    Text("YOUR SHIFT", fontSize = 11.sp, color = Color.White.copy(alpha = 0.6f), letterSpacing = 0.1.sp)
                    Spacer(Modifier.height(6.dp))
                    if (loading) CircularProgressIndicator(color = Color.White, modifier = Modifier.size(24.dp))
                    else {
                        val s = shift
                        if (s != null && s.shiftName.isNotEmpty()) {
                            Text(s.shiftName, fontSize = 28.sp, fontWeight = FontWeight.Bold, color = Color.White, modifier = Modifier.padding(bottom = 10.dp))
                            Surface(shape = RoundedCornerShape(20.dp), color = Color.White.copy(alpha = 0.2f)) {
                                Text(
                                    "${fmt12(s.startTime)} – ${fmt12(s.endTime)}",
                                    fontSize = 14.sp, fontWeight = FontWeight.Bold, color = Color.White,
                                    modifier = Modifier.padding(horizontal = 14.dp, vertical = 6.dp)
                                )
                            }
                        } else {
                            Text("No shift assigned", fontSize = 22.sp, fontWeight = FontWeight.Bold, color = Color.White.copy(alpha = 0.7f))
                        }
                    }
                }
            }

            // Weekly schedule
            Card(
                modifier = Modifier.fillMaxWidth().padding(16.dp),
                shape = RoundedCornerShape(16.dp),
                colors = CardDefaults.cardColors(containerColor = Color.White),
                elevation = CardDefaults.cardElevation(2.dp),
            ) {
                Column(Modifier.padding(16.dp)) {
                    Text("This Week", fontWeight = FontWeight.Bold, fontSize = 15.sp, modifier = Modifier.padding(bottom = 12.dp))

                    val s = shift
                    allDays.forEachIndexed { i, day ->
                        val isWork  = s?.daysOfWeek?.any { it.equals(day, ignoreCase = true) } == true
                        val isToday = day.equals(todayAbbrev, ignoreCase = true)

                        Row(
                            modifier = Modifier.fillMaxWidth().padding(vertical = 10.dp),
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Text(
                                day,
                                fontWeight = if (isToday) FontWeight.Bold else FontWeight.Normal,
                                color = if (isToday) Navy else TextPrimary,
                                fontSize = 14.sp,
                                modifier = Modifier.width(48.dp)
                            )
                            Box(
                                modifier = Modifier.weight(1f).height(4.dp).padding(horizontal = 12.dp)
                                    .background(
                                        color = when { isToday && isWork -> Navy; isWork -> DividerColor; else -> Color.Transparent },
                                        shape = RoundedCornerShape(2.dp)
                                    )
                            )
                            Text(
                                if (isWork && s != null) "${fmt12(s.startTime)}–${fmt12(s.endTime)}" else "Off",
                                fontSize = 12.sp,
                                color = if (isWork) TextSecondary else TextHint,
                            )
                        }
                        if (i < allDays.size - 1) HorizontalDivider(color = DividerColor)
                    }
                }
            }
        }
    }
}

private fun fmt12(t: String): String {
    if (t.isEmpty()) return ""
    return try { SimpleDateFormat("h:mm a", Locale.US).format(SimpleDateFormat("HH:mm:ss", Locale.US).parse(t)!!) }
    catch (_: Exception) { t }
}
