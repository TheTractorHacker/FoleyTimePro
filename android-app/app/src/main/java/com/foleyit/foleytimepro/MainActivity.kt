package com.foleyit.foleytimepro

import android.Manifest
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import androidx.fragment.app.FragmentActivity
import com.foleyit.foleytimepro.ui.navigation.AppNavigation
import com.foleyit.foleytimepro.ui.theme.FoleyTimeProTheme
import com.foleyit.foleytimepro.utils.PrefsManager
import dagger.hilt.android.AndroidEntryPoint
import javax.inject.Inject

/**
 * Single Activity host. Extends [FragmentActivity] so [BiometricPrompt] can
 * attach — Compose still owns every screen.
 *
 * The window is drawn edge-to-edge; `FoleyTimeProTheme` syncs the system-bar
 * icon tint to the active light/dark scheme.
 *
 * The runtime `POST_NOTIFICATIONS` request is scoped to a first-launch prompt
 * on Android 13+. We ask for it eagerly (before login) so the kiosk owner
 * decides once; ideally the user grants it before FCM registers.
 */
@AndroidEntryPoint
class MainActivity : FragmentActivity() {

    @Inject lateinit var prefs: PrefsManager

    private val requestNotifPermission = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { /* result discarded — FCM registration proceeds either way. */ }

    override fun onCreate(savedInstanceState: Bundle?) {
        installSplashScreen()
        // Draw behind the status and navigation bars. Compose then places the
        // app bars and the navigation bar against the real insets, which is what
        // lets the tonal surfaces run to the edge of the screen instead of
        // stopping at an opaque navy strip.
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        maybeRequestNotificationPermission()

        val start = when {
            prefs.getInstanceUrl().isEmpty() -> "setup"
            prefs.getAccessToken().isEmpty() -> "login"
            else                             -> "dashboard"
        }
        setContent {
            FoleyTimeProTheme {
                AppNavigation(startDestination = start)
            }
        }
    }

    private fun maybeRequestNotificationPermission() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return
        val granted = ContextCompat.checkSelfPermission(
            this, Manifest.permission.POST_NOTIFICATIONS
        ) == PackageManager.PERMISSION_GRANTED
        if (!granted) requestNotifPermission.launch(Manifest.permission.POST_NOTIFICATIONS)
    }
}
