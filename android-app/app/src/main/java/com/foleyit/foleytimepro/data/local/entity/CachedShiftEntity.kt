package com.foleyit.foleytimepro.data.local.entity

import androidx.room.Entity
import androidx.room.PrimaryKey

/**
 * A user's assigned shift, cached for offline display. One row per user
 * (we only ever show the current employee's shift in the app).
 */
@Entity(tableName = "cached_shifts")
data class CachedShiftEntity(
    @PrimaryKey val userId: Int,
    val name: String,
    val startTime: String,
    val endTime: String,
    val daysCsv: String,           // Room ships without list converters; store CSV.
    val lunchDuration: Int,
    val lunchStartTime: String,
    val cachedAtEpochMs: Long = System.currentTimeMillis(),
)
