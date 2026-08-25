package com.foleyit.foleytimepro.push

import android.app.NotificationChannel
import android.app.NotificationManager
import android.content.Context

/**
 * FCM notification channels.
 *
 * `time_off_updates` — high importance (approval/rejection is time-sensitive).
 * `shift_changes`    — default importance (schedule updates).
 * `general`          — low importance, default channel referenced by the manifest.
 */
object NotificationChannels {

    const val TIME_OFF   = "time_off_updates"
    const val SHIFTS     = "shift_changes"
    const val GENERAL    = "general"

    fun createAll(context: Context) {
        val nm = context.getSystemService(Context.NOTIFICATION_SERVICE) as NotificationManager
        nm.createNotificationChannels(
            listOf(
                NotificationChannel(
                    TIME_OFF,
                    "Time-Off Updates",
                    NotificationManager.IMPORTANCE_HIGH,
                ).apply {
                    description = "Approvals, rejections, and manager comments on your time-off requests."
                    enableVibration(true)
                },
                NotificationChannel(
                    SHIFTS,
                    "Shift Changes",
                    NotificationManager.IMPORTANCE_DEFAULT,
                ).apply {
                    description = "Schedule assignments and swaps."
                },
                NotificationChannel(
                    GENERAL,
                    "General",
                    NotificationManager.IMPORTANCE_LOW,
                ).apply {
                    description = "Miscellaneous notifications."
                },
            )
        )
    }
}
