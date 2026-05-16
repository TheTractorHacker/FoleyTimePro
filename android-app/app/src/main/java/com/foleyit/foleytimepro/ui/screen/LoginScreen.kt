package com.foleyit.foleytimepro.ui.screen

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.*
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.foleyit.foleytimepro.network.ApiClient
import com.foleyit.foleytimepro.ui.theme.*
import com.foleyit.foleytimepro.utils.PrefsManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

@Composable
fun LoginScreen(prefs: PrefsManager, onLoggedIn: () -> Unit) {
    val scope    = rememberCoroutineScope()
    var email    by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var twofa    by remember { mutableStateOf("") }
    var showPw   by remember { mutableStateOf(false) }
    var needs2FA by remember { mutableStateOf(false) }
    var loading  by remember { mutableStateOf(false) }
    var error    by remember { mutableStateOf("") }

    fun doLogin() {
        scope.launch {
            loading = true; error = ""
            val r = withContext(Dispatchers.IO) {
                ApiClient(prefs.getInstanceUrl()).login(email, password, twofa.takeIf { needs2FA && it.isNotBlank() })
            }
            loading = false
            when {
                r.success -> {
                    prefs.saveTokens(r.accessToken, r.refreshToken, r.expiresIn)
                    prefs.saveUser(r.userId, r.email, r.firstName, r.lastName)
                    onLoggedIn()
                }
                r.requires2FA -> needs2FA = true
                else          -> error = r.error
            }
        }
    }

    Column(
        modifier = Modifier.fillMaxSize().padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Text("FoleyTimePro", fontSize = 28.sp, fontWeight = FontWeight.Bold, color = Navy)
        Text(prefs.getCompanyName(), fontSize = 14.sp, color = TextSecondary, modifier = Modifier.padding(top = 4.dp, bottom = 40.dp))

        OutlinedTextField(
            value = email,
            onValueChange = { email = it; error = "" },
            label = { Text("Email") },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email, imeAction = ImeAction.Next),
        )

        Spacer(Modifier.height(12.dp))

        OutlinedTextField(
            value = password,
            onValueChange = { password = it; error = "" },
            label = { Text("Password") },
            modifier = Modifier.fillMaxWidth(),
            singleLine = true,
            visualTransformation = if (showPw) VisualTransformation.None else PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = if (needs2FA) ImeAction.Next else ImeAction.Go),
            keyboardActions = KeyboardActions(onGo = { if (!needs2FA) doLogin() }),
            trailingIcon = {
                IconButton(onClick = { showPw = !showPw }) {
                    Icon(if (showPw) Icons.Filled.Visibility else Icons.Filled.VisibilityOff, contentDescription = null)
                }
            }
        )

        if (needs2FA) {
            Spacer(Modifier.height(12.dp))
            Text("Enter your 6-digit authenticator code", style = MaterialTheme.typography.bodySmall, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(
                value = twofa,
                onValueChange = { twofa = it.take(6) },
                label = { Text("2FA Code") },
                modifier = Modifier.fillMaxWidth(),
                singleLine = true,
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number, imeAction = ImeAction.Go),
                keyboardActions = KeyboardActions(onGo = { doLogin() }),
            )
        }

        if (error.isNotEmpty()) {
            Spacer(Modifier.height(8.dp))
            Text(error, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium)
        }

        Spacer(Modifier.height(20.dp))

        Button(
            onClick  = { doLogin() },
            enabled  = email.isNotBlank() && password.isNotBlank() && !loading,
            modifier = Modifier.fillMaxWidth().height(52.dp),
        ) {
            if (loading) CircularProgressIndicator(Modifier.size(20.dp), color = MaterialTheme.colorScheme.onPrimary, strokeWidth = 2.dp)
            else Text(if (needs2FA) "Verify & Sign In" else "Sign In", fontWeight = FontWeight.Bold)
        }

        Spacer(Modifier.height(24.dp))
        HorizontalDivider()
        Spacer(Modifier.height(16.dp))

        TextButton(onClick = {
            prefs.clearAll()
            // Restart app navigation — pop back to setup
        }) {
            Text("Change Server", color = TextSecondary)
        }
    }
}
