package com.foleyit.foleytimepro.ui.screen

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.foleyit.foleytimepro.network.ApiClient
import com.foleyit.foleytimepro.ui.theme.*
import com.foleyit.foleytimepro.utils.PrefsManager
import kotlinx.coroutines.launch

@Composable
fun SetupScreen(prefs: PrefsManager, onConnected: () -> Unit) {
    val scope   = rememberCoroutineScope()
    var url     by remember { mutableStateOf("") }
    var loading by remember { mutableStateOf(false) }
    var error   by remember { mutableStateOf("") }
    var company by remember { mutableStateOf("") }
    var verified by remember { mutableStateOf(false) }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Text("FoleyTimePro", fontSize = 32.sp, fontWeight = FontWeight.Bold, color = Navy)
        Text("by Foley IT", fontSize = 13.sp, color = TextSecondary, modifier = Modifier.padding(top = 4.dp, bottom = 48.dp))

        Text("Connect to your server", style = MaterialTheme.typography.titleMedium, modifier = Modifier.fillMaxWidth())
        Text("Enter the URL of your FoleyTimePro instance", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.fillMaxWidth().padding(top = 4.dp, bottom = 20.dp))

        OutlinedTextField(
            value = url,
            onValueChange = { url = it; verified = false; error = "" },
            label = { Text("Server URL") },
            placeholder = { Text("https://time.yourcompany.com") },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri, imeAction = ImeAction.Go),
            keyboardActions = KeyboardActions(onGo = {
                if (!loading) scope.launch { connect(url, prefs) { c, e -> company = c; error = e; verified = c.isNotEmpty() } }
            }),
            isError = error.isNotEmpty(),
            supportingText = if (error.isNotEmpty()) {{ Text(error, color = MaterialTheme.colorScheme.error) }} else null,
        )

        Spacer(Modifier.height(16.dp))

        if (verified) {
            Card(
                colors = CardDefaults.cardColors(containerColor = Navy.copy(alpha = 0.08f)),
                modifier = Modifier.fillMaxWidth()
            ) {
                Column(Modifier.padding(16.dp)) {
                    Text("Connected to", style = MaterialTheme.typography.bodySmall)
                    Text(company, style = MaterialTheme.typography.titleMedium, color = Navy)
                }
            }
            Spacer(Modifier.height(16.dp))
            Button(onClick = onConnected, modifier = Modifier.fillMaxWidth().height(52.dp)) {
                Text("Continue to Sign In →", fontWeight = FontWeight.Bold)
            }
        } else {
            Button(
                onClick = {
                    scope.launch {
                        loading = true; error = ""
                        connect(url, prefs) { c, e -> company = c; error = e; verified = c.isNotEmpty() }
                        loading = false
                    }
                },
                enabled  = url.isNotBlank() && !loading,
                modifier = Modifier.fillMaxWidth().height(52.dp),
            ) {
                if (loading) CircularProgressIndicator(Modifier.size(20.dp), color = MaterialTheme.colorScheme.onPrimary, strokeWidth = 2.dp)
                else Text("Connect", fontWeight = FontWeight.Bold)
            }
        }
    }
}

private suspend fun connect(rawUrl: String, prefs: PrefsManager, result: (String, String) -> Unit) {
    val url = if (rawUrl.startsWith("http")) rawUrl.trimEnd('/') else "https://$rawUrl".trimEnd('/')
    val r   = kotlinx.coroutines.withContext(kotlinx.coroutines.Dispatchers.IO) {
        ApiClient(url).fetchBranding()
    }
    if (r.success) {
        prefs.setInstanceUrl(url)
        prefs.saveCompanyName(r.companyName)
        result(r.companyName, "")
    } else {
        result("", r.error.ifEmpty { "Could not connect to server" })
    }
}
