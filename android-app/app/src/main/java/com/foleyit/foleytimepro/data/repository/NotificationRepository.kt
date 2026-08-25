package com.foleyit.foleytimepro.data.repository

import android.content.Context
import android.os.Build
import com.foleyit.foleytimepro.BuildConfig
import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.remote.api.NotificationApi
import com.foleyit.foleytimepro.data.remote.dto.DeviceRegisterRequest
import com.foleyit.foleytimepro.utils.PrefsManager
import com.google.firebase.messaging.FirebaseMessaging
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.tasks.await
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Push registration lifecycle.
 *
 * `syncCurrentToken` is called after successful login to register the FCM
 * token with the server. `FoleyMessagingService.onNewToken` triggers it too
 * (via the `FcmTokenSyncWorker`), so token rotations propagate automatically.
 */
@Singleton
class NotificationRepository @Inject constructor(
    private val api: NotificationApi,
    private val prefs: PrefsManager,
    @ApplicationContext private val appContext: Context,
) {

    suspend fun syncCurrentToken(): ApiResult<Unit> {
        val token = try {
            FirebaseMessaging.getInstance().token.await()
        } catch (ise: IllegalStateException) {
            // Firebase not initialized (APP_ENABLE_FIREBASE=false, or missing
            // google-services.json). Silently no-op so builds without FCM
            // wiring still succeed at login.
            return ApiResult.Success(Unit)
        } catch (t: Throwable) {
            return ApiResult.Unknown(t)
        }
        return registerToken(token)
    }

    suspend fun registerToken(token: String): ApiResult<Unit> {
        if (token.isBlank()) return ApiResult.HttpError(0, "Empty FCM token")
        val res = api.register(
            DeviceRegisterRequest(
                token = token,
                platform = "android",
                appVersion = BuildConfig.VERSION_NAME,
                deviceModel = Build.MODEL,
                osVersion = "${Build.VERSION.RELEASE} (SDK ${Build.VERSION.SDK_INT})",
            )
        )
        if (res is ApiResult.Success) {
            prefs.setFcmToken(token)
            prefs.setFcmRegistered(true)
        }
        return res
    }
}
