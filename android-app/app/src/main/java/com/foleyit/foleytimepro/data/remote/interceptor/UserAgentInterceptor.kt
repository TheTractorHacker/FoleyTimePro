package com.foleyit.foleytimepro.data.remote.interceptor

import android.os.Build
import com.foleyit.foleytimepro.BuildConfig
import okhttp3.Interceptor
import okhttp3.Response
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Adds a distinctive User-Agent so server-side logs can attribute traffic to
 * a specific app version — useful when investigating incidents. Example:
 *   FoleyTimePro/4.0.0 (Android 34; sdk 34; Pixel 7)
 */
@Singleton
class UserAgentInterceptor @Inject constructor() : Interceptor {
    private val ua = "FoleyTimePro/${BuildConfig.VERSION_NAME} " +
                     "(Android ${Build.VERSION.RELEASE}; sdk ${Build.VERSION.SDK_INT}; ${Build.MODEL})"

    override fun intercept(chain: Interceptor.Chain): Response =
        chain.proceed(chain.request().newBuilder().header("User-Agent", ua).build())
}
