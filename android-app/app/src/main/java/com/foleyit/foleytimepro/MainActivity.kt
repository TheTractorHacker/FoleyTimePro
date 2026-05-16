package com.foleyit.foleytimepro

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import com.foleyit.foleytimepro.ui.navigation.AppNavigation
import com.foleyit.foleytimepro.ui.theme.FoleyTimeProTheme
import com.foleyit.foleytimepro.utils.PrefsManager

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        installSplashScreen()
        super.onCreate(savedInstanceState)
        val prefs = PrefsManager(this)
        val start = when {
            prefs.getInstanceUrl().isEmpty() -> "setup"
            prefs.getAccessToken().isEmpty() -> "login"
            else                             -> "dashboard"
        }
        setContent {
            FoleyTimeProTheme {
                AppNavigation(startDestination = start, prefs = prefs)
            }
        }
    }
}
