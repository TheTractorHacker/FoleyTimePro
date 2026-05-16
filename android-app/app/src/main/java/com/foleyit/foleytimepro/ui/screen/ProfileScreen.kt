package com.foleyit.foleytimepro.ui.screen

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.foleyit.foleytimepro.network.ApiClient
import com.foleyit.foleytimepro.ui.theme.*
import com.foleyit.foleytimepro.utils.PrefsManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun ProfileScreen(prefs: PrefsManager, onLogout: () -> Unit) {
    val context = LocalContext.current
    val scope   = rememberCoroutineScope()
    val api     = remember { ApiClient(prefs.getInstanceUrl()) }

    val name     = prefs.getUserName()
    val email    = context.getSharedPreferences("foley_timepro", 0).getString("user_email", "") ?: ""
    val initials = name.split(" ").filter { it.isNotEmpty() }.take(2).joinToString("") { it.first().uppercase() }

    var newPw    by remember { mutableStateOf("") }
    var confirmPw by remember { mutableStateOf("") }
    var pwMsg    by remember { mutableStateOf("") }
    var pwError  by remember { mutableStateOf(false) }
    var pwLoading by remember { mutableStateOf(false) }
    var showLogoutDialog by remember { mutableStateOf(false) }

    if (showLogoutDialog) {
        AlertDialog(
            onDismissRequest = { showLogoutDialog = false },
            title = { Text("Sign Out") },
            text  = { Text("Are you sure you want to sign out?") },
            confirmButton = {
                Button(onClick = { prefs.clearSession(); onLogout() }, colors = ButtonDefaults.buttonColors(containerColor = ClockOutRed)) { Text("Sign Out") }
            },
            dismissButton = { TextButton(onClick = { showLogoutDialog = false }) { Text("Cancel") } }
        )
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = { Text("Profile", fontWeight = FontWeight.Bold, color = Color.White) },
                colors = TopAppBarDefaults.topAppBarColors(
                    containerColor = Navy,
                    titleContentColor = Color.White,
                    actionIconContentColor = Color.White,
                )
            )
        }
    ) { padding ->
        Column(modifier = Modifier.fillMaxSize().padding(padding).verticalScroll(rememberScrollState())) {

            // Avatar hero
            Box(modifier = Modifier.fillMaxWidth().background(Brush.linearGradient(listOf(Navy, NavyLight))).padding(28.dp), contentAlignment = Alignment.Center) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Box(
                        modifier = Modifier.size(80.dp).clip(CircleShape).background(Color.White.copy(alpha = 0.2f)),
                        contentAlignment = Alignment.Center
                    ) {
                        Text(initials, fontSize = 28.sp, fontWeight = FontWeight.Bold, color = Color.White)
                    }
                    Spacer(Modifier.height(14.dp))
                    Text(name, fontSize = 20.sp, fontWeight = FontWeight.Bold, color = Color.White)
                    Text(email.ifEmpty { "No email on file" }, fontSize = 13.sp, color = Color.White.copy(alpha = 0.7f), modifier = Modifier.padding(top = 3.dp))
                }
            }

            // Info card
            ProfileCard(modifier = Modifier.padding(start = 16.dp, end = 16.dp, top = 16.dp)) {
                InfoRow(label = "Company", value = prefs.getCompanyName())
                HorizontalDivider(color = DividerColor)
                InfoRow(label = "Server", value = prefs.getInstanceUrl().removePrefix("https://").removePrefix("http://"))
            }

            // Change password card
            Card(modifier = Modifier.fillMaxWidth().padding(start = 16.dp, end = 16.dp, top = 12.dp), shape = RoundedCornerShape(16.dp), colors = CardDefaults.cardColors(containerColor = Color.White), elevation = CardDefaults.cardElevation(2.dp)) {
                Column(Modifier.padding(16.dp)) {
                    Text("Change Password", fontWeight = FontWeight.Bold, fontSize = 15.sp, modifier = Modifier.padding(bottom = 12.dp))
                    OutlinedTextField(value = newPw, onValueChange = { newPw = it; pwMsg = "" }, label = { Text("New Password") }, modifier = Modifier.fillMaxWidth().padding(bottom = 10.dp), visualTransformation = PasswordVisualTransformation(), singleLine = true)
                    OutlinedTextField(value = confirmPw, onValueChange = { confirmPw = it; pwMsg = "" }, label = { Text("Confirm Password") }, modifier = Modifier.fillMaxWidth().padding(bottom = 12.dp), visualTransformation = PasswordVisualTransformation(), singleLine = true)
                    if (pwMsg.isNotEmpty()) Text(pwMsg, color = if (pwError) MaterialTheme.colorScheme.error else GreenIn, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(bottom = 8.dp))
                    Button(
                        onClick = {
                            when {
                                newPw.isEmpty()       -> { pwMsg = "Enter a new password"; pwError = true }
                                newPw != confirmPw    -> { pwMsg = "Passwords don't match"; pwError = true }
                                newPw.length < 6      -> { pwMsg = "Must be at least 6 characters"; pwError = true }
                                else -> scope.launch {
                                    pwLoading = true
                                    val r = withContext(Dispatchers.IO) { api.changePassword(prefs.getAccessToken(), newPw) }
                                    pwMsg = if (r.success) "Password updated!" else r.error
                                    pwError = !r.success
                                    if (r.success) { newPw = ""; confirmPw = "" }
                                    pwLoading = false
                                }
                            }
                        },
                        enabled = !pwLoading,
                        modifier = Modifier.fillMaxWidth().height(48.dp),
                    ) {
                        if (pwLoading) CircularProgressIndicator(Modifier.size(18.dp), color = Color.White, strokeWidth = 2.dp)
                        else Text("Update Password", fontWeight = FontWeight.Bold, color = Color.White)
                    }
                }
            }

            // Settings card
            ProfileCard(modifier = Modifier.padding(start = 16.dp, end = 16.dp, top = 12.dp)) {
                Row(modifier = Modifier.fillMaxWidth().clickable {
                    val url = "${prefs.getInstanceUrl()}/user/settings.php"
                    context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)))
                }.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Filled.Security, null, tint = TextSecondary, modifier = Modifier.padding(end = 12.dp))
                    Column(Modifier.weight(1f)) {
                        Text("Two-Factor Auth (2FA)", fontSize = 14.sp, fontWeight = FontWeight.Medium)
                        Text("Manage via web portal", fontSize = 12.sp, color = TextSecondary)
                    }
                    Icon(Icons.Filled.ChevronRight, null, tint = TextHint)
                }

                HorizontalDivider(color = DividerColor)

                Row(modifier = Modifier.fillMaxWidth().clickable {
                    prefs.clearAll(); onLogout()
                }.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(Icons.Filled.SwapHoriz, null, tint = TextSecondary, modifier = Modifier.padding(end = 12.dp))
                    Text("Change Server", fontSize = 14.sp, fontWeight = FontWeight.Medium, modifier = Modifier.weight(1f))
                    Icon(Icons.Filled.ChevronRight, null, tint = TextHint)
                }
            }

            // Sign out
            Button(
                onClick  = { showLogoutDialog = true },
                modifier = Modifier.fillMaxWidth().padding(16.dp).height(52.dp),
                colors   = ButtonDefaults.buttonColors(containerColor = ClockOutRed, contentColor = Color.White),
                shape    = RoundedCornerShape(14.dp),
            ) {
                Icon(Icons.AutoMirrored.Filled.Logout, null, modifier = Modifier.padding(end = 8.dp))
                Text("Sign Out", fontWeight = FontWeight.Bold, fontSize = 15.sp, color = Color.White)
            }

            Spacer(Modifier.height(16.dp))
        }
    }
}

@Composable
private fun ProfileCard(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Card(modifier = modifier.fillMaxWidth(), shape = RoundedCornerShape(16.dp), colors = CardDefaults.cardColors(containerColor = Color.White), elevation = CardDefaults.cardElevation(2.dp)) {
        Column(content = content)
    }
}

@Composable
private fun InfoRow(label: String, value: String) {
    Row(modifier = Modifier.fillMaxWidth().padding(horizontal = 16.dp, vertical = 14.dp), verticalAlignment = Alignment.CenterVertically) {
        Text(label, fontSize = 14.sp, color = TextSecondary, modifier = Modifier.width(80.dp))
        Text(value, fontSize = 13.sp, fontWeight = FontWeight.Medium, color = TextPrimary, modifier = Modifier.weight(1f), maxLines = 1, overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis)
    }
}
