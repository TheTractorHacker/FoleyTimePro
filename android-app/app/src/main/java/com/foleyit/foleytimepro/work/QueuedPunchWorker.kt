package com.foleyit.foleytimepro.work

import android.content.Context
import androidx.hilt.work.HiltWorker
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.foleyit.foleytimepro.data.local.dao.QueuedPunchDao
import com.foleyit.foleytimepro.data.remote.ApiResult
import com.foleyit.foleytimepro.data.remote.api.PunchApi
import com.foleyit.foleytimepro.data.remote.dto.PunchRequest
import dagger.assisted.Assisted
import dagger.assisted.AssistedInject

/**
 * Drains queued offline punches to the server.
 *
 * Constraints (set at enqueue-time by [PunchRepository]) require network
 * connectivity. Each queued row is sent to the same `punch.php?action=` it
 * would have used live, but with `source=offline_sync` and the original
 * `captured_at` epoch millis so the server can record the real punch time.
 *
 * Retry policy: HTTP errors (4xx/5xx) mark the row failed but leave it in
 * place for manual inspection (the server may reject a duplicate); network
 * errors return `retry()` so WorkManager tries again with exponential backoff.
 */
@HiltWorker
class QueuedPunchWorker @AssistedInject constructor(
    @Assisted appContext: Context,
    @Assisted params: WorkerParameters,
    private val punchApi: PunchApi,
    private val queuedPunchDao: QueuedPunchDao,
) : CoroutineWorker(appContext, params) {

    override suspend fun doWork(): Result {
        val pending = queuedPunchDao.pending()
        if (pending.isEmpty()) return Result.success()

        var sawNetworkFailure = false

        for (entry in pending) {
            val res = punchApi.punch(
                action = entry.action,
                payload = PunchRequest(
                    note = entry.note,
                    latitude = entry.latitude,
                    longitude = entry.longitude,
                    capturedAt = entry.capturedAtEpochMs,
                    source = "offline_sync",
                )
            )
            when (res) {
                is ApiResult.Success      -> queuedPunchDao.deleteById(entry.id)
                is ApiResult.NetworkError -> {
                    sawNetworkFailure = true
                    queuedPunchDao.markFailure(entry.id, "network")
                    // Preserve original order — bail out and retry the whole batch later.
                    break
                }
                is ApiResult.Unauthorized -> {
                    // Auth is dead; do not drain further — user must sign in again.
                    queuedPunchDao.markFailure(entry.id, "unauthorized")
                    return Result.failure()
                }
                is ApiResult.HttpError -> {
                    queuedPunchDao.markFailure(entry.id, "http ${res.code}: ${res.message}")
                    if (entry.attempts >= 5) queuedPunchDao.deleteById(entry.id)
                }
                is ApiResult.Unknown -> {
                    sawNetworkFailure = true
                    queuedPunchDao.markFailure(entry.id, res.throwable.message ?: "unknown")
                    break
                }
            }
        }
        return if (sawNetworkFailure) Result.retry() else Result.success()
    }

    companion object { const val UNIQUE_NAME = "queued-punch-drain" }
}
