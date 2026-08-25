package com.foleyit.foleytimepro.data.remote.interceptor

import com.foleyit.foleytimepro.utils.PrefsManager
import okhttp3.Interceptor
import okhttp3.Response
import javax.inject.Inject
import javax.inject.Singleton

/** Marker attached with `Request.Builder().tag(Anonymous)` to skip token injection. */
data object Anonymous

/**
 * Adds `Authorization: Bearer <token>` to every request except those tagged
 * `Anonymous` (login, refresh, branding).
 */
@Singleton
class AuthInterceptor @Inject constructor(
    private val prefs: PrefsManager,
) : Interceptor {

    override fun intercept(chain: Interceptor.Chain): Response {
        val req = chain.request()
        if (req.tag() == Anonymous) return chain.proceed(req)

        val token = prefs.getAccessToken()
        val newReq = if (token.isNotEmpty()) {
            req.newBuilder().header("Authorization", "Bearer $token").build()
        } else req

        return chain.proceed(newReq)
    }
}
