package com.foleyit.foleytimepro.ui.screen.setup

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
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.outlined.Dns
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.TextFieldValue
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.foleyit.foleytimepro.ui.theme.AppShapeExtras
import com.foleyit.foleytimepro.ui.theme.Spacing
import com.foleyit.foleytimepro.ui.theme.statusColors

@Composable
fun SetupScreen(
    onConnected: () -> Unit,
    viewModel: SetupViewModel = hiltViewModel(),
) {
    val state by viewModel.state.collectAsStateWithLifecycle()

    // Pin the cursor after "https://" like the legacy screen.
    var urlField by remember { mutableStateOf(TextFieldValue(state.url, TextRange(state.url.length))) }
    LaunchedEffect(state.url) {
        if (urlField.text != state.url) {
            urlField = TextFieldValue(state.url, TextRange(state.url.length))
        }
    }

    Surface(color = MaterialTheme.colorScheme.background) {
        Box(
            modifier = Modifier
                .fillMaxSize()
                .safeDrawingPadding()
                .imePadding()
                .verticalScroll(rememberScrollState()),
            contentAlignment = Alignment.Center,
        ) {
            Column(
                modifier = Modifier
                    .widthIn(max = 420.dp)
                    .padding(Spacing.xxl),
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.spacedBy(Spacing.md),
            ) {
                Icon(
                    imageVector = Icons.Outlined.Dns,
                    contentDescription = null,
                    tint = MaterialTheme.colorScheme.primary,
                    modifier = Modifier.size(48.dp),
                )
                Text(
                    text  = "FoleyTimePro",
                    style = MaterialTheme.typography.displaySmall,
                    color = MaterialTheme.colorScheme.primary,
                    fontWeight = FontWeight.Bold,
                )
                Text(
                    text  = "by Foley IT",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )

                Spacer(Modifier.height(Spacing.xl))

                Text(
                    text  = "Connect to your server",
                    style = MaterialTheme.typography.titleLarge,
                    textAlign = TextAlign.Center,
                )
                Text(
                    text  = "Enter the URL of your FoleyTimePro instance.",
                    style = MaterialTheme.typography.bodyMedium,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                    textAlign = TextAlign.Center,
                )

                OutlinedTextField(
                    value = urlField,
                    onValueChange = { newVal ->
                        urlField = if (newVal.text.startsWith(SetupViewModel.HTTPS)) newVal
                                   else TextFieldValue(
                                       SetupViewModel.HTTPS,
                                       TextRange(SetupViewModel.HTTPS.length),
                                   )
                        viewModel.onUrlChanged(urlField.text)
                    },
                    label = { Text("Server URL") },
                    placeholder = { Text("time.yourcompany.com") },
                    modifier = Modifier.fillMaxWidth(),
                    shape = MaterialTheme.shapes.medium,
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(
                        keyboardType = KeyboardType.Uri,
                        imeAction = ImeAction.Go,
                    ),
                    keyboardActions = KeyboardActions(
                        onGo = { if (!state.loading) viewModel.connect() },
                    ),
                    isError = state.error != null,
                    supportingText = state.error?.let { { Text(it) } },
                )

                AnimatedVisibility(
                    visible = state.verified,
                    enter   = fadeIn() + expandVertically(),
                    exit    = fadeOut() + shrinkVertically(),
                ) {
                    VerifiedCard(companyName = state.verifiedCompanyName.orEmpty())
                }

                Spacer(Modifier.height(Spacing.xs))

                if (state.verified) {
                    Button(
                        onClick  = onConnected,
                        modifier = Modifier.fillMaxWidth().height(56.dp),
                        shape    = AppShapeExtras.pill,
                    ) {
                        Text("Continue to Sign In", style = MaterialTheme.typography.titleMedium)
                    }
                } else {
                    Button(
                        onClick  = viewModel::connect,
                        enabled  = urlField.text.length > SetupViewModel.HTTPS.length && !state.loading,
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
                            Text("Connect", style = MaterialTheme.typography.titleMedium)
                        }
                    }
                }
            }
        }
    }
}

/**
 * Confirmation that the URL resolved to a real instance.
 *
 * Uses the clocked-in green rather than the primary brand color: this is a
 * success result, and reusing primary here would make it look like just another
 * card rather than the answer to "did that work?".
 */
@Composable
private fun VerifiedCard(companyName: String) {
    Surface(
        modifier = Modifier.fillMaxWidth(),
        shape = MaterialTheme.shapes.large,
        color = MaterialTheme.statusColors.clockedInContainer,
        contentColor = MaterialTheme.statusColors.onClockedInContainer,
    ) {
        Column(Modifier.padding(Spacing.lg)) {
            Icon(Icons.Filled.CheckCircle, contentDescription = null)
            Spacer(Modifier.height(Spacing.sm))
            Text("Connected to", style = MaterialTheme.typography.labelMedium)
            Text(companyName, style = MaterialTheme.typography.titleLarge)
        }
    }
}
