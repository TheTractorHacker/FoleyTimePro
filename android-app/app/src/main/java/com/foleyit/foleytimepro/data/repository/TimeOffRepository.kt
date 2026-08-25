package com.foleyit.foleytimepro.data.repository

import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.remote.api.TimeOffApi
import com.foleyit.foleytimepro.data.remote.dto.TimeOffSubmitRequest
import com.foleyit.foleytimepro.domain.model.TimeOffItem
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class TimeOffRepository @Inject constructor(
    private val api: TimeOffApi,
) {

    suspend fun list(): ApiResult<List<TimeOffItem>> {
        return when (val res = api.list()) {
            is ApiResult.Success -> ApiResult.Success(
                res.data.requests.map {
                    TimeOffItem(
                        id = it.id,
                        startDate = it.startDate,
                        endDate = it.endDate,
                        reason = it.reason,
                        status = it.status,
                        type = it.type,
                        createdAt = it.createdAt,
                    )
                }
            )
            is ApiResult.HttpError    -> res
            is ApiResult.NetworkError -> res
            is ApiResult.Unauthorized -> res
            is ApiResult.Unknown      -> res
        }
    }

    suspend fun submit(
        startDate: String,
        endDate: String,
        reason: String,
        type: String,
    ): ApiResult<Unit> = api.submit(TimeOffSubmitRequest(startDate, endDate, reason, type))
}
