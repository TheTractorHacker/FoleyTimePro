package com.foleyit.foleytimepro.ui.screen.login

import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.foleyit.foleytimepro.security.RootEmulatorDetector
import com.foleyit.foleytimepro.security.SecureScreen
import com.foleyit.foleytimepro.ui.common.WarningStrip
import com.foleyit.foleytimepro.ui.theme.AppShapeExtras
import com.foleyit.foleytimepro.ui.theme.Spacing

@Composable
fun LoginScreen(
    onLoggedIn: () -> Unit,
    onChangeServer: () -> Unit,
    viewModel: LoginViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    // Block screenshots + hide from Recent Apps thumbnail while a password is
    // on screen. Only bound to the Login lifecycle.
    SecureScreen()

    LaunchedEffect(state.loggedIn) { if (state.loggedIn) onLoggedIn() }

    var showPassword by remember { mutableStateOf(false) }
    val warning = remember { RootEmulatorDetector.report() }

    Surface(color = MaterialTheme.colorScheme.background) {
        Column(Modifier.fillMaxSize()) {
            if (warning.hasWarning) {
                WarningStrip(
                    text = when {
                        warning.rooted && warning.emulator ->
                            "This device appears rooted and running under an emulator."
                        warning.rooted -> "This device appears rooted — sensitive data may be at risk."
                        else           -> "This device appears to be an emulator."
                    },
                    modifier = Modifier.safeDrawingPadding(),
                )
            }

            Box(
                modifier = Modifier
                    .fillMaxSize()
                    .safeDrawingPadding()
                    .imePadding()
                    .verticalScroll(rememberScrollState()),
                contentAlignment = Alignment.Center,
            ) {
                Column(
                    // Capped so the fields stay a comfortable reading width on a
                    // tablet kiosk instead of stretching the full 10 inches.
                    modifier = Modifier
                        .widthIn(max = 420.dp)
                        .padding(Spacing.xxl),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.spacedBy(Spacing.md),
                ) {
                    Text(
                        text  = "FoleyTimePro",
                        style = MaterialTheme.typography.displaySmall,
                        color = MaterialTheme.colorScheme.primary,
                        fontWeight = FontWeight.Bold,
                    )
                    Text(
                        text  = state.companyName,
                        style = MaterialTheme.typography.bodyLarge,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                        textAlign = TextAlign.Center,
                    )

                    Spacer(Modifier.height(Spacing.lg))

                    OutlinedTextField(
                        value = state.email,
                        onValueChange = viewModel::onEmailChanged,
                        label = { Text("Email") },
                        modifier = Modifier.fillMaxWidth(),
                        shape = MaterialTheme.shapes.medium,
                        singleLine = true,
                        isError = state.error != null,
                        keyboardOptions = KeyboardOptions(
                            keyboardType = KeyboardType.Email,
                            imeAction = ImeAction.Next,
                        ),
                    )

                    OutlinedTextField(
                        value = state.password,
                        onValueChange = viewModel::onPasswordChanged,
                        label = { Text("Password") },
                        modifier = Modifier.fillMaxWidth(),
                        shape = MaterialTheme.shapes.medium,
                        singleLine = true,
                        isError = state.error != null,
                        visualTransformation = if (showPassword) VisualTransformation.None
                                               else PasswordVisualTransformation(),
                        keyboardOptions = KeyboardOptions(
                            keyboardType = KeyboardType.Password,
                            imeAction = if (state.needs2FA) ImeAction.Next else ImeAction.Go,
                        ),
                        keyboardActions = KeyboardActions(
                            onGo = { if (!state.needs2FA) viewModel.submit() },
                        ),
                        trailingIcon = {
                            IconButton(onClick = { showPassword = !showPassword }) {
                                Icon(
                                    imageVector = if (showPassword) Icons.Filled.Visibility
                                                  else Icons.Filled.VisibilityOff,
                                    contentDescription = if (showPassword) "Hide password"
                                                         else "Show password",
                                )
                            }
                        },
                    )

                    AnimatedVisibility(
                        visible = state.needs2FA,
                        enter   = fadeIn() + expandVertically(),
                        exit    = fadeOut() + shrinkVertically(),
                    ) {
                        OutlinedTextField(
                            value = state.twoFaCode,
                            onValueChange = viewModel::onTwoFaChanged,
                            label = { Text("2FA Code") },
                            supportingText = { Text("Enter your 6-digit authenticator code") },
                            modifier = Modifier.fillMaxWidth(),
                            shape = MaterialTheme.shapes.medium,
                            singleLine = true,
                            keyboardOptions = KeyboardOptions(
                                keyboardType = KeyboardType.Number,
                                imeAction = ImeAction.Go,
                            ),
                            keyboardActions = KeyboardActions(onGo = { viewModel.submit() }),
                        )
                    }

                    state.error?.let {
                        Text(
                            text  = it,
                            style = MaterialTheme.typography.bodyMedium,
                            color = MaterialTheme.colorScheme.error,
                            textAlign = TextAlign.Center,
                            modifier = Modifier.fillMaxWidth(),
                        )
                    }

                    Spacer(Modifier.height(Spacing.xs))

                    Button(
                        onClick  = viewModel::submit,
                        enabled  = state.canSubmit,
                        modifier = Modifier.fillMaxWidth().height(56.dp),
                        shape    = AppShapeExtras.pill,
                    ) {
                        if (state.loading) {
                            CircularProgressIndicator(
                                modifier = Modifier.size(20.dp),
                                color = MaterialTheme.colorScheme.onPrimary,
                                strokeWidth = 2.dp,
                            )
                        } else {
                            Text(
                                text  = if (state.needs2FA) "Verify & Sign In" else "Sign In",
                                style = MaterialTheme.typography.titleMedium,
                            )
                        }
                    }

                    TextButton(onClick = {
                        viewModel.onChangeServer()
                        onChangeServer()
                    }) {
                        Text("Change Server")
                    }
                }
            }
        }
    }
}
