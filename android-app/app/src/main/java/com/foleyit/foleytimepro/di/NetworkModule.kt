package com.foleyit.foleytimepro.di

import com.foleyit.foleytimepro.BuildConfig
import com.foleyit.foleytimepro.data.remote.JsonHttp
import com.foleyit.foleytimepro.data.remote.interceptor.AuthInterceptor
import com.foleyit.foleytimepro.data.remote.interceptor.BareOkHttp
import com.foleyit.foleytimepro.data.remote.interceptor.TokenAuthenticator
import com.foleyit.foleytimepro.data.remote.interceptor.UserAgentInterceptor
import com.foleyit.foleytimepro.security.CertPinProvider
import com.foleyit.foleytimepro.utils.PrefsManager
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import kotlinx.serialization.json.Json
import okhttp3.CertificatePinner
import okhttp3.ConnectionSpec
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import java.util.concurrent.TimeUnit
import javax.inject.Singleton

/**
 * OkHttp + kotlinx.serialization bindings.
 *
 * Two client instances are provided:
 *  - the primary `OkHttpClient` with cert pinning, auth interceptor, and
 *    `TokenAuthenticator` for automatic refresh-on-401,
 *  - a `@BareOkHttp` client with NO interceptors, used by the authenticator
 *    itself to avoid re-entrancy when it hits `/api/v1/auth.php?action=refresh`.
 *
 * The cert pinner is computed lazily from the current instance URL, so
 * subsequent URL changes after Setup take effect on the next cold start
 * (acceptable — pin changes at that layer are rare).
 */
@Module
@InstallIn(SingletonComponent::class)
object NetworkModule {

    @Provides @Singleton
    fun provideJson(): Json = Json {
        ignoreUnknownKeys = true
        explicitNulls = false
        coerceInputValues = true
        isLenient = true
    }

    @Provides @Singleton
    fun provideCertificatePinner(
        prefs: PrefsManager,
        certPinProvider: CertPinProvider,
    ): CertificatePinner {
        val base = prefs.getInstanceUrl().ifBlank { BuildConfig.API_BASE_URL_DEFAULT }
        return certPinProvider.pinnerFor(base)
    }

    @Provides @Singleton @BareOkHttp
    fun provideBareOkHttp(pinner: CertificatePinner): OkHttpClient =
        OkHttpClient.Builder()
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(20, TimeUnit.SECONDS)
            .writeTimeout(20, TimeUnit.SECONDS)
            .certificatePinner(pinner)
            .connectionSpecs(listOf(ConnectionSpec.MODERN_TLS))
            .retryOnConnectionFailure(true)
            .build()

    @Provides @Singleton
    fun provideOkHttp(
        pinner: CertificatePinner,
        authInterceptor: AuthInterceptor,
        userAgentInterceptor: UserAgentInterceptor,
        tokenAuthenticator: TokenAuthenticator,
    ): OkHttpClient {
        val builder = OkHttpClient.Builder()
            .connectTimeout(20, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .certificatePinner(pinner)
            .connectionSpecs(listOf(ConnectionSpec.MODERN_TLS))
            .retryOnConnectionFailure(true)
            .addInterceptor(userAgentInterceptor)
            .addInterceptor(authInterceptor)
            .authenticator(tokenAuthenticator)

        if (BuildConfig.DEBUG) {
            builder.addInterceptor(HttpLoggingInterceptor().apply {
                level = HttpLoggingInterceptor.Level.BASIC
            })
        }
        return builder.build()
    }

    @Provides @Singleton
    fun provideJsonHttp(client: OkHttpClient, json: Json): JsonHttp = JsonHttp(client, json)
}
