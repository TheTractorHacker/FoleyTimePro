package com.foleyit.foleytimepro.data.repository

import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.remote.api.BrandingApi
import com.foleyit.foleytimepro.utils.PrefsManager
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class BrandingRepository @Inject constructor(
    private val brandingApi: BrandingApi,
    private val prefs: PrefsManager,
) {
    /**
     * Probe the server. On success, cache the company name so the Login screen
     * can render branded text before the next branding fetch completes.
     */
    suspend fun probe(): ApiResult<String> {
        return when (val res = brandingApi.fetch()) {
            is ApiResult.Success -> {
                val name = res.data.companyName.ifBlank { "FoleyTimePro" }
                prefs.saveCompanyName(name)
                ApiResult.Success(name)
            }
            is ApiResult.HttpError    -> res
            is ApiResult.NetworkError -> res
            is ApiResult.Unauthorized -> res
            is ApiResult.Unknown      -> res
        }
    }

    fun cachedCompanyName(): String = prefs.getCompanyName()
}
