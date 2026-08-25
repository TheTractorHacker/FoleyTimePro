package com.foleyit.foleytimepro.data.remote.api

import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.remote.BaseUrlProvider
import com.foleyit.foleytimepro.data.remote.JsonHttp
import com.foleyit.foleytimepro.data.remote.dto.LoginData
import com.foleyit.foleytimepro.data.remote.dto.LoginRequest
import com.foleyit.foleytimepro.data.remote.dto.RefreshRequest
import com.foleyit.foleytimepro.data.remote.interceptor.Anonymous
import kotlinx.serialization.encodeToString
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class AuthApi @Inject constructor(
    private val http: JsonHttp,
    private val urls: BaseUrlProvider,
) {

    suspend fun login(email: String, password: String, twoFaCode: String? = null): ApiResult<LoginData> {
        val body = http.json.encodeToString(LoginRequest(email, password, twoFaCode))
            .toRequestBody(http.jsonMediaType)
        val req = Request.Builder()
            .url(urls.endpoint("auth.php", "action=login"))
            .post(body)
            .tag(Anonymous)
            .build()
        return http.execute(req, LoginData.serializer())
    }

    suspend fun logout(): ApiResult<Unit> = http.executeNoPayload(
        Request.Builder()
            .url(urls.endpoint("auth.php", "action=logout"))
            .post("".toRequestBody(null))
            .build()
    )

    suspend fun logoutAllDevices(): ApiResult<Unit> = http.executeNoPayload(
        Request.Builder()
            .url(urls.endpoint("auth.php", "action=logout-all"))
            .post("".toRequestBody(null))
            .build()
    )

    /**
     * Manual refresh (used by [com.foleyit.foleytimepro.work.TokenRefreshWorker]).
     * The reactive refresh path lives in the OkHttp `TokenAuthenticator`.
     */
    suspend fun refresh(refreshToken: String): ApiResult<LoginData> {
        val body = http.json.encodeToString(RefreshRequest(refreshToken))
            .toRequestBody(http.jsonMediaType)
        val req = Request.Builder()
            .url(urls.endpoint("auth.php", "action=refresh"))
            .post(body)
            .tag(Anonymous)
            .build()
        return http.execute(req, LoginData.serializer())
    }
}
