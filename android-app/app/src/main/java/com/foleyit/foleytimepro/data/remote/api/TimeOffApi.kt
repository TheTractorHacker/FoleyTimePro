package com.foleyit.foleytimepro.data.remote.api

import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.remote.BaseUrlProvider
import com.foleyit.foleytimepro.data.remote.JsonHttp
import com.foleyit.foleytimepro.data.remote.dto.TimeOffListDto
import com.foleyit.foleytimepro.data.remote.dto.TimeOffSubmitRequest
import kotlinx.serialization.encodeToString
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class TimeOffApi @Inject constructor(
    private val http: JsonHttp,
    private val urls: BaseUrlProvider,
) {

    suspend fun list(): ApiResult<TimeOffListDto> {
        val req = Request.Builder()
            .url(urls.endpoint("timeoff.php", "action=timeoff"))
            .get()
            .build()
        return http.execute(req, TimeOffListDto.serializer())
    }

    suspend fun submit(request: TimeOffSubmitRequest): ApiResult<Unit> {
        val body = http.json.encodeToString(request).toRequestBody(http.jsonMediaType)
        val req = Request.Builder()
            .url(urls.endpoint("timeoff.php", "action=request"))
            .post(body)
            .build()
        return http.executeNoPayload(req)
    }
}
