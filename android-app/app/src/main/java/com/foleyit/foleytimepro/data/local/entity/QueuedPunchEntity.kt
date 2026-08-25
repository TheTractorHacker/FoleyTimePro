package com.foleyit.foleytimepro.data.local.entity

import androidx.room.Entity
import androidx.room.PrimaryKey

/**
 * A punch captured while the device was offline (or the server 500'd).
 *
 * The [QueuedPunchWorker] drains this table when connectivity returns. Each
 * row carries the epoch millis of the ORIGINAL capture so the server can
 * record the real punch time rather than the drain time.
 */
@Entity(tableName = "queued_punches")
data class QueuedPunchEntity(
    @PrimaryKey(autoGenerate = true) val id: Long = 0,
    val action: String,                    // clockin | lunchstart | lunchend | clockout
    val note: String,
    val latitude: Double?,
    val longitude: Double?,
    val capturedAtEpochMs: Long,           // when the user tapped punch on the device
    val userId: Int,                       // owner of the queued punch
    val attempts: Int = 0,
    val lastError: String? = null,
    val enqueuedAtEpochMs: Long = System.currentTimeMillis(),
)
