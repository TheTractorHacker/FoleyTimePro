package com.foleyit.foleytimepro.work

import android.content.Context
import androidx.hilt.work.HiltWorker
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.repository.NotificationRepository
import dagger.assisted.Assisted
import dagger.assisted.AssistedInject

/**
 * Registers the current FCM device token with the backend.
 *
 * Enqueued by [com.foleyit.foleytimepro.push.FoleyMessagingService.onNewToken]
 * and (opportunistically) after a successful login. Retries on network error.
 */
@HiltWorker
class FcmTokenSyncWorker @AssistedInject constructor(
    @Assisted appContext: Context,
    @Assisted params: WorkerParameters,
    private val notificationRepository: NotificationRepository,
) : CoroutineWorker(appContext, params) {

    override suspend fun doWork(): Result {
        val token = inputData.getString(KEY_TOKEN)
        val res = if (token.isNullOrBlank()) {
            notificationRepository.syncCurrentToken()
        } else {
            notificationRepository.registerToken(token)
        }
        return when (res) {
            is ApiResult.Success       -> Result.success()
            is ApiResult.NetworkError  -> Result.retry()
            is ApiResult.Unknown       -> Result.retry()
            is ApiResult.Unauthorized  -> Result.failure()
            is ApiResult.HttpError     -> if (res.code in 500..599) Result.retry() else Result.failure()
        }
    }

    companion object {
        const val UNIQUE_NAME = "fcm-token-sync"
        const val KEY_TOKEN   = "token"
    }
}
