package com.foleyit.foleytimepro.security

import android.app.Activity
import android.view.WindowManager
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.ui.platform.LocalContext

/**
 * Adds `FLAG_SECURE` to the current window for the duration of the composable's
 * lifecycle. This blocks screenshots and hides the surface from screen-share /
 * the recent-apps thumbnail.
 *
 * Use on the Login screen (password field), the Profile screen's
 * change-password dialog, and anywhere else a shoulder-surfer could capture
 * sensitive state. Do NOT apply globally — it also blocks accessibility
 * screen-recording, which some users rely on.
 */
@Composable
fun SecureScreen() {
    val context = LocalContext.current
    DisposableEffect(Unit) {
        val activity = context as? Activity ?: return@DisposableEffect onDispose {}
        activity.window.setFlags(
            WindowManager.LayoutParams.FLAG_SECURE,
            WindowManager.LayoutParams.FLAG_SECURE,
        )
        onDispose {
            activity.window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
        }
    }
}
