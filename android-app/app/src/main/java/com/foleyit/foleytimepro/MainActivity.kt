package com.foleyit.foleytimepro

import android.content.Intent
import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import com.foleyit.foleytimepro.utils.PrefsManager

class MainActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        installSplashScreen()
        super.onCreate(savedInstanceState)
        val prefs = PrefsManager(this)
        val next = when {
            prefs.getInstanceUrl().isEmpty() -> SetupActivity::class.java
            prefs.getAccessToken().isEmpty() -> LoginActivity::class.java
            else                             -> DashboardActivity::class.java
        }
        startActivity(Intent(this, next))
        finish()
    }
}
