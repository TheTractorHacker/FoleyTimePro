package com.foleyit.foleytimepro.data.remote.api

import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.remote.BaseUrlProvider
import com.foleyit.foleytimepro.data.remote.JsonHttp
import com.foleyit.foleytimepro.data.remote.dto.PunchHistoryDto
import com.foleyit.foleytimepro.data.remote.dto.PunchRequest
import com.foleyit.foleytimepro.data.remote.dto.PunchResultDto
import com.foleyit.foleytimepro.data.remote.dto.PunchStatusDto
import kotlinx.serialization.encodeToString
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class PunchApi @Inject constructor(
    private val http: JsonHttp,
    private val urls: BaseUrlProvider,
) {

    suspend fun status(): ApiResult<PunchStatusDto> {
        val req = Request.Builder()
            .url(urls.endpoint("punch.php", "action=status"))
            .get()
            .build()
        return http.execute(req, PunchStatusDto.serializer())
    }

    /** action = "clockin" | "lunchstart" | "lunchend" | "clockout". */
    suspend fun punch(action: String, payload: PunchRequest): ApiResult<PunchResultDto> {
        val body = http.json.encodeToString(payload).toRequestBody(http.jsonMediaType)
        val req = Request.Builder()
            .url(urls.endpoint("punch.php", "action=$action"))
            .post(body)
            .build()
        return http.execute(req, PunchResultDto.serializer())
    }

    suspend fun history(startDate: String? = null, endDate: String? = null): ApiResult<PunchHistoryDto> {
        val query = buildString {
            append("action=history")
            if (!startDate.isNullOrBlank()) append("&start=$startDate")
            if (!endDate.isNullOrBlank())   append("&end=$endDate")
        }
        val req = Request.Builder()
            .url(urls.endpoint("punch.php", query))
            .get()
            .build()
        return http.execute(req, PunchHistoryDto.serializer())
    }
}
