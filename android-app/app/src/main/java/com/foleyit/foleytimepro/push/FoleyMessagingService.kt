package com.foleyit.foleytimepro.push

import android.app.PendingIntent
import android.content.Intent
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.work.Data
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import com.foleyit.foleytimepro.MainActivity
import com.foleyit.foleytimepro.R
import com.foleyit.foleytimepro.work.FcmTokenSyncWorker
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlin.random.Random

/**
 * Handles token rotation and incoming FCM messages.
 *
 * Token registration is deferred to [FcmTokenSyncWorker] so it runs on a
 * WorkManager thread with retry-on-network-failure — the service callback
 * itself must return quickly.
 *
 * For visible messages, we post a `NotificationCompat` on the channel named
 * in the payload's `channel` field (default: `general`), and tap-through
 * launches [MainActivity] with any `deep_link` extra.
 */
class FoleyMessagingService : FirebaseMessagingService() {

    override fun onNewToken(token: String) {
        val data = Data.Builder().putString(FcmTokenSyncWorker.KEY_TOKEN, token).build()
        val req  = OneTimeWorkRequestBuilder<FcmTokenSyncWorker>().setInputData(data).build()
        WorkManager.getInstance(applicationContext)
            .enqueueUniqueWork(FcmTokenSyncWorker.UNIQUE_NAME, ExistingWorkPolicy.REPLACE, req)
    }

    override fun onMessageReceived(message: RemoteMessage) {
        val channelId = message.data["channel"] ?: NotificationChannels.GENERAL
        val title     = message.notification?.title ?: message.data["title"] ?: return
        val body      = message.notification?.body  ?: message.data["body"]  ?: ""
        val deepLink  = message.data["deep_link"]

        val launch = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
            if (!deepLink.isNullOrBlank()) putExtra(EXTRA_DEEP_LINK, deepLink)
        }
        val pi = PendingIntent.getActivity(
            this,
            Random.nextInt(),
            launch,
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE,
        )

        val notification = NotificationCompat.Builder(this, channelId)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle(title)
            .setContentText(body)
            .setAutoCancel(true)
            .setContentIntent(pi)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .build()

        val mgr = NotificationManagerCompat.from(this)
        if (mgr.areNotificationsEnabled()) {
            try {
                mgr.notify(Random.nextInt(), notification)
            } catch (se: SecurityException) {
                Log.w("FoleyFCM", "POST_NOTIFICATIONS not granted; dropping message")
            }
        }
    }

    companion object {
        const val EXTRA_DEEP_LINK = "foley.deep_link"
    }
}
