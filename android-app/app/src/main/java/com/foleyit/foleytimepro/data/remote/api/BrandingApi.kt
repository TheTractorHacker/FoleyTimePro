package com.foleyit.foleytimepro.data.remote.api

import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.remote.BaseUrlProvider
import com.foleyit.foleytimepro.data.remote.JsonHttp
import com.foleyit.foleytimepro.data.remote.dto.BrandingDto
import com.foleyit.foleytimepro.data.remote.interceptor.Anonymous
import okhttp3.Request
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class BrandingApi @Inject constructor(
    private val http: JsonHttp,
    private val urls: BaseUrlProvider,
) {
    /** Probed during Setup to confirm the URL points at a FoleyTimePro server. */
    suspend fun fetch(): ApiResult<BrandingDto> {
        val req = Request.Builder()
            .url(urls.endpoint("branding.php"))
            .get()
            .tag(Anonymous)
            .build()
        return http.execute(req, BrandingDto.serializer())
    }
}
