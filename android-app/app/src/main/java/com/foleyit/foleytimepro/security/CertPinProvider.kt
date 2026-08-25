package com.foleyit.foleytimepro.security

import android.net.Uri
import com.foleyit.foleytimepro.BuildConfig
import okhttp3.CertificatePinner
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Builds an OkHttp `CertificatePinner` from `BuildConfig.CERT_PIN_PRIMARY`
 * and `CERT_PIN_BACKUP`.
 *
 * Pins are SPKI-SHA256 in OkHttp's canonical `sha256/BASE64` form. Extract
 * them from your production certificate:
 *
 *   openssl s_client -servername HOST -connect HOST:443 </dev/null 2>/dev/null \
 *     | openssl x509 -pubkey -noout \
 *     | openssl pkey -pubin -outform der \
 *     | openssl dgst -sha256 -binary | base64
 *
 * Always ship two pins — the current leaf and one backup — so a routine cert
 * rotation on the server does not brick every kiosk.
 *
 * If both pins are empty (dev / early rollout), returns the default pinner
 * (no pinning). Do NOT ship a release build with empty pins.
 */
@Singleton
class CertPinProvider @Inject constructor() {

    fun pinnerFor(baseUrl: String): CertificatePinner {
        val host = extractHost(baseUrl) ?: return CertificatePinner.DEFAULT
        val builder = CertificatePinner.Builder()

        var added = 0
        if (BuildConfig.CERT_PIN_PRIMARY.isNotEmpty()) {
            builder.add(host, BuildConfig.CERT_PIN_PRIMARY); added++
        }
        if (BuildConfig.CERT_PIN_BACKUP.isNotEmpty()) {
            builder.add(host, BuildConfig.CERT_PIN_BACKUP); added++
        }
        return if (added == 0) CertificatePinner.DEFAULT else builder.build()
    }

    private fun extractHost(url: String): String? = runCatching {
        val u = Uri.parse(url)
        u.host?.takeIf { it.isNotEmpty() }
    }.getOrNull()
}
