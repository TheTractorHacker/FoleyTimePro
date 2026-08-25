package com.foleyit.foleytimepro.data.remote.api

import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.remote.BaseUrlProvider
import com.foleyit.foleytimepro.data.remote.JsonHttp
import com.foleyit.foleytimepro.data.remote.dto.ShiftListDto
import okhttp3.Request
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class ShiftApi @Inject constructor(
    private val http: JsonHttp,
    private val urls: BaseUrlProvider,
) {
    suspend fun mine(): ApiResult<ShiftListDto> {
        val req = Request.Builder()
            .url(urls.endpoint("shifts.php", "action=my"))
            .get()
            .build()
        return http.execute(req, ShiftListDto.serializer())
    }
}
