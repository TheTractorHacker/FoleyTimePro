package com.foleyit.foleytimepro.data.repository

import android.content.Context
import androidx.work.Constraints
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import com.foleyit.foleytimepro.data.local.dao.CachedPunchDao
import com.foleyit.foleytimepro.data.local.dao.QueuedPunchDao
import com.foleyit.foleytimepro.data.local.entity.CachedPunchEntity
import com.foleyit.foleytimepro.data.local.entity.QueuedPunchEntity
import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.remote.api.PunchApi
import com.foleyit.foleytimepro.data.remote.dto.PunchEntryDto
import com.foleyit.foleytimepro.data.remote.dto.PunchRequest
import com.foleyit.foleytimepro.domain.model.PunchEntry
import com.foleyit.foleytimepro.domain.model.PunchHistory
import com.foleyit.foleytimepro.domain.model.PunchStatus
import com.foleyit.foleytimepro.utils.PrefsManager
import com.foleyit.foleytimepro.work.QueuedPunchWorker
import dagger.hilt.android.qualifiers.ApplicationContext
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.map
import javax.inject.Inject
import javax.inject.Singleton

/**
 * Punch surface: live status/history from the API with a Room fallback for
 * offline reads, plus an offline queue for punches issued while disconnected.
 *
 * `punchOrQueue` is the workhorse: it tries the network first, and on any
 * network-layer failure (or if the caller explicitly forces queuing), it
 * inserts a [QueuedPunchEntity] and schedules the [QueuedPunchWorker] to
 * drain once connectivity returns. Callers get an immediate `Queued` result
 * so the UI can render "Pending sync" without spinning on the network.
 */
@Singleton
class PunchRepository @Inject constructor(
    private val punchApi: PunchApi,
    private val queuedPunchDao: QueuedPunchDao,
    private val cachedPunchDao: CachedPunchDao,
    private val prefs: PrefsManager,
    @ApplicationContext private val appContext: Context,
) {

    sealed interface PunchOutcome {
        data class Committed(val timeText: String, val hoursWorked: String) : PunchOutcome
        data object Queued : PunchOutcome
        data class Failed(val message: String) : PunchOutcome
    }

    suspend fun status(): ApiResult<PunchStatus> {
        return when (val res = punchApi.status()) {
            is ApiResult.Success -> ApiResult.Success(
                PunchStatus(
                    name           = res.data.name,
                    status         = res.data.status,
                    clockInTime    = res.data.clockInTime,
                    lunchStartTime = res.data.lunchStartTime,
                    elapsedSeconds = res.data.elapsedSeconds,
                )
            )
            is ApiResult.HttpError    -> res
            is ApiResult.NetworkError -> res
            is ApiResult.Unauthorized -> res
            is ApiResult.Unknown      -> res
        }
    }

    /**
     * Live-path punch. Queues on network failure. If the server returns an
     * HTTP error (401/4xx/5xx), we do NOT queue — those indicate the client
     * or session is at fault, not connectivity.
     */
    suspend fun punchOrQueue(
        action: String,
        note: String,
        latitude: Double?,
        longitude: Double?,
    ): PunchOutcome {
        val capturedAtMs = System.currentTimeMillis()
        val userId = prefs.getUserId()

        val res = punchApi.punch(
            action = action,
            payload = PunchRequest(
                note = note,
                latitude = latitude,
                longitude = longitude,
                capturedAt = capturedAtMs,
                source = "live",
            )
        )
        return when (res) {
            is ApiResult.Success -> PunchOutcome.Committed(res.data.time, res.data.hoursWorked)
            is ApiResult.NetworkError -> {
                enqueue(action, note, latitude, longitude, capturedAtMs, userId)
                PunchOutcome.Queued
            }
            is ApiResult.HttpError    -> PunchOutcome.Failed(res.message)
            is ApiResult.Unauthorized -> PunchOutcome.Failed("Session expired.")
            is ApiResult.Unknown      -> PunchOutcome.Failed(res.throwable.message ?: "Unexpected error")
        }
    }

    private suspend fun enqueue(
        action: String,
        note: String,
        lat: Double?,
        lon: Double?,
        capturedAtMs: Long,
        userId: Int,
    ) {
        queuedPunchDao.insert(
            QueuedPunchEntity(
                action = action,
                note = note,
                latitude = lat,
                longitude = lon,
                capturedAtEpochMs = capturedAtMs,
                userId = userId,
            )
        )
        val req = OneTimeWorkRequestBuilder<QueuedPunchWorker>()
            .setConstraints(
                Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.CONNECTED)
                    .build()
            )
            .build()
        WorkManager.getInstance(appContext).enqueueUniqueWork(
            QueuedPunchWorker.UNIQUE_NAME,
            ExistingWorkPolicy.KEEP,
            req,
        )
    }

    /** Live count of pending offline punches for the current user (badge in ClockScreen). */
    fun queuedCountForCurrentUser(): Flow<Int> = queuedPunchDao.countForUser(prefs.getUserId())

    /**
     * Cache-through history: emits Room contents immediately, refreshes from
     * the network in [refresh], writes fresh rows back into Room. UI receives
     * a Flow that updates automatically after refresh.
     */
    fun observeCachedHistory(): Flow<PunchHistory> =
        cachedPunchDao.observeForUser(prefs.getUserId()).map { rows ->
            val totalHours = rows.mapNotNull { it.hours?.toFloatOrNull() }.sum()
            PunchHistory(
                entries = rows.map { it.toDomain() },
                totalHours = "%.2f".format(totalHours),
            )
        }

    suspend fun refreshHistory(startDate: String? = null, endDate: String? = null): ApiResult<PunchHistory> {
        return when (val res = punchApi.history(startDate, endDate)) {
            is ApiResult.Success -> {
                val uid = prefs.getUserId()
                cachedPunchDao.deleteForUser(uid)
                cachedPunchDao.upsertAll(res.data.punches.map { it.toEntity(uid) })
                ApiResult.Success(
                    PunchHistory(
                        entries = res.data.punches.map { it.toDomain() },
                        totalHours = res.data.totalHours,
                    )
                )
            }
            is ApiResult.HttpError    -> res
            is ApiResult.NetworkError -> res
            is ApiResult.Unauthorized -> res
            is ApiResult.Unknown      -> res
        }
    }

    private fun PunchEntryDto.toEntity(userId: Int) = CachedPunchEntity(
        userId = userId,
        date = date,
        inTime = inTime,
        lunchStart = lunchStart,
        lunchEnd = lunchEnd,
        outTime = outTime,
        hours = hours,
        note = note,
        source = source,
    )

    private fun CachedPunchEntity.toDomain() = PunchEntry(
        date = date,
        inTime = inTime,
        lunchStart = lunchStart,
        lunchEnd = lunchEnd,
        outTime = outTime,
        hours = hours,
        note = note,
        source = source,
    )

    private fun PunchEntryDto.toDomain() = PunchEntry(
        date = date,
        inTime = inTime,
        lunchStart = lunchStart,
        lunchEnd = lunchEnd,
        outTime = outTime,
        hours = hours,
        note = note,
        source = source,
    )
}
