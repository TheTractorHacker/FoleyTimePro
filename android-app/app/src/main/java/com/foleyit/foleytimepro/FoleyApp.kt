package com.foleyit.foleytimepro

import android.app.Application
import androidx.hilt.work.HiltWorkerFactory
import androidx.work.Configuration
import com.foleyit.foleytimepro.push.NotificationChannels
import dagger.hilt.android.HiltAndroidApp
import javax.inject.Inject

/**
 * Application entry point.
 *
 * - `@HiltAndroidApp` bootstraps Hilt's generated component graph.
 * - Implements [Configuration.Provider] so WorkManager uses the [HiltWorkerFactory]
 *   for constructor injection into `CoroutineWorker`s.
 * - Creates notification channels once at cold start.
 */
@HiltAndroidApp
class FoleyApp : Application(), Configuration.Provider {

    @Inject lateinit var workerFactory: HiltWorkerFactory

    override val workManagerConfiguration: Configuration
        get() = Configuration.Builder()
            .setWorkerFactory(workerFactory)
            .setMinimumLoggingLevel(if (BuildConfig.DEBUG) android.util.Log.INFO else android.util.Log.ERROR)
            .build()

    override fun onCreate() {
        super.onCreate()
        NotificationChannels.createAll(this)
    }
}
