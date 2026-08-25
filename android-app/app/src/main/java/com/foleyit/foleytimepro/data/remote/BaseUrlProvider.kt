package com.foleyit.foleytimepro.data.remote

import com.foleyit.foleytimepro.BuildConfig
import com.foleyit.foleytimepro.utils.PrefsManager
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Resolves the current API base URL (e.g. `https://timeclock.example.com`)
 * from [PrefsManager]. Falls back to the compile-time
 * [BuildConfig.API_BASE_URL_DEFAULT] so early boot code (Setup screen probing
 * a URL) has a sane starting point.
 */
@Singleton
class BaseUrlProvider @Inject constructor(private val prefs: PrefsManager) {

    /** e.g. `https://timeclock.example.com` — no trailing slash. */
    fun base(): String =
        prefs.getInstanceUrl().ifBlank { BuildConfig.API_BASE_URL_DEFAULT }.trimEnd('/')

    /** e.g. `https://timeclock.example.com/api/v1`. */
    fun apiV1(): String = "${base()}/api/v1"

    fun endpoint(path: String, query: String = ""): String {
        val q = if (query.isEmpty()) "" else "?$query"
        return "${apiV1()}/$path$q"
    }
}
