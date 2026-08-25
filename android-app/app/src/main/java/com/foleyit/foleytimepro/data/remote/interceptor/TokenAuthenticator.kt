package com.foleyit.foleytimepro.data.remote.interceptor

import com.foleyit.foleytimepro.data.remote.dto.Envelope
import com.foleyit.foleytimepro.data.remote.dto.LoginData
import com.foleyit.foleytimepro.data.remote.dto.RefreshRequest
import com.foleyit.foleytimepro.security.AuthEvents
import com.foleyit.foleytimepro.utils.PrefsManager
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import okhttp3.Authenticator
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import okhttp3.Route
import javax.inject.Inject
import javax.inject.Provider
import javax.inject.Singleton

/**
 * OkHttp `Authenticator` triggered on 401 responses.
 *
 * On the first 401 seen with a given access token, refreshes the token via
 * `/api/v1/auth.php?action=refresh` and retries the original request with the
 * new bearer. Concurrent 401s share the refresh: the `synchronized` block
 * ensures only one thread hits the refresh endpoint; the others compare
 * their in-flight bearer to the latest stored value and skip a redundant call.
 *
 * A failed refresh emits `AuthEvents.Reason.TokenRefreshFailed` and returns
 * null (giving up), which surfaces to the caller as `ApiResult.Unauthorized`
 * so `MainActivity` can navigate back to the Login screen.
 *
 * The refresh call uses a *bare* `OkHttpClient` (no interceptors) built via
 * `Provider<OkHttpClient>` to avoid re-triggering this authenticator on itself.
 */
@Singleton
class TokenAuthenticator @Inject constructor(
    private val prefs: PrefsManager,
    private val json: Json,
    private val bareClient: Provider<@BareOkHttp OkHttpClient>,
    private val authEvents: AuthEvents,
) : Authenticator {

    private val jsonMediaType = "application/json; charset=utf-8".toMediaType()

    override fun authenticate(route: Route?, response: Response): Request? {
        // Circuit-breaker: cap retries at 1 per original request.
        if (response.priorResponse != null) return null
        val original = response.request

        val bearerOnRequest = original.header("Authorization")
            ?.removePrefix("Bearer ")
            ?.trim()
            .orEmpty()

        synchronized(this) {
            val current = prefs.getAccessToken()
            // Another thread already refreshed — retry with the fresh token.
            if (current.isNotEmpty() && current != bearerOnRequest) {
                return original.newBuilder()
                    .header("Authorization", "Bearer $current")
                    .build()
            }

            val refresh = prefs.getRefreshToken()
            if (refresh.isEmpty()) {
                authEvents.forceLogout(AuthEvents.Reason.TokenRefreshFailed)
                return null
            }

            val newAccess = doRefresh(original, refresh) ?: run {
                prefs.clearSession()
                authEvents.forceLogout(AuthEvents.Reason.TokenRefreshFailed)
                return null
            }

            return original.newBuilder()
                .header("Authorization", "Bearer $newAccess")
                .build()
        }
    }

    private fun doRefresh(original: Request, refreshToken: String): String? {
        val base = original.url.newBuilder()
            .encodedPath(original.url.encodedPath.substringBeforeLast("/") + "/auth.php")
            .build()
            .toString()
            .substringBefore("?")

        val body = json.encodeToString(RefreshRequest(refreshToken))
            .toRequestBody(jsonMediaType)

        val req = Request.Builder()
            .url("$base?action=refresh")
            .post(body)
            .tag(Anonymous)
            .build()

        return try {
            bareClient.get().newCall(req).execute().use { resp ->
                if (!resp.isSuccessful) return null
                val payload = resp.body?.string().orEmpty()
                val env = json.decodeFromString(
                    Envelope.serializer(LoginData.serializer()),
                    payload.ifBlank { "{}" }
                )
                if (!env.success) return null
                val data = env.data ?: return null
                val newAccess = data.accessToken
                val newRefresh = data.refreshToken.ifBlank { refreshToken }
                if (newAccess.isBlank()) return null
                prefs.saveTokens(newAccess, newRefresh, data.expiresIn.takeIf { it > 0 } ?: 900)
                newAccess
            }
        } catch (_: Throwable) {
            null
        }
    }
}

/**
 * Qualifier for the refresh-only OkHttpClient (no interceptors, no authenticator).
 *
 * Needs an explicit [Target] because it's used both on the `@Provides` function
 * in NetworkModule and as a type annotation inside `Provider<@BareOkHttp
 * OkHttpClient>` above — Kotlin's default target set for an annotation with no
 * `@Target` excludes `TYPE` usage.
 */
@javax.inject.Qualifier
@Retention(AnnotationRetention.BINARY)
@Target(
    AnnotationTarget.FUNCTION,
    AnnotationTarget.PROPERTY_GETTER,
    AnnotationTarget.VALUE_PARAMETER,
    AnnotationTarget.TYPE,
)
annotation class BareOkHttp
