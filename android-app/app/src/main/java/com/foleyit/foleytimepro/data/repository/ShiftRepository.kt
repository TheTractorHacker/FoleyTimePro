package com.foleyit.foleytimepro.data.repository

import com.foleyit.foleytimepro.data.local.dao.CachedShiftDao
import com.foleyit.foleytimepro.data.local.entity.CachedShiftEntity
import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.remote.api.ShiftApi
import com.foleyit.foleytimepro.data.remote.dto.ShiftDto
import com.foleyit.foleytimepro.domain.model.Shift
import com.foleyit.foleytimepro.utils.PrefsManager
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import javax.inject.Inject
import javax.inject.Singleton

@Singleton
class ShiftRepository @Inject constructor(
    private val api: ShiftApi,
    private val cache: CachedShiftDao,
    private val prefs: PrefsManager,
) {
    fun observeCached(): Flow<Shift?> =
        cache.observeForUser(prefs.getUserId()).map { it?.toDomain() }

    suspend fun refresh(): ApiResult<Shift?> {
        return when (val res = api.mine()) {
            is ApiResult.Success -> {
                val first = res.data.shifts.firstOrNull()
                val uid = prefs.getUserId()
                if (first == null) {
                    cache.deleteForUser(uid)
                    ApiResult.Success(null)
                } else {
                    cache.upsert(first.toEntity(uid))
                    ApiResult.Success(first.toDomain())
                }
            }
            is ApiResult.HttpError    -> res
            is ApiResult.NetworkError -> res
            is ApiResult.Unauthorized -> res
            is ApiResult.Unknown      -> res
        }
    }

    private fun ShiftDto.toEntity(userId: Int) = CachedShiftEntity(
        userId = userId,
        name = name,
        startTime = startTime,
        endTime = endTime,
        daysCsv = days.joinToString(","),
        lunchDuration = lunchDuration,
        lunchStartTime = lunchStartTime,
    )

    private fun ShiftDto.toDomain() = Shift(
        name = name,
        startTime = startTime,
        endTime = endTime,
        daysOfWeek = days,
        lunchDurationMinutes = lunchDuration,
        lunchStartTime = lunchStartTime,
    )

    private fun CachedShiftEntity.toDomain() = Shift(
        name = name,
        startTime = startTime,
        endTime = endTime,
        daysOfWeek = if (daysCsv.isBlank()) emptyList() else daysCsv.split(","),
        lunchDurationMinutes = lunchDuration,
        lunchStartTime = lunchStartTime,
    )
}
