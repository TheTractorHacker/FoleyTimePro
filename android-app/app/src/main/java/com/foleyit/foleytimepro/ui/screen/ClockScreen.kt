package com.foleyit.foleytimepro.ui.screen

import androidx.compose.animation.*
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.foleyit.foleytimepro.network.*
import com.foleyit.foleytimepro.ui.theme.*
import com.foleyit.foleytimepro.utils.PrefsManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.util.Calendar

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ClockScreen(prefs: PrefsManager) {
    val scope   = rememberCoroutineScope()
    val api     = remember { ApiClient(prefs.getInstanceUrl()) }
    val token   = prefs.getAccessToken()

    var status  by remember { mutableStateOf<PunchStatus?>(null) }
    var history by remember { mutableStateOf<PunchHistory?>(null) }
    var note    by remember { mutableStateOf("") }
    var actionMsg by remember { mutableStateOf("") }
    var actionError by remember { mutableStateOf(false) }
    var punching by remember { mutableStateOf(false) }

    // Live elapsed timer
    var elapsed by remember { mutableLongStateOf(0L) }
    LaunchedEffect(status?.status, status?.elapsedSeconds) {
        elapsed = status?.elapsedSeconds ?: 0L
        if (status?.status == "In") {
            while (true) { delay(1000); elapsed++ }
        }
    }

    fun load() = scope.launch {
        val s = withContext(Dispatchers.IO) { api.getPunchStatus(token) }
        val h = withContext(Dispatchers.IO) { api.getPunchHistory(token) }
        if (s.success) status = s
        if (h.success) history = h
    }

    fun punch(action: String) = scope.launch {
        punching = true; actionMsg = ""
        val r = withContext(Dispatchers.IO) { api.punch(token, action, note) }
        note = ""; punching = false
        actionMsg = if (r.success) r.message else r.error
        actionError = !r.success
        if (r.success) load()
    }

    LaunchedEffect(Unit) { load() }

    val s = status
    val isOut   = s?.status == "Out" || s == null
    val isIn    = s?.status == "In"
    val isLunch = s?.status == "Lunch"

    // Gradient colors by status
    val gradStart = when { isIn -> GreenDark; isLunch -> OrangeDark; else -> Navy }
    val gradEnd   = when { isIn -> GreenIn;   isLunch -> OrangeLunch; else -> NavyLight }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text(prefs.getCompanyName(), fontWeight = FontWeight.Bold) },
                actions = { IconButton(onClick = { scope.launch { load() } }) { Icon(Icons.Filled.Refresh, "Refresh") } },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Navy,
                    titleContentColor = Color.White,
                    actionIconContentColor = Color.White,
                )
            )
        }
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState())
        ) {
            // ── STATUS HERO ──
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .background(Brush.linearGradient(listOf(gradStart, gradEnd)))
                    .padding(28.dp),
                contentAlignment = Alignment.Center
            ) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Text(
                        text = greeting(),
                        fontSize = 13.sp, color = Color.White.copy(alpha = 0.7f),
                        letterSpacing = 0.05.sp
                    )
                    Text(
                        text = s?.name ?: "Loading...",
                        fontSize = 26.sp, fontWeight = FontWeight.Bold, color = Color.White,
                        modifier = Modifier.padding(top = 4.dp, bottom = 12.dp)
                    )
                    Surface(
                        shape = RoundedCornerShape(20.dp),
                        color = Color.White.copy(alpha = 0.2f),
                        modifier = Modifier.padding(bottom = if (isOut) 0.dp else 16.dp)
                    ) {
                        Text(
                            text = when { isIn -> "● CLOCKED IN"; isLunch -> "● ON LUNCH"; else -> "● CLOCKED OUT" },
                            fontSize = 12.sp, fontWeight = FontWeight.Bold,
                            color = Color.White, letterSpacing = 0.08.sp,
                            modifier = Modifier.padding(horizontal = 18.dp, vertical = 6.dp)
                        )
                    }
                    if (!isOut) {
                        AnimatedVisibility(visible = true) {
                            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                                Text(
                                    text = formatElapsed(elapsed),
                                    fontSize = 48.sp, fontWeight = FontWeight.Bold,
                                    fontFamily = FontFamily.Monospace,
                                    color = Color.White,
                                )
                                s?.clockInTime?.let {
                                    Text("Since $it", fontSize = 13.sp, color = Color.White.copy(alpha = 0.7f))
                                }
                            }
                        }
                    }
                }
            }

            // ── PUNCH ACTIONS ──
            Column(modifier = Modifier.padding(16.dp)) {
                OutlinedTextField(
                    value = note,
                    onValueChange = { note = it },
                    label = { Text("Add a note (optional)") },
                    modifier = Modifier.fillMaxWidth().padding(bottom = 14.dp),
                    maxLines = 2,
                )

                // Clock In — full width
                Button(
                    onClick  = { punch("clockin") },
                    enabled  = isOut && !punching,
                    modifier = Modifier.fillMaxWidth().height(56.dp).padding(bottom = 8.dp),
                    colors   = ButtonDefaults.buttonColors(containerColor = ClockInGreen),
                    shape    = RoundedCornerShape(14.dp),
                ) { Text("Clock In", fontWeight = FontWeight.Bold, fontSize = 16.sp) }

                // Lunch row
                Row(modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(
                        onClick  = { punch("lunchstart") },
                        enabled  = isIn && !punching,
                        modifier = Modifier.weight(1f).height(52.dp),
                        colors   = ButtonDefaults.buttonColors(containerColor = LunchOrange),
                        shape    = RoundedCornerShape(14.dp),
                    ) { Text("Start Lunch", fontWeight = FontWeight.Bold) }
                    Button(
                        onClick  = { punch("lunchend") },
                        enabled  = isLunch && !punching,
                        modifier = Modifier.weight(1f).height(52.dp),
                        colors   = ButtonDefaults.buttonColors(containerColor = LunchEndBlue),
                        shape    = RoundedCornerShape(14.dp),
                    ) { Text("End Lunch", fontWeight = FontWeight.Bold) }
                }

                // Clock Out — full width
                Button(
                    onClick  = { punch("clockout") },
                    enabled  = (isIn || isLunch) && !punching,
                    modifier = Modifier.fillMaxWidth().height(56.dp),
                    colors   = ButtonDefaults.buttonColors(containerColor = ClockOutRed),
                    shape    = RoundedCornerShape(14.dp),
                ) { Text("Clock Out", fontWeight = FontWeight.Bold, fontSize = 16.sp) }

                if (punching) {
                    LinearProgressIndicator(modifier = Modifier.fillMaxWidth().padding(top = 10.dp))
                }

                if (actionMsg.isNotEmpty()) {
                    Text(
                        text = actionMsg.removePrefix("✅ ").removePrefix("🕔 ").removePrefix("🍽️ "),
                        color = if (actionError) MaterialTheme.colorScheme.error else GreenIn,
                        fontWeight = FontWeight.SemiBold,
                        modifier = Modifier.padding(top = 8.dp).fillMaxWidth(),
                        textAlign = androidx.compose.ui.text.style.TextAlign.Center,
                    )
                }
            }

            // ── THIS WEEK ──
            history?.let { h ->
                Card(
                    modifier = Modifier.fillMaxWidth().padding(start = 16.dp, end = 16.dp, bottom = 16.dp),
                    shape = RoundedCornerShape(16.dp),
                    colors = CardDefaults.cardColors(containerColor = Color.White),
                    elevation = CardDefaults.cardElevation(2.dp),
                ) {
                    Column(Modifier.padding(16.dp)) {
                        Row(
                            modifier = Modifier.fillMaxWidth().padding(bottom = 8.dp),
                            horizontalArrangement = Arrangement.SpaceBetween,
                            verticalAlignment = Alignment.CenterVertically,
                        ) {
                            Text("This Week", fontWeight = FontWeight.Bold, fontSize = 15.sp)
                            Text("${h.totalHours} hrs", fontWeight = FontWeight.Bold, color = Navy)
                        }

                        if (h.punches.isEmpty()) {
                            Text("No punches this week", color = TextHint, modifier = Modifier.padding(16.dp).fillMaxWidth(), textAlign = androidx.compose.ui.text.style.TextAlign.Center)
                        } else {
                            h.punches.take(7).forEachIndexed { i, entry ->
                                PunchRowItem(entry)
                                if (i < h.punches.size - 1) HorizontalDivider(color = DividerColor)
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
fun PunchRowItem(entry: PunchEntry) {
    Row(
        modifier = Modifier.fillMaxWidth().padding(vertical = 10.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(modifier = Modifier.weight(1f)) {
            Text(entry.date, fontWeight = FontWeight.Bold, fontSize = 13.sp)
            Text(
                "${entry.inTime ?: "—"}  →  ${entry.outTime ?: "open"}",
                fontSize = 12.sp, color = TextSecondary,
                modifier = Modifier.padding(top = 2.dp)
            )
            entry.lunchStart?.let {
                Text("Lunch: $it – ${entry.lunchEnd ?: "?"}", fontSize = 11.sp, color = TextHint, modifier = Modifier.padding(top = 2.dp))
            }
        }
        entry.hours?.let {
            Surface(
                shape = RoundedCornerShape(8.dp),
                color = Navy.copy(alpha = 0.1f),
            ) {
                Text("${it}h", fontWeight = FontWeight.Bold, fontSize = 13.sp, color = Navy, modifier = Modifier.padding(horizontal = 10.dp, vertical = 4.dp))
            }
        }
    }
}

private fun greeting(): String = when (Calendar.getInstance().get(Calendar.HOUR_OF_DAY)) {
    in 0..11  -> "Good morning"
    in 12..16 -> "Good afternoon"
    else      -> "Good evening"
}

private fun formatElapsed(s: Long) = "%02d:%02d:%02d".format(s / 3600, (s % 3600) / 60, s % 60)
