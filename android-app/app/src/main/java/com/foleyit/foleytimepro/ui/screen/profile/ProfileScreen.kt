package com.foleyit.foleytimepro.ui.screen.profile

import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.Logout
import androidx.compose.material.icons.filled.ChevronRight
import androidx.compose.material.icons.outlined.Security
import androidx.compose.material.icons.outlined.SwapHoriz
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.ListItem
import androidx.compose.material3.ListItemDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material3.rememberTopAppBarState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.foleyit.foleytimepro.security.SecureScreen
import com.foleyit.foleytimepro.ui.common.FoleyLargeTopAppBar
import com.foleyit.foleytimepro.ui.common.KeyValueRow
import com.foleyit.foleytimepro.ui.common.RowDivider
import com.foleyit.foleytimepro.ui.common.SectionCard
import com.foleyit.foleytimepro.ui.theme.AppShapeExtras
import com.foleyit.foleytimepro.ui.theme.Spacing
import com.foleyit.foleytimepro.ui.theme.statusColors

@Composable
fun ProfileScreen(
    onChangeServer: () -> Unit,
    viewModel: ProfileViewModel = hiltViewModel(),
) {
    // Hide contents from Recent Apps thumbnail — password fields visible here.
    SecureScreen()

    val state by viewModel.state.collectAsStateWithLifecycle()
    val context = LocalContext.current
    val scrollBehavior = TopAppBarDefaults.enterAlwaysScrollBehavior(rememberTopAppBarState())
    var showLogoutDialog by remember { mutableStateOf(false) }

    if (showLogoutDialog) {
        AlertDialog(
            onDismissRequest = { showLogoutDialog = false },
            title = { Text("Sign out?") },
            text  = { Text("You'll need your password to sign back in.") },
            confirmButton = {
                Button(
                    onClick = { showLogoutDialog = false; viewModel.signOut() },
                    colors  = ButtonDefaults.buttonColors(
                        containerColor = MaterialTheme.colorScheme.error,
                        contentColor   = MaterialTheme.colorScheme.onError,
                    ),
                ) { Text("Sign Out") }
            },
            dismissButton = { TextButton(onClick = { showLogoutDialog = false }) { Text("Cancel") } },
        )
    }

    Scaffold(
        modifier = Modifier.nestedScroll(scrollBehavior.nestedScrollConnection),
        topBar = {
            FoleyLargeTopAppBar(
                title    = "Profile",
                subtitle = state.companyName.ifEmpty { null },
                scrollBehavior = scrollBehavior,
            )
        },
        contentWindowInsets = WindowInsets(0, 0, 0, 0),
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(Spacing.md),
        ) {
            IdentityHeader(
                initials = state.initials,
                name     = state.displayName,
                email    = state.email,
                modifier = Modifier.padding(horizontal = Spacing.screenGutter),
            )

            SectionCard(
                modifier = Modifier.padding(horizontal = Spacing.screenGutter),
                title = "Account",
            ) {
                KeyValueRow(label = "Company", value = state.companyName)
                RowDivider()
                KeyValueRow(label = "Server", value = state.instanceHost)
            }

            ChangePasswordCard(
                state = state,
                onNewPassword     = viewModel::onNewPasswordChanged,
                onConfirmPassword = viewModel::onConfirmPasswordChanged,
                onSubmit          = viewModel::submitPasswordChange,
                modifier = Modifier.padding(horizontal = Spacing.screenGutter),
            )

            SectionCard(
                modifier = Modifier.padding(horizontal = Spacing.screenGutter),
                contentPadding = false,
            ) {
                SettingsRow(
                    icon = Icons.Outlined.Security,
                    title = "Two-Factor Auth (2FA)",
                    subtitle = "Manage via web portal",
                    onClick = {
                        val host = sanitizeHost(state.instanceHost)
                        context.startActivity(
                            Intent(Intent.ACTION_VIEW, Uri.parse("https://$host/user/settings.php"))
                        )
                    },
                )
                RowDivider()
                SettingsRow(
                    icon = Icons.Outlined.SwapHoriz,
                    title = "Change Server",
                    subtitle = "Disconnect and point at a different instance",
                    onClick = {
                        viewModel.changeServer()
                        onChangeServer()
                    },
                )
            }

            Button(
                onClick  = { showLogoutDialog = true },
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = Spacing.screenGutter)
                    .height(56.dp),
                shape  = AppShapeExtras.pill,
                colors = ButtonDefaults.buttonColors(
                    containerColor = MaterialTheme.colorScheme.errorContainer,
                    contentColor   = MaterialTheme.colorScheme.onErrorContainer,
                ),
            ) {
                Icon(Icons.AutoMirrored.Filled.Logout, contentDescription = null)
                Spacer(Modifier.size(Spacing.sm))
                Text("Sign Out", style = MaterialTheme.typography.titleMedium)
            }

            Spacer(Modifier.height(Spacing.lg))
        }
    }
}

@Composable
private fun IdentityHeader(
    initials: String,
    name: String,
    email: String,
    modifier: Modifier = Modifier,
) {
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = AppShapeExtras.extraLargeIncreased,
        color = MaterialTheme.colorScheme.primaryContainer,
        contentColor = MaterialTheme.colorScheme.onPrimaryContainer,
    ) {
        Column(
            modifier = Modifier.fillMaxWidth().padding(Spacing.xl),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Surface(
                modifier = Modifier.size(84.dp),
                shape = CircleShape,
                color = MaterialTheme.colorScheme.primary,
                contentColor = MaterialTheme.colorScheme.onPrimary,
            ) {
                Box(contentAlignment = Alignment.Center) {
                    Text(initials, style = MaterialTheme.typography.headlineMedium)
                }
            }
            Spacer(Modifier.height(Spacing.lg))
            Text(name, style = MaterialTheme.typography.headlineSmall)
            Text(
                text  = email.ifEmpty { "No email on file" },
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onPrimaryContainer.copy(alpha = 0.75f),
            )
        }
    }
}

@Composable
private fun ChangePasswordCard(
    state: ProfileUiState,
    onNewPassword: (String) -> Unit,
    onConfirmPassword: (String) -> Unit,
    onSubmit: () -> Unit,
    modifier: Modifier = Modifier,
) {
    SectionCard(modifier = modifier, title = "Change Password") {
        Column(verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
            OutlinedTextField(
                value = state.newPassword,
                onValueChange = onNewPassword,
                label = { Text("New Password") },
                modifier = Modifier.fillMaxWidth(),
                shape = MaterialTheme.shapes.medium,
                visualTransformation = PasswordVisualTransformation(),
                singleLine = true,
                isError = state.passwordIsError,
            )
            OutlinedTextField(
                value = state.confirmPassword,
                onValueChange = onConfirmPassword,
                label = { Text("Confirm Password") },
                modifier = Modifier.fillMaxWidth(),
                shape = MaterialTheme.shapes.medium,
                visualTransformation = PasswordVisualTransformation(),
                singleLine = true,
                isError = state.passwordIsError,
                // Both the failure and the success message belong to this pair of
                // fields, so they hang off the second one instead of becoming a
                // free-floating line of coloured text.
                supportingText = state.passwordMessage?.let { message ->
                    {
                        Text(
                            text  = message,
                            color = if (state.passwordIsError) MaterialTheme.colorScheme.error
                                    else MaterialTheme.statusColors.clockedIn,
                        )
                    }
                },
            )
            Button(
                onClick  = onSubmit,
                enabled  = !state.passwordLoading,
                modifier = Modifier.fillMaxWidth().height(52.dp),
                shape    = AppShapeExtras.pill,
            ) {
                if (state.passwordLoading) {
                    CircularProgressIndicator(
                        modifier = Modifier.size(20.dp),
                        color = MaterialTheme.colorScheme.onPrimary,
                        strokeWidth = 2.dp,
                    )
                } else {
                    Text("Update Password", style = MaterialTheme.typography.titleMedium)
                }
            }
        }
    }
}

/** A tappable settings row. `ListItem` gives it the Material heights for free. */
@Composable
private fun SettingsRow(
    icon: ImageVector,
    title: String,
    subtitle: String,
    onClick: () -> Unit,
) {
    ListItem(
        headlineContent   = { Text(title) },
        supportingContent = { Text(subtitle) },
        leadingContent    = { Icon(icon, contentDescription = null) },
        trailingContent   = { Icon(Icons.Filled.ChevronRight, contentDescription = null) },
        colors = ListItemDefaults.colors(containerColor = Color.Transparent),
        modifier = Modifier.clickable(onClick = onClick),
    )
}

private fun sanitizeHost(host: String): String =
    host.removePrefix("https://").removePrefix("http://").trimEnd('/')
