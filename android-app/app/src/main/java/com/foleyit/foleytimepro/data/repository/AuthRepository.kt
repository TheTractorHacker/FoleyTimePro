package com.foleyit.foleytimepro.data.repository

import com.foleyit.foleytimepro.data.local.dao.CachedPunchDao
import com.foleyit.foleytimepro.data.local.dao.CachedShiftDao
import com.foleyit.foleytimepro.data.local.dao.QueuedPunchDao
import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.remote.api.AuthApi
import com.foleyit.foleytimepro.data.remote.api.NotificationApi
import com.foleyit.foleytimepro.data.remote.dto.LoginData
import com.foleyit.foleytimepro.domain.model.Session
import com.foleyit.foleytimepro.security.AuthEvents
import com.foleyit.foleytimepro.utils.PrefsManager
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.tasks.await
import kotlinx.coroutines.withTimeoutOrNull
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Owns login, logout, and the on-device session state.
 *
 * On logout:
 *   1. Best-effort unregister the FCM token so the server stops pushing to
 *      this device (we don't wait forever — 2s cap).
 *   2. Best-effort call `/auth?action=logout` to revoke the refresh token.
 *   3. Delete the FCM registration.
 *   4. Wipe all local caches (Room + prefs).
 *
 * These fire whether the network call succeeds or not — a compromised device
 * offline should NOT retain credentials just because the API is unreachable.
 */
@Singleton
class AuthRepository @Inject constructor(
    private val authApi: AuthApi,
    private val notificationApi: NotificationApi,
    private val prefs: PrefsManager,
    private val queuedPunchDao: QueuedPunchDao,
    private val cachedPunchDao: CachedPunchDao,
    private val cachedShiftDao: CachedShiftDao,
    private val authEvents: AuthEvents,
) {

    suspend fun login(email: String, password: String, twoFaCode: String? = null): ApiResult<Session> {
        return when (val result = authApi.login(email, password, twoFaCode)) {
            is ApiResult.Success -> {
                val data: LoginData = result.data
                if (data.requires2FA) {
                    ApiResult.Success(Session(-1, email, "", requires2FA = true))
                } else {
                    prefs.saveTokens(data.accessToken, data.refreshToken, data.expiresIn.takeIf { it > 0 } ?: 900)
                    prefs.saveUser(data.user.id, data.user.email, data.user.firstName, data.user.lastName)
                    ApiResult.Success(
                        Session(
                            userId       = data.user.id,
                            email        = data.user.email,
                            displayName  = "${data.user.firstName} ${data.user.lastName}".trim(),
                            requires2FA  = false,
                        )
                    )
                }
            }
            is ApiResult.HttpError    -> result
            is ApiResult.NetworkError -> result
            is ApiResult.Unauthorized -> result
            is ApiResult.Unknown      -> result
        }
    }

    suspend fun signOut(reason: AuthEvents.Reason = AuthEvents.Reason.UserSignOut) {
        val token = prefs.getFcmToken()
        if (token.isNotEmpty() && prefs.isFcmRegistered()) {
            withTimeoutOrNull(2_000) { notificationApi.unregister(token) }
        }
        withTimeoutOrNull(2_000) { authApi.logout() }

        // Delete FCM registration locally so onNewToken fires clean next login.
        // Swallow IllegalStateException so builds without FCM wiring still work.
        withTimeoutOrNull(2_000) {
            runCatching { FirebaseMessaging.getInstance().deleteToken().await() }
                .onFailure { /* Firebase may not be initialized — non-fatal */ }
        }

        wipeLocalData()
        authEvents.forceLogout(reason)
    }

    /**
     * Called by [TokenAuthenticator] and [ViewModel]s reacting to explicit
     * server-side revocation — wipes without waiting on network.
     */
    suspend fun wipeLocalData() {
        val uid = prefs.getUserId()
        if (uid > 0) {
            runCatching { queuedPunchDao.deleteForUser(uid) }
            runCatching { cachedPunchDao.deleteForUser(uid) }
            runCatching { cachedShiftDao.deleteForUser(uid) }
        } else {
            runCatching { queuedPunchDao.clear() }
            runCatching { cachedPunchDao.clear() }
            runCatching { cachedShiftDao.clear() }
        }
        prefs.clearSession()
    }

    /** Called on inactivity timeout (kiosk device). */
    fun onInactivityTimeout() = authEvents.forceLogout(AuthEvents.Reason.Inactivity)
}
