package com.foleyit.foleytimepro.data.remote.api

import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.remote.BaseUrlProvider
import com.foleyit.foleytimepro.data.remote.JsonHttp
import com.foleyit.foleytimepro.data.remote.dto.ChangePasswordRequest
import kotlinx.serialization.encodeToString
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class UserApi @Inject constructor(
    private val http: JsonHttp,
    private val urls: BaseUrlProvider,
) {
    suspend fun changePassword(newPassword: String): ApiResult<Unit> {
        val body = http.json.encodeToString(ChangePasswordRequest(newPassword))
            .toRequestBody(http.jsonMediaType)
        val req = Request.Builder()
            .url(urls.endpoint("user.php", "action=change_password"))
            .post(body)
            .build()
        return http.executeNoPayload(req)
    }
}
