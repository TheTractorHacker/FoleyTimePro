package com.foleyit.foleytimepro.work

import android.content.Context
import androidx.hilt.work.HiltWorker
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.remote.api.AuthApi
import com.foleyit.foleytimepro.security.AuthEvents
import com.foleyit.foleytimepro.utils.PrefsManager
import dagger.assisted.Assisted
import dagger.assisted.AssistedInject

/**
 * Periodic proactive access-token refresh.
 *
 * WorkManager cannot schedule below 15 minutes for periodic work, which sits
 * comfortably inside the 900s (15m) access-token lifetime — so we refresh
 * every ~12 minutes (via a repeating one-time chain) OR rely on this periodic
 * worker to keep tokens fresh on cold start / long idle.
 *
 * The [com.foleyit.foleytimepro.data.remote.interceptor.TokenAuthenticator]
 * remains the ultimate safety net for reactive 401 recovery.
 */
@HiltWorker
class TokenRefreshWorker @AssistedInject constructor(
    @Assisted appContext: Context,
    @Assisted params: WorkerParameters,
    private val authApi: AuthApi,
    private val prefs: PrefsManager,
    private val authEvents: AuthEvents,
) : CoroutineWorker(appContext, params) {

    override suspend fun doWork(): Result {
        val refreshToken = prefs.getRefreshToken()
        if (refreshToken.isBlank()) return Result.success()

        return when (val res = authApi.refresh(refreshToken)) {
            is ApiResult.Success -> {
                val newAccess = res.data.accessToken
                val newRefresh = res.data.refreshToken.ifBlank { refreshToken }
                if (newAccess.isNotBlank()) {
                    prefs.saveTokens(newAccess, newRefresh, res.data.expiresIn.takeIf { it > 0 } ?: 900)
                }
                Result.success()
            }
            is ApiResult.Unauthorized -> {
                prefs.clearSession()
                authEvents.forceLogout(AuthEvents.Reason.TokenRefreshFailed)
                Result.failure()
            }
            is ApiResult.NetworkError -> Result.retry()
            is ApiResult.HttpError    -> Result.retry()
            is ApiResult.Unknown      -> Result.retry()
        }
    }

    companion object { const val UNIQUE_NAME = "token-refresh" }
}
