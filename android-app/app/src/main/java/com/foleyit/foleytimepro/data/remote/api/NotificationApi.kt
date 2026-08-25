package com.foleyit.foleytimepro.data.remote.api

import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.remote.BaseUrlProvider
import com.foleyit.foleytimepro.data.remote.JsonHttp
import com.foleyit.foleytimepro.data.remote.dto.DeviceRegisterRequest
import com.foleyit.foleytimepro.data.remote.dto.DeviceUnregisterRequest
import kotlinx.serialization.encodeToString
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class NotificationApi @Inject constructor(
    private val http: JsonHttp,
    private val urls: BaseUrlProvider,
) {
    suspend fun register(request: DeviceRegisterRequest): ApiResult<Unit> {
        val body = http.json.encodeToString(request).toRequestBody(http.jsonMediaType)
        val req = Request.Builder()
            .url(urls.endpoint("notifications.php", "action=register"))
            .post(body)
            .build()
        return http.executeNoPayload(req)
    }

    suspend fun unregister(token: String): ApiResult<Unit> {
        val body = http.json.encodeToString(DeviceUnregisterRequest(token))
            .toRequestBody(http.jsonMediaType)
        val req = Request.Builder()
            .url(urls.endpoint("notifications.php", "action=unregister"))
            .post(body)
            .build()
        return http.executeNoPayload(req)
    }
}
